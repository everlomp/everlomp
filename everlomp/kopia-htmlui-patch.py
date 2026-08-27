#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path

BASE_EXPR = "import.meta.env.BASE_URL"
DEFAULT_BASE = "/kopia/"


def die(msg):
    print(f"[everlomp-htmlui] ERROR: {msg}", file=sys.stderr)
    raise SystemExit(1)


def app_files(root):
    src = root / "src"
    if not src.is_dir():
        die(f"missing source directory: {src}")

    seen = set()
    for glob in ("*.js", "*.jsx", "*.ts", "*.tsx", "*.mjs"):
        for p in src.rglob(glob):
            if p not in seen:
                seen.add(p)
                yield p


def patch_index(root):
    p = root / "index.html"
    if not p.is_file():
        die(f"missing {p}")

    s = p.read_text()
    old = s

    if re.search(r"<base\b", s, re.I):
        s = re.sub(
            r"<base\b[^>]*href=[\"'][^\"']*[\"'][^>]*>",
            '<base href="/kopia/" />',
            s,
            count=1,
            flags=re.I,
        )
    else:
        s = re.sub(
            r"<head\b([^>]*)>",
            r'<head\1>\n    <base href="/kopia/" />',
            s,
            count=1,
            flags=re.I,
        )

    if '<base href="/kopia/"' not in s:
        die('could not install <base href="/kopia/"> in index.html')

    p.write_text(s)
    return int(s != old)


def last_import_end(s):
    matches = list(
        re.finditer(
            r"(?ms)^import\s+.*?;\s*(?=^import\s|\n(?!import\s)|\Z)",
            s,
        )
    )
    if matches:
        return matches[-1].end()

    matches = list(re.finditer(r"(?m)^import\s+.*$", s))
    return matches[-1].end() if matches else None


def patch_axios(root):
    imp = re.compile(
        r"(?m)^import\s+(?P<name>[A-Za-z_$][\w$]*)\s+from\s+[\"']axios[\"']\s*;?"
    )

    touched = 0
    found = 0

    for p in app_files(root):
        s = p.read_text()
        imports = list(imp.finditer(s))
        if not imports:
            continue

        found += len(imports)
        additions = []

        for m in imports:
            name = m.group("name")
            line = f"{name}.defaults.baseURL = {BASE_EXPR};"
            if line not in s:
                additions.append(line)

        if not additions:
            continue

        pos = last_import_end(s)
        if pos is None:
            die(f"could not identify import block in {p}")

        s = (
            s[:pos]
            + "\n// Everlomp: keep Kopia REST calls below Vite BASE_URL.\n"
            + "\n".join(additions)
            + "\n"
            + s[pos:]
        )

        p.write_text(s)
        touched += 1

    if not found:
        die("could not find a default axios import in Kopia HTMLUI")

    return touched


