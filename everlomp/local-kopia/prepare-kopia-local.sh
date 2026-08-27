#!/bin/bash
set -euo pipefail

VERSION="${1:-0.23.1}"
VERSION="${VERSION#v}"
OUT_DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$OUT_DIR/kopia_local.tar.gz"
WORK="$(mktemp -d /tmp/everlomp-kopia-bundle.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

for cmd in git curl tar python3; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "Missing required command: $cmd" >&2
        exit 1
    }
done

echo "Preparing local Kopia source bundle for v${VERSION}..."

git clone --quiet --depth 1 --branch "v${VERSION}" \
    https://github.com/kopia/kopia.git \
    "$WORK/kopia"

BUILD_VERSION="$(awk '$1 == "github.com/kopia/htmluibuild" {print $2; exit}' "$WORK/kopia/go.mod")"
BUILD_COMMIT="$(printf '%s\n' "$BUILD_VERSION" | sed -nE 's/.*-([0-9a-f]{12,40})$/\1/p')"

[ -n "$BUILD_COMMIT" ] || {
    echo "Could not resolve htmluibuild commit from Kopia v${VERSION}." >&2
    exit 1
}

META="$(curl -fsSL \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: Everlomp-Kopia-Local-Bundle' \
    "https://api.github.com/repos/kopia/htmluibuild/commits/${BUILD_COMMIT}")"

HTMLUI_SHA="$(printf '%s' "$META" | python3 -c '
import json, re, sys
obj=json.load(sys.stdin)
msg=obj.get("commit", {}).get("message", "")
for pat in (r"github\.com/kopia/htmlui/commit/([0-9a-f]{40})", r"\bhtmlui\b[^0-9a-f]+([0-9a-f]{40})\b"):
    m=re.search(pat, msg, re.I)
    if m:
        print(m.group(1)); break
')"

[ -n "$HTMLUI_SHA" ] || {
    echo "Could not resolve matching htmlui source SHA." >&2
    exit 1
}

echo "Downloading matching HTMLUI source ${HTMLUI_SHA}..."
mkdir -p "$WORK/htmlui"
curl -fsSL "https://codeload.github.com/kopia/htmlui/tar.gz/${HTMLUI_SHA}" \
    | tar -xz --strip-components=1 -C "$WORK/htmlui"

cat > "$WORK/manifest.json" <<EOF
{
  "format": 1,
  "kopia_version": "${VERSION}",
  "htmlui_source_sha": "${HTMLUI_SHA}"
}
EOF

rm -f "$OUT"
(
    cd "$WORK"
    tar -czf "$OUT" manifest.json kopia htmlui
)

printf '\nCreated:\n  %s\n\n' "$OUT"
printf 'Bundle version: Kopia v%s\nHTMLUI SHA: %s\n' "$VERSION" "$HTMLUI_SHA"
printf 'Now rebuild Everlomp and check "Install local version" in the Kopia installer.\n'
