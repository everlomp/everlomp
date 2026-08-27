#!/bin/bash
set -euo pipefail
VERSION="11.4.5"
FILE="drupal-${VERSION}.tar.gz"
URL="https://ftp.drupal.org/files/projects/${FILE}"
SHA256="c9444b40993332f4dd0e57968a20c6013ffcac384f3a3ea9f62e6a0bfddf24e6"
DEST="$(cd "$(dirname "$0")" && pwd)/${FILE}"
TMP="${DEST}.part"
rm -f "$TMP"
echo "Fetching Drupal ${VERSION} fallback..."
curl -fL --retry 3 --connect-timeout 20 --max-time 300 "$URL" -o "$TMP"
echo "$SHA256  $TMP" | sha256sum -c -
mv "$TMP" "$DEST"
echo "Saved verified fallback: $DEST"