def patch_dom_attrs(root):
    """Patch literal browser href/src values only.

    React Router's `to=` properties are deliberately left alone. Router
    navigation must be handled by the one basename patch applied to the final
    Vite bundle.
    """

    total = 0

    for p in app_files(root):
        if p.suffix not in (".jsx", ".tsx"):
            continue

        s = p.read_text()
        old = s

        for attr in ("href", "src"):
            pat = re.compile(rf'{attr}="(/[^"\n]*)"')

            def repl_static_dq(m, attr=attr):
                nonlocal total
                v = m.group(1)
                if v.startswith("//"):
                    return m.group(0)
                total += 1
                return (
                    f'{attr}={{'
                    + BASE_EXPR
                    + ' + "'
                    + v.lstrip("/")
                    + '"}'
                )

            s = pat.sub(repl_static_dq, s)

            pat = re.compile(rf"{attr}='(/[^'\n]*)'")

            def repl_static_sq(m, attr=attr):
                nonlocal total
                v = m.group(1)
                if v.startswith("//"):
                    return m.group(0)
                total += 1
                return (
                    f"{attr}={{"
                    + BASE_EXPR
                    + " + '"
                    + v.lstrip("/")
                    + "'}"
                )

            s = pat.sub(repl_static_sq, s)

            pat = re.compile(rf'{attr}=\{{\s*"(/[^"\n]*)"')

            def repl_expr_dq(m, attr=attr):
                nonlocal total
                v = m.group(1)
                if v.startswith("//"):
                    return m.group(0)
                total += 1
                return (
                    f'{attr}={{'
                    + BASE_EXPR
                    + ' + "'
                    + v.lstrip("/")
                    + '"'
                )

            s = pat.sub(repl_expr_dq, s)

            pat = re.compile(rf"{attr}=\{{\s*'(/[^'\n]*)'")

            def repl_expr_sq(m, attr=attr):
                nonlocal total
                v = m.group(1)
                if v.startswith("//"):
                    return m.group(0)
                total += 1
                return (
                    f"{attr}={{"
                    + BASE_EXPR
                    + " + '"
                    + v.lstrip("/")
                    + "'"
                )

            s = pat.sub(repl_expr_sq, s)

            pat = re.compile(rf"{attr}=\{{`(/[^`\n]*)")

            def repl_template(m, attr=attr):
                nonlocal total
                v = m.group(1)
                if v.startswith("//"):
                    return m.group(0)
                total += 1
                return (
                    f"{attr}={{`${{"
                    + BASE_EXPR
                    + "}"
                    + v.lstrip("/")
                )

            s = pat.sub(repl_template, s)

        if s != old:
            p.write_text(s)

    return total


def patch_browser_navigation(root):
    """Keep full-page browser navigation below Vite BASE_URL.

    React Router links are handled by the Router basename patch, but Kopia
    also uses window.location.replace() after repository connect/disconnect.
    Those calls bypass React Router completely, so a root-relative target such
    as "/repo" would escape the public /kopia/ mount point.
    """

    total = 0

    for p in app_files(root):
        s = p.read_text()
        old = s

        pat = re.compile(
            r"(window\.location\.(?:replace|assign)\(\s*)([\"'])(/(?!/)[^\"'\n]*)(\2)(\s*\))"
        )

        def repl_call(m):
            nonlocal total
            total += 1
            q = m.group(2)
            target = m.group(3).lstrip("/")
            return m.group(1) + BASE_EXPR + " + " + q + target + q + m.group(5)

        s = pat.sub(repl_call, s)

        pat = re.compile(
            r"(window\.location\.href\s*=\s*)([\"'])(/(?!/)[^\"'\n]*)(\2)"
        )

        def repl_href(m):
            nonlocal total
            total += 1
            q = m.group(2)
            target = m.group(3).lstrip("/")
            return m.group(1) + BASE_EXPR + " + " + q + target + q

        s = pat.sub(repl_href, s)

        if s != old:
            p.write_text(s)

    return total


def verify_app(root):
    joined = "\n".join(p.read_text() for p in app_files(root))

    if ".defaults.baseURL = import.meta.env.BASE_URL;" not in joined:
        die("Axios BASE_URL patch did not verify")

    if '<base href="/kopia/"' not in (root / "index.html").read_text():
        die("HTML base patch did not verify")

    for pat in (
        r"window\.location\.(?:replace|assign)\(\s*[\"']/(?!/)",
        r"window\.location\.href\s*=\s*[\"']/(?!/)",
    ):
        if re.search(pat, joined):
            die("root-relative window.location navigation survived app patch")

    for pat in (
        r'href\s*=\s*"/snapshots/dir/',
        r"href\s*=\s*'/snapshots/dir/",
        r'href\s*=\s*\{\s*"/snapshots/dir/',
        r"href\s*=\s*\{\s*'/snapshots/dir/",
        r"href\s*=\s*\{\s*`/snapshots/dir/",
    ):
        if re.search(pat, joined):
            die("root-relative /snapshots/dir/ href survived app patch")


