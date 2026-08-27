#!/bin/bash
set -euo pipefail

BASE="/home/everlomp/kopia"
SECRETS_ROOT="/home/everlomp/secrets"
KOPIA_SECRETS="$SECRETS_ROOT/kopia"
CONFIG="$KOPIA_SECRETS/repository.config"
REPO_PASSWORD_FILE="$KOPIA_SECRETS/repository.password"
WEB_PASSWORD_FILE="$KOPIA_SECRETS/webui.password"
INFO="/home/everlomp/kopia.json"
SECRET_HELPER="/usr/local/sbin/everlomp-secret"

KOPIA_BIN=""
for candidate in /usr/local/bin/kopia /usr/bin/kopia; do
    if [ -x "$candidate" ]; then
        KOPIA_BIN="$candidate"
        break
    fi
done

if [ -z "$KOPIA_BIN" ]; then
    echo "[Kopia] Kopia executable is missing. Install Kopia from the Everlomp installer first." >&2
    exit 1
fi

mkdir -p "$BASE" "$KOPIA_SECRETS"
chmod 0700 "$BASE" || true
chown root:root "$KOPIA_SECRETS" 2>/dev/null || true
chmod 0700 "$KOPIA_SECRETS" || true

if [ -s "$INFO" ]; then
    python3 - "$INFO" <<'PYINFO'
from pathlib import Path
import json
import os
import sys
import tempfile

path = Path(sys.argv[1])
try:
    data = json.loads(path.read_text())
except Exception:
    raise SystemExit(0)

if not isinstance(data, dict):
    raise SystemExit(0)

repo_path = data.get("repository_path")
if repo_path and data.get("configured") is not True:
    data["configured"] = True
    fd, tmp = tempfile.mkstemp(prefix=".kopia-info.", dir=str(path.parent))
    os.close(fd)
    Path(tmp).write_text(json.dumps(data, indent=2, sort_keys=True) + "\n")
    os.replace(tmp, path)
PYINFO
    chown root:nogroup "$INFO" 2>/dev/null || true
    chmod 0640 "$INFO" 2>/dev/null || true
fi

if ! "$SECRET_HELPER" exists "$WEB_PASSWORD_FILE"; then
    echo "[Kopia] Web UI credentials are missing." >&2
    exit 1
fi

export KOPIA_SERVER_PASSWORD
KOPIA_SERVER_PASSWORD="$("$SECRET_HELPER" read "$WEB_PASSWORD_FILE")"

export KOPIA_CONFIG_PATH="$CONFIG"

if "$SECRET_HELPER" exists "$REPO_PASSWORD_FILE"; then
    export KOPIA_PASSWORD
    KOPIA_PASSWORD="$("$SECRET_HELPER" read "$REPO_PASSWORD_FILE")"
else
    unset KOPIA_PASSWORD || true
fi

if [ -s "$CONFIG" ]; then
    echo "[Kopia] Starting Web UI; repository configuration is present." >&2
else
    echo "[Kopia] Starting Web UI in disconnected mode; repository.config is absent." >&2
fi

exec "$KOPIA_BIN" server start \
    --address=http://127.0.0.1:51515 \
    --insecure \
    --server-username=admin \
    --ui \
    --no-grpc \
    --async-repo-connect \
    --no-check-for-updates \
    --description="Everlomp Backup"
