#!/bin/bash
set -Eeuo pipefail

# Everlomp passes one JSON object on stdin:
# {
#   "schema": 1,
#   "package_id": "dummy-example",
#   "fields": { ... values from manifest.json ... }
# }
PAYLOAD="$(cat)"
SITE_TITLE="$(jq -r '.fields.site_title // "My Everlomp Example"' <<<"$PAYLOAD")"
SHOW_TIMESTAMP="$(jq -r '.fields.show_timestamp // false' <<<"$PAYLOAD")"

# This example behaves like a primary application installer: preserve the
# /install.php setup page while replacing everything else in the public web root.
find /var/www/html -mindepth 1 -maxdepth 1 ! -name lompinstaller.php -exec rm -rf -- {} +

TITLE_ESCAPED="$(python3 -c 'import html,sys; print(html.escape(sys.stdin.read()))' <<<"$SITE_TITLE")"
TIMESTAMP_HTML=""
if [ "$SHOW_TIMESTAMP" = "true" ]; then
    TIMESTAMP_HTML="<p>Installed at $(date -u +'%Y-%m-%d %H:%M:%S UTC')</p>"
fi

cat > /var/www/html/index.html <<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>${TITLE_ESCAPED}</title>
  <style>body{font-family:system-ui,sans-serif;max-width:760px;margin:10vh auto;padding:24px;background:#10131a;color:#f5f7ff}code{background:#1b2130;padding:2px 6px;border-radius:6px}</style>
</head>
<body>
  <h1>${TITLE_ESCAPED}</h1>
  <p>This page was installed by the downloadable Everlomp external-installer example.</p>
  ${TIMESTAMP_HTML}
  <p>Edit <code>manifest.json</code> for the form and <code>install.sh</code> for the installation logic.</p>
</body>
</html>
HTML

chmod 0644 /var/www/html/index.html
printf 'Dummy Example App installed successfully.\n'