def install_replication_ui(root):
    """Install Everlomp's repository-replication page into Kopia HTMLUI.

    The page is part of the Kopia UI after compilation, so ongoing replica
    management does not depend on Everlomp's one-time installation page.
    """

    template_candidates = [
        Path("/usr/local/share/everlomp/kopia-replication-page.jsx"),
        Path(__file__).resolve().with_name("kopia-replication-page.jsx"),
    ]
    template = next((x for x in template_candidates if x.is_file()), None)
    if template is None:
        die("Everlomp Replication.jsx template is missing")

    pages = root / "src" / "pages"
    pages.mkdir(parents=True, exist_ok=True)
    target = pages / "Replication.jsx"
    target.write_text(template.read_text())

    app = root / "src" / "App.jsx"
    if not app.is_file():
        die(f"missing {app}")

    text = app.read_text()

    if 'import { Replication } from "./pages/Replication";' not in text:
        marker = 'import { Repository } from "./pages/Repository";'
        if marker not in text:
            die("could not find Repository import in src/App.jsx")
        text = text.replace(
            marker,
            marker + '\nimport { Replication } from "./pages/Replication";',
            1,
        )

    if 'data-testid="tab-replication"' not in text:
        marker = (
            '                  <NavLink\n'
            '                    data-testid="tab-preferences"'
        )
        if marker not in text:
            die("could not find Preferences navigation entry in src/App.jsx")
        nav = (
            '                  <NavLink\n'
            '                    data-testid="tab-replication"\n'
            '                    data-title="Replication"\n'
            '                    className="nav-link"\n'
            '                    to="/replication"\n'
            '                  >\n'
            '                    Replication\n'
            '                  </NavLink>\n'
        )
        text = text.replace(marker, nav + marker, 1)

    if '<Route path="replication" element={<Replication />} />' not in text:
        marker = '                <Route path="preferences" element={<Preferences />} />'
        if marker not in text:
            die("could not find Preferences route in src/App.jsx")
        text = text.replace(
            marker,
            '                <Route path="replication" element={<Replication />} />\n' + marker,
            1,
        )

    app.write_text(text)

    verify = app.read_text()
    required = (
        'import { Replication } from "./pages/Replication";',
        'data-testid="tab-replication"',
        'to="/replication"',
        '<Route path="replication" element={<Replication />} />',
    )
    if not all(x in verify for x in required):
        die("Replication tab injection did not verify")

    print("[everlomp-htmlui] Replication tab/page installed")


def install_repository_security_ui(root):
    """Install Everlomp repository-password controls into Kopia's native Repository page."""

    template_candidates = [
        Path("/usr/local/share/everlomp/kopia-repository-security.jsx"),
        Path(__file__).resolve().with_name("kopia-repository-security.jsx"),
    ]
    template = next((x for x in template_candidates if x.is_file()), None)
    if template is None:
        die("Everlomp repository-security component template is missing")

    components = root / "src" / "components"
    components.mkdir(parents=True, exist_ok=True)
    component_target = components / "EverlompRepositorySecurity.jsx"
    component_target.write_text(template.read_text())

    repository = root / "src" / "pages" / "Repository.jsx"
    if not repository.is_file():
        die(f"missing {repository}")

    text = repository.read_text()

    import_line = 'import { EverlompRepositorySecurity } from "../components/EverlompRepositorySecurity";'
    if import_line not in text:
        marker = 'import { AppContext } from "../contexts/AppContext";'
        if marker not in text:
            die("could not find AppContext import in src/pages/Repository.jsx")
        text = text.replace(marker, marker + "\n" + import_line, 1)

    render_line = "          <EverlompRepositorySecurity />"
    if render_line not in text:
        marker = (
            "          </Form>\n"
            "          <Row>\n"
            "            <Col>&nbsp;</Col>\n"
            "          </Row>\n"
        )
        if marker not in text:
            die("could not find connected Repository form footer in src/pages/Repository.jsx")
        replacement = (
            "          </Form>\n"
            "          <EverlompRepositorySecurity />\n"
            "          <Row>\n"
            "            <Col>&nbsp;</Col>\n"
            "          </Row>\n"
        )
        text = text.replace(marker, replacement, 1)

    repository.write_text(text)

    verify = repository.read_text()
    if import_line not in verify or render_line not in verify:
        die("Repository password UI injection did not verify")
    if not component_target.is_file():
        die("Repository password UI component was not installed")

    print("[everlomp-htmlui] Repository password UI installed")



def install_webui_preferences_ui(root):
    """Add Everlomp's Kopia WebUI-password control to the Preferences route.

    Keep Kopia's native Preferences component untouched. App.jsx is pointed at
    a tiny wrapper page which renders native Preferences first and then the
    Everlomp security card. This is less brittle across HTMLUI releases than
    rewriting the internal layout of Preferences.jsx.
    """

    template_candidates = [
        Path("/usr/local/share/everlomp/kopia-webui-security.jsx"),
        Path(__file__).resolve().with_name("kopia-webui-security.jsx"),
    ]
    template = next((x for x in template_candidates if x.is_file()), None)
    if template is None:
        die("Everlomp WebUI-security component template is missing")

    components = root / "src" / "components"
    components.mkdir(parents=True, exist_ok=True)
    component_target = components / "EverlompWebUISecurity.jsx"
    component_target.write_text(template.read_text())

    native_preferences = root / "src" / "pages" / "Preferences.jsx"
    if not native_preferences.is_file():
        die(f"missing {native_preferences}")

    wrapper = root / "src" / "pages" / "EverlompPreferences.jsx"
    wrapper.write_text(
        'import React from "react";\n'
        'import { EverlompWebUISecurity } from "../components/EverlompWebUISecurity";\n'
        'import { Preferences } from "./Preferences";\n\n'
        'export function EverlompPreferences() {\n'
        '  return (\n'
        '    <>\n'
        '      <Preferences />\n'
        '      <EverlompWebUISecurity />\n'
        '    </>\n'
        '  );\n'
        '}\n'
    )

    app = root / "src" / "App.jsx"
    if not app.is_file():
        die(f"missing {app}")
    text = app.read_text()

    import_line = 'import { EverlompPreferences } from "./pages/EverlompPreferences";'
    pref_import_pattern = re.compile(
        r'(?m)^import\s+\{\s*Preferences\s*\}\s+from\s+["\']\./pages/Preferences["\']\s*;?\n?'
    )

    # App.jsx no longer renders Preferences directly once the route points at
    # EverlompPreferences. Replace the native import instead of adding a second
    # import, otherwise Kopia's ESLint build rejects the now-unused Preferences
    # symbol. Also clean it up if this patch is re-run on a partially patched tree.
    if import_line not in text:
        text, count = pref_import_pattern.subn(import_line + "\n", text, count=1)
        if count != 1:
            die("could not find Preferences import in src/App.jsx")
    else:
        text = pref_import_pattern.sub("", text, count=1)

    native_route = '<Route path="preferences" element={<Preferences />} />'
    wrapped_route = '<Route path="preferences" element={<EverlompPreferences />} />'
    if wrapped_route not in text:
        if native_route not in text:
            die("could not find Preferences route in src/App.jsx")
        text = text.replace(native_route, wrapped_route, 1)

    app.write_text(text)

    verify = app.read_text()
    if import_line not in verify or wrapped_route not in verify:
        die("WebUI password Preferences injection did not verify")
    if not component_target.is_file() or not wrapper.is_file():
        die("WebUI password Preferences files were not installed")

    print("[everlomp-htmlui] WebUI password control installed in Preferences")

def patch_setup_repository_password(root):
    """Persist repository passwords entered in Kopia's native Repository UI.

    Kopia server mode needs KOPIA_PASSWORD again after a container restart.
    Everlomp's one-time installer is gone by then, so successful native
    create/connect operations also hand the encryption password to the local
    replication/service helper. Repository-server and token connections are
    excluded because their password semantics differ.
    """

    p = root / "src" / "components" / "SetupRepository.jsx"
    if not p.is_file():
        die(f"missing {p}")
    text = p.read_text()

    create_old = (
        '      .then((_result) => {\n'
        '        this.context.repositoryUpdated(true);\n'
        '      })'
    )
    create_new = (
        '      .then(async (_result) => {\n'
        '        if (this.state.provider !== "_token" && this.state.provider !== "_server" && this.state.password) {\n'
        '          try {\n'
        '            await axios.post("everlomp-api/source-password", { password: this.state.password });\n'
        '          } catch (error) {\n'
        '            console.warn("Everlomp could not persist the Kopia repository password", error);\n'
        '          }\n'
        '        }\n'
        '        this.context.repositoryUpdated(true);\n'
        '      })'
    )
    if 'Everlomp could not persist the Kopia repository password' not in text:
        if create_old not in text:
            die("could not find native repository-create success handler")
        text = text.replace(create_old, create_new, 1)

        connect_old = (
            '      .then((_result) => {\n'
            '        this.setState({ isLoading: false });\n'
            '        this.context.repositoryUpdated(true);\n'
            '      })'
        )
        connect_new = (
            '      .then(async (_result) => {\n'
            '        this.setState({ isLoading: false });\n'
            '        if (this.state.provider !== "_token" && this.state.provider !== "_server" && this.state.password) {\n'
            '          try {\n'
            '            await axios.post("everlomp-api/source-password", { password: this.state.password });\n'
            '          } catch (error) {\n'
            '            console.warn("Everlomp could not persist the Kopia repository password", error);\n'
            '          }\n'
            '        }\n'
            '        this.context.repositoryUpdated(true);\n'
            '      })'
        )
        if connect_old not in text:
            die("could not find native repository-connect success handler")
        text = text.replace(connect_old, connect_new, 1)

    p.write_text(text)
    verify = p.read_text()
    if verify.count('axios.post("everlomp-api/source-password"') != 2:
        die("repository-password persistence patch did not verify")
    print("[everlomp-htmlui] native Repository password persistence installed")

def patch_app(root):
    install_replication_ui(root)
    install_repository_security_ui(root)
    install_webui_preferences_ui(root)
    patch_setup_repository_password(root)
    index_count = patch_index(root)
    axios_count = patch_axios(root)
    dom_count = patch_dom_attrs(root)
    navigation_count = patch_browser_navigation(root)
    verify_app(root)

    print(
        "[everlomp-htmlui] app source patched: "
        f"index={index_count}, "
        f"axios_files={axios_count}, "
        f"dom_attrs={dom_count}, "
        f"browser_navigation={navigation_count}"
    )


ROUTER_MARKER = "You cannot render a <Router> inside another <Router>"


def built_js_files(root):
    for p in root.rglob("*.js"):
        if p.is_file():
            yield p


def patch_router_signature(text, base):
    """Patch ONLY the low-level React Router component's basename default.

    The working Kopia proxy experiment established that the specific Router
    signature:

        {basename:e="/",children:t=null,...}

    needs:

        {basename:e="/kopia/",children:t=null,...}

    The v17 node_modules patch was too broad because it changed every
    basename="/" default in React Router internals.

    Here we only match basename immediately followed by the Router component's
    `children` destructured property. Other React Router basename defaults are
    deliberately left untouched.
    """

    replacements = 0

    alias_pattern = re.compile(
        r"(\bbasename\s*:\s*[A-Za-z_$][\w$]*\s*=\s*)"
        r"([\"'])/([\"'])"
        r"(\s*,\s*children\s*:\s*[A-Za-z_$][\w$]*)"
    )

    def alias_repl(m):
        nonlocal replacements

        if m.group(2) != m.group(3):
            return m.group(0)

        replacements += 1
        q = m.group(2)

        return (
            m.group(1)
            + q
            + base
            + q
            + m.group(4)
        )

    text = alias_pattern.sub(alias_repl, text)

    readable_pattern = re.compile(
        r"(\bbasename\s*=\s*)"
        r"([\"'])/([\"'])"
        r"(\s*,\s*children\b)"
    )

    def readable_repl(m):
        nonlocal replacements

        if m.group(2) != m.group(3):
            return m.group(0)

        replacements += 1
        q = m.group(2)

        return (
            m.group(1)
            + q
            + base
            + q
            + m.group(4)
        )

    text = readable_pattern.sub(readable_repl, text)

    return text, replacements


def print_router_diagnostics(files):
    print(
        "[everlomp-htmlui] Router signature was not found. "
        "Relevant snippets:",
        file=sys.stderr,
    )

    shown = 0

    for p, s in files:
        for needle in ("basename", "children"):
            pos = s.find(needle)
            if pos < 0:
                continue

            start = max(0, pos - 180)
            end = min(len(s), pos + 420)
            snippet = s[start:end].replace("\n", " ")

            print(
                f"[everlomp-htmlui] {p}: {snippet}",
                file=sys.stderr,
            )

            shown += 1
            if shown >= 8:
                return


def patch_bundle_router(root, base):
    if not root.is_dir():
        die(f"built HTMLUI directory not found: {root}")

    if not (base.startswith("/") and base.endswith("/")):
        die(f"base must start and end with '/': {base}")

    candidates = []

    for p in built_js_files(root):
        try:
            s = p.read_text()
        except UnicodeDecodeError:
            continue

        if ROUTER_MARKER in s:
            candidates.append((p, s))

    if not candidates:
        die(
            "could not find the React Router implementation in the "
            "finished Vite JavaScript"
        )

    changed = []
    total = 0

    for p, s in candidates:
        new_text, count = patch_router_signature(s, base)

        if count:
            p.write_text(new_text)
            changed.append((p, count))
            total += count

    if total == 0:
        print_router_diagnostics(candidates)
        die(
            "found React Router, but the low-level "
            "basename/children signature changed"
        )

    if total > 2:
        die(
            f"unexpectedly found {total} low-level Router basename "
            "signatures; refusing to guess"
        )

    for p, _ in candidates:
        s = p.read_text()

        old_alias = re.compile(
            r"\bbasename\s*:\s*[A-Za-z_$][\w$]*\s*=\s*"
            r"([\"'])/\1"
            r"\s*,\s*children\s*:"
        )

        old_readable = re.compile(
            r"\bbasename\s*=\s*"
            r"([\"'])/\1"
            r"\s*,\s*children\b"
        )

        if old_alias.search(s) or old_readable.search(s):
            die(
                f"old low-level Router basename survived in {p}"
            )

    # Verify the new exact signature exists.
    new_base = re.escape(base)

    new_alias = re.compile(
        r"\bbasename\s*:\s*[A-Za-z_$][\w$]*\s*=\s*"
        r"([\"'])"
        + new_base
        + r"\1"
        + r"\s*,\s*children\s*:"
    )

    new_readable = re.compile(
        r"\bbasename\s*=\s*"
        r"([\"'])"
        + new_base
        + r"\1"
        + r"\s*,\s*children\b"
    )

    if not any(
        new_alias.search(p.read_text())
        or new_readable.search(p.read_text())
        for p, _ in changed
    ):
        die("new low-level Router basename did not verify")

    print(
        "[everlomp-htmlui] finished-bundle Router basename patched: "
        f"base={base}, replacements={total}, files={len(changed)}"
    )

    for p, count in changed:
        try:
            rel = p.relative_to(root)
        except ValueError:
            rel = p

        print(
            f"[everlomp-htmlui]   patched {rel} "
            f"({count} exact Router signature replacement(s))"
        )


def main():
    if len(sys.argv) < 3:
        die(
            "usage: kopia-htmlui-patch.py "
            "app <htmlui-source> | "
            "bundle-router <built-htmlui> [base]"
        )

    mode = sys.argv[1]

    if mode == "app" and len(sys.argv) == 3:
        patch_app(Path(sys.argv[2]).resolve())
        return

    if mode == "bundle-router" and len(sys.argv) in (3, 4):
        base = sys.argv[3] if len(sys.argv) == 4 else DEFAULT_BASE
        patch_bundle_router(Path(sys.argv[2]).resolve(), base)
        return

    die(
        "usage: kopia-htmlui-patch.py "
        "app <htmlui-source> | "
        "bundle-router <built-htmlui> [base]"
    )


if __name__ == "__main__":
    main()
