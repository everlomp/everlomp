#!/bin/bash
# folders that need to persist: /home /var/www /var/lib/mysql /usr/local/lsws secrets used: key and 2fa
# --vol1--var___www--vol2--var___lib___mysql--vol3--usr___local___lsws--sec1--2fa
# support https://discord.gg/DAQszjKEBV (Evernode Community Discord)

set -e

source /contract/env.vars

install -d -o root -g nogroup -m 0750 /run/everlomp
install -d -o root -g nogroup -m 0750 /var/www/.everlomp
openssl rand -hex 16 > /run/everlomp/instance-id
chmod 0600 /run/everlomp/instance-id

install -d -o root -g nogroup -m 0750 /var/www/.everlomp
/usr/local/sbin/everlomp-key boot-finalize >/dev/null 2>&1 || true

EVERLOMP_SECRETS="/home/everlomp/secrets"
KOPIA_SECRETS="$EVERLOMP_SECRETS/kopia"
KOPIA_REPLICATION_SECRETS="$KOPIA_SECRETS/replication"
SECRET_HELPER="/usr/local/sbin/everlomp-secret"

install -d -o root -g root -m 0700 \
    "$EVERLOMP_SECRETS" \
    "$KOPIA_SECRETS" \
    "$KOPIA_REPLICATION_SECRETS"

migrate_protected_secret() {
    local old="$1" new="$2" tmp

    if "$SECRET_HELPER" exists "$new" >/dev/null 2>&1; then
        "$SECRET_HELPER" remove "$old" >/dev/null 2>&1 || true
        return 0
    fi
    "$SECRET_HELPER" exists "$old" >/dev/null 2>&1 || return 0

    tmp="$(mktemp /run/everlomp-secret-migrate.XXXXXX)"
    chmod 0600 "$tmp"
    if "$SECRET_HELPER" read "$old" > "$tmp" \
        && "$SECRET_HELPER" write "$new" < "$tmp"; then
        "$SECRET_HELPER" remove "$old" >/dev/null 2>&1 || true
        echo "[Everlomp boot] Migrated protected secret to $new."
    else
        echo "[Everlomp boot] WARNING: could not migrate protected secret $old." >&2
    fi
    rm -f "$tmp"
}

migrate_plain_secret() {
    local old="$1" new="$2"

    if "$SECRET_HELPER" exists "$new" >/dev/null 2>&1; then
        rm -f "$old"
        return 0
    fi
    [ -s "$old" ] || return 0

    if "$SECRET_HELPER" write "$new" < "$old"; then
        rm -f "$old"
        echo "[Everlomp boot] Migrated credential material to $new."
    else
        echo "[Everlomp boot] WARNING: could not migrate credential material $old." >&2
    fi
}

migrate_protected_secret /var/www/.everlomp-secrets/wordpress-db-password "$EVERLOMP_SECRETS/wordpress-db-password"
migrate_protected_secret /var/www/.everlomp-secrets/phpbb-db-password "$EVERLOMP_SECRETS/phpbb-db-password"
migrate_protected_secret /var/www/.everlomp-secrets/ssh-host-keys.tar "$EVERLOMP_SECRETS/ssh-host-keys.tar"
migrate_protected_secret /home/everlomp/kopia/repository.password "$KOPIA_SECRETS/repository.password"
migrate_protected_secret /home/everlomp/kopia/webui.password "$KOPIA_SECRETS/webui.password"
migrate_protected_secret /home/everlomp/kopia/offsite.json "$KOPIA_SECRETS/offsite.json"
migrate_protected_secret /var/www/.everlomp-secrets/drupal-db-password "$EVERLOMP_SECRETS/drupal-db-password"
if [ -s /home/everlomp/kopia/offsite-s3.json ]; then
    if ! "$SECRET_HELPER" exists "$KOPIA_SECRETS/offsite.json" >/dev/null 2>&1; then
        legacy_offsite_tmp="$(mktemp /run/everlomp-offsite-migrate.XXXXXX)"
        if python3 - /home/everlomp/kopia/offsite-s3.json "$legacy_offsite_tmp" <<'PYOFFSITEMIGRATE'
from pathlib import Path
import json, os, sys
src, dst = map(Path, sys.argv[1:3])
data = json.loads(src.read_text())
if not isinstance(data, dict):
    raise SystemExit(1)
data['type'] = 's3'
dst.write_text(json.dumps(data, indent=2, sort_keys=True) + '\n')
os.chmod(dst, 0o600)
PYOFFSITEMIGRATE
        then
            if "$SECRET_HELPER" write "$KOPIA_SECRETS/offsite.json" < "$legacy_offsite_tmp"; then
                rm -f /home/everlomp/kopia/offsite-s3.json
                echo "[Everlomp boot] Migrated legacy S3 credentials into the central secret store."
            fi
        fi
        rm -f "$legacy_offsite_tmp"
    else
        rm -f /home/everlomp/kopia/offsite-s3.json
    fi
fi

if [ -s /home/everlomp/kopia/repository.config ]; then
    if [ ! -s "$KOPIA_SECRETS/repository.config" ]; then
        install -o root -g root -m 0600 /home/everlomp/kopia/repository.config "$KOPIA_SECRETS/repository.config"
        echo "[Everlomp boot] Migrated Kopia repository.config into the central secret store."
    fi
    rm -f /home/everlomp/kopia/repository.config
fi

if [ -s "$KOPIA_SECRETS/repository.config" ] && [ -f /home/everlomp/kopia/enabled ]; then
    python3 - /home/everlomp/kopia.json <<'PYKOPIASTATE'
from pathlib import Path
import json, os, sys, tempfile
path = Path(sys.argv[1])
try:
    data = json.loads(path.read_text()) if path.is_file() else {}
except Exception:
    data = {}
if not isinstance(data, dict):
    data = {}
data['configured'] = True
fd, tmp = tempfile.mkstemp(prefix='.kopia-info.', dir=str(path.parent))
os.close(fd)
Path(tmp).write_text(json.dumps(data, indent=2, sort_keys=True) + '\n')
os.chmod(tmp, 0o644)
os.replace(tmp, path)
os.chmod(path, 0o644)
PYKOPIASTATE
fi

for legacy_replica in /home/everlomp/kopia/replication-secrets/*.json /home/everlomp/kopia/replication-secrets/*.json.enc; do
    [ -e "$legacy_replica" ] || continue
    legacy_base="${legacy_replica%.enc}"
    replica_name="$(basename "$legacy_base")"
    migrate_protected_secret "$legacy_base" "$KOPIA_REPLICATION_SECRETS/$replica_name"
done
rmdir /home/everlomp/kopia/replication-secrets 2>/dev/null || true

migrate_plain_secret /var/www/.everlomp/lsws-htpasswd "$EVERLOMP_SECRETS/lsws-htpasswd"
migrate_plain_secret /var/www/.everlomp/ssh-password.hash "$EVERLOMP_SECRETS/ssh-password.hash"
rmdir /var/www/.everlomp-secrets 2>/dev/null || true

EVERLOMP_STATE="/var/www/.everlomp"
SUPERVISOR_CONF="/etc/everlomp/supervisord.conf"

set_supervisor_program() {
    local program="$1" autostart="$2" command_override="${3:-}"
    [ -f "$SUPERVISOR_CONF" ] || return 0
    python3 - "$SUPERVISOR_CONF" "$program" "$autostart" "$command_override" <<'PYRESTORE'
from pathlib import Path
import re, sys
path=Path(sys.argv[1]); program=sys.argv[2]; desired=sys.argv[3]; command=sys.argv[4]
text=path.read_text()
pat=re.compile(r'(?ms)^\[program:' + re.escape(program) + r'\]\s*$.*?(?=^\[|\Z)')
m=pat.search(text)
if not m: raise SystemExit(0)
section=m.group(0)
if re.search(r'(?m)^autostart\s*=', section):
    section=re.sub(r'(?m)^autostart\s*=.*$', 'autostart='+desired, section, count=1)
else:
    pos=section.find('\n'); section=section[:pos+1]+'autostart='+desired+'\n'+section[pos+1:]
if command and re.search(r'(?m)^command\s*=', section):
    section=re.sub(r'(?m)^command\s*=.*$', 'command='+command, section, count=1)
path.write_text(text[:m.start()]+section+text[m.end():])
PYRESTORE
    local rc=$?
    if [ $rc -ne 0 ]; then
        echo "[Everlomp boot] WARNING: could not restore Supervisor state for $program." >&2
    fi
    return 0
}

for marker in database.configured database-admin-user lsws-password.configured hotpocket.enabled ssh.configured; do
    if [ ! -e "$EVERLOMP_STATE/$marker" ] && [ -e "/home/everlomp/$marker" ]; then
        cp -a "/home/everlomp/$marker" "$EVERLOMP_STATE/$marker" 2>/dev/null || true
    fi
done

LSWS_HTPASSWD_SECRET="$EVERLOMP_SECRETS/lsws-htpasswd"
if "$SECRET_HELPER" exists "$LSWS_HTPASSWD_SECRET" >/dev/null 2>&1; then
    lsws_htpasswd_tmp="$(mktemp /run/everlomp-lsws-htpasswd.XXXXXX)"
    chmod 0600 "$lsws_htpasswd_tmp"
    if "$SECRET_HELPER" read "$LSWS_HTPASSWD_SECRET" > "$lsws_htpasswd_tmp" \
        && install -d -o lsadm -g lsadm -m 0750 /usr/local/lsws/admin/conf \
        && install -o lsadm -g lsadm -m 0600 "$lsws_htpasswd_tmp" /usr/local/lsws/admin/conf/htpasswd; then
        echo "[Everlomp boot] Restored OpenLiteSpeed WebAdmin password hash."
    else
        echo "[Everlomp boot] WARNING: could not restore OpenLiteSpeed WebAdmin password hash." >&2
    fi
    rm -f "$lsws_htpasswd_tmp"
fi

if [ -x "$EVERLOMP_STATE/kopia-runtime/kopia" ]; then
    if install -m 0755 "$EVERLOMP_STATE/kopia-runtime/kopia" /usr/local/bin/kopia \
        && ln -sfn /usr/local/bin/kopia /usr/bin/kopia; then
        echo "[Everlomp boot] Restored persistent Kopia executable."
    else
        echo "[Everlomp boot] WARNING: could not restore persistent Kopia executable." >&2
    fi
fi
if [ -f /home/everlomp/kopia/enabled ] && [ -x /usr/local/bin/kopia ]; then
    set_supervisor_program kopia true
    echo "[Everlomp boot] Restored Kopia autostart=true."
else
    set_supervisor_program kopia false
    if [ -f /home/everlomp/kopia/enabled ] && [ ! -x /usr/local/bin/kopia ]; then
        echo "[Everlomp boot] WARNING: Kopia is enabled but no executable could be restored." >&2
    fi
fi

if [ -f "$EVERLOMP_STATE/hotpocket.enabled" ] || [ -f /home/everlomp/hotpocket.enabled ]; then
    set_supervisor_program hotpocket true
else
    set_supervisor_program hotpocket false
fi

SSH_STATE="$EVERLOMP_STATE/ssh.configured"
SSH_HASH="$EVERLOMP_SECRETS/ssh-password.hash"
SSH_PORT_FILE="$EVERLOMP_STATE/ssh.port"
SSH_HOST_KEYS_SECRET="$EVERLOMP_SECRETS/ssh-host-keys.tar"
if [ "$(cat "$SSH_STATE" 2>/dev/null || true)" = "enabled" ]; then
    if "$SECRET_HELPER" exists "$SSH_HASH" >/dev/null 2>&1; then
        ssh_password_hash="$("$SECRET_HELPER" read "$SSH_HASH" 2>/dev/null || true)"
        if [ -z "$ssh_password_hash" ] || ! usermod -p "$ssh_password_hash" everlomp; then
            echo "[Everlomp boot] WARNING: could not restore SSH password hash." >&2
            set_supervisor_program sshd false
        fi
        ssh_port="$(cat "$SSH_PORT_FILE" 2>/dev/null || true)"
        if ! [[ "$ssh_port" =~ ^[0-9]+$ ]] || [ "$ssh_port" -lt 1 ] || [ "$ssh_port" -gt 65535 ]; then
            ssh_port="${EXTERNAL_GPTCP2_PORT:-}"
        fi
        if [[ "$ssh_port" =~ ^[0-9]+$ ]] && [ "$ssh_port" -ge 1 ] && [ "$ssh_port" -le 65535 ]; then
            set_supervisor_program sshd true "/usr/sbin/sshd -D -p $ssh_port"
            echo "[Everlomp boot] Restored SSH password hash and port $ssh_port."
        else
            set_supervisor_program sshd false
            echo "[Everlomp boot] WARNING: SSH enabled but no valid GPTCP2 port exists." >&2
        fi
    else
        set_supervisor_program sshd false
        echo "[Everlomp boot] WARNING: SSH enabled marker exists but persisted password hash is missing." >&2
    fi
else
    set_supervisor_program sshd false
fi

if /usr/local/sbin/everlomp-secret exists "$SSH_HOST_KEYS_SECRET" >/dev/null 2>&1; then
    hostkeys_tar="$(mktemp /run/everlomp-hostkeys.XXXXXX)"
    if /usr/local/sbin/everlomp-secret read "$SSH_HOST_KEYS_SECRET" > "$hostkeys_tar"; then
        tar -C /etc/ssh -xf "$hostkeys_tar" >/dev/null 2>&1 || true
        chmod 0600 /etc/ssh/ssh_host_*_key 2>/dev/null || true
        chmod 0644 /etc/ssh/ssh_host_*_key.pub 2>/dev/null || true
    fi
    rm -f "$hostkeys_tar"
fi

if /usr/local/sbin/everlomp-secret exists "$EVERLOMP_SECRETS/wordpress-db-password"; then
    /usr/local/sbin/everlomp-secret materialize-web "$EVERLOMP_SECRETS/wordpress-db-password" /run/everlomp/web/wordpress-db-password
fi
if /usr/local/sbin/everlomp-secret exists "$EVERLOMP_SECRETS/phpbb-db-password"; then
    /usr/local/sbin/everlomp-secret materialize-web "$EVERLOMP_SECRETS/phpbb-db-password" /run/everlomp/web/phpbb-db-password
fi
if /usr/local/sbin/everlomp-secret exists "$EVERLOMP_SECRETS/drupal-db-password"; then
    /usr/local/sbin/everlomp-secret materialize-web "$EVERLOMP_SECRETS/drupal-db-password" /run/everlomp/web/drupal-db-password
fi
[ ! -f /etc/ssh/ssh_host_rsa_key ] && ssh-keygen -A

chown everlomp:everlomp /home/everlomp
find /home/everlomp -mindepth 1 -maxdepth 1 \
    ! -name secrets \
    ! -name kopiasnapshots \
    -exec chown -R everlomp:everlomp {} +

# The primary Kopia repository belongs exclusively to the privileged Kopia
# service/worker. The unprivileged replication API never opens repository
# storage or receives its password.
if [ -d /home/everlomp/kopiasnapshots ]; then
    chown -R root:root /home/everlomp/kopiasnapshots
    find /home/everlomp/kopiasnapshots -type d -exec chmod 0700 {} +
    find /home/everlomp/kopiasnapshots -type f -exec chmod 0600 {} +
fi

chown -R root:root "$EVERLOMP_SECRETS"
find "$EVERLOMP_SECRETS" -type d -exec chmod 0700 {} +
find "$EVERLOMP_SECRETS" -type f -exec chmod 0600 {} +

CFG_FILE="/contract/cfg/hp.cfg"

PHPMYADMIN_ROOT="/usr/local/everlomp/phpmyadmin"
PHPMYADMIN_CONFIG="${PHPMYADMIN_ROOT}/config.inc.php"
PHPMYADMIN_TMP="/var/lib/phpmyadmin/tmp"
PHPMYADMIN_AUTH_LOG="/var/log/everlomp/phpmyadmin-auth.log"
DISK_QUOTA_NUM=${DISK_QUOTA%GB}
DISK_USED=$(df -BG / | awk 'NR==2 {gsub("G","",$3); print $3}')
DISK_LEFT=$((DISK_QUOTA_NUM - DISK_USED))
echo "Disk Space:"
echo "Quota: ${DISK_QUOTA}"
echo "Used: ${DISK_USED}GB"
echo "Left: ${DISK_LEFT}GB"
mkdir -p /var/log/everlomp
touch "$PHPMYADMIN_AUTH_LOG"
chown nobody:nogroup "$PHPMYADMIN_AUTH_LOG"
chmod 0640 "$PHPMYADMIN_AUTH_LOG"

if [ -d "$PHPMYADMIN_ROOT" ]; then
    mkdir -p "$PHPMYADMIN_TMP"

    chown -R nobody:nogroup /var/lib/phpmyadmin
    chmod 0750 /var/lib/phpmyadmin
    chmod 0700 "$PHPMYADMIN_TMP"

    if [ ! -f "$PHPMYADMIN_CONFIG" ]; then
        echo "[phpMyAdmin] Creating runtime configuration..."

        PHPMYADMIN_BLOWFISH_SECRET="$(openssl rand -hex 32)"

        cat > "$PHPMYADMIN_CONFIG" <<EOF
<?php
declare(strict_types=1);

\$cfg['blowfish_secret'] = '${PHPMYADMIN_BLOWFISH_SECRET}';

\$i = 0;
\$i++;

\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = 'localhost';
\$cfg['Servers'][\$i]['connect_type'] = 'socket';
\$cfg['Servers'][\$i]['socket'] = '/run/mysqld/mysqld.sock';
\$cfg['Servers'][\$i]['compress'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;

\$cfg['AllowArbitraryServer'] = false;

\$cfg['AuthLog'] = '${PHPMYADMIN_AUTH_LOG}';
\$cfg['AuthLogSuccess'] = false;

\$cfg['TempDir'] = '${PHPMYADMIN_TMP}';
\$cfg['LoginCookieStore'] = 0;
\$cfg['LoginCookieDeleteAll'] = true;
EOF

        chown root:nogroup "$PHPMYADMIN_CONFIG"
        chmod 0640 "$PHPMYADMIN_CONFIG"

        echo "[phpMyAdmin] Runtime configuration created"
    fi
else
    echo "[phpMyAdmin] WARNING: ${PHPMYADMIN_ROOT} is missing"
fi

if [ -f "$PHPMYADMIN_CONFIG" ] && \
   ! grep -q "phpmyadmin-auth.log" "$PHPMYADMIN_CONFIG"; then

    echo "[phpMyAdmin] Adding authentication logging configuration..."

    cat >> "$PHPMYADMIN_CONFIG" <<EOF

\$cfg['AuthLog'] = '${PHPMYADMIN_AUTH_LOG}';
\$cfg['AuthLogSuccess'] = false;
EOF

    chown root:nogroup "$PHPMYADMIN_CONFIG"
    chmod 0640 "$PHPMYADMIN_CONFIG"
fi

FILEGATOR_STATE="/var/www/.everlomp-filegator"
FILEGATOR_APP="$FILEGATOR_STATE/app"
FILEGATOR_INFO="$FILEGATOR_STATE/info.json"
FILEGATOR_VHOST_CONF="/usr/local/lsws/conf/vhosts/GPTCP1/vhconf.conf"

install -d -o root -g nogroup -m 0710 "$FILEGATOR_STATE"
if [ ! -e "$FILEGATOR_APP" ] && [ -d /home/everlomp/filegator ]; then
    cp -a /home/everlomp/filegator "$FILEGATOR_APP" 2>/dev/null || true
fi
if [ ! -e "$FILEGATOR_INFO" ] && [ -s /home/everlomp/filegator.json ]; then
    cp -a /home/everlomp/filegator.json "$FILEGATOR_INFO" 2>/dev/null || true
fi

if [ -f "$FILEGATOR_VHOST_CONF" ]; then
    python3 - "$FILEGATOR_VHOST_CONF" "$FILEGATOR_APP" "$FILEGATOR_INFO" <<'PY'
from pathlib import Path
import re
import sys

conf = Path(sys.argv[1])
app = Path(sys.argv[2])
info = Path(sys.argv[3])
text = conf.read_text()

text = re.sub(
    r'(?ms)^# BEGIN everlomp FileGator\n.*?^# END everlomp FileGator\n?',
    '',
    text,
)

text = re.sub(
    r'(?ms)^# FileGator is optional\. Its application is installed persistently under\n'
    r'# /home/everlomp/filegator; only dist/ is exposed to the web\.\n'
    r'context /filegator/\s*\{.*?^[ \t]+\}\n^\}\n?',
    '',
    text,
)

installed = (app / 'dist' / 'index.php').is_file() and info.is_file()

if installed:
    block = r'''# BEGIN everlomp FileGator
context /filegator/ {
    location                /var/www/.everlomp-filegator/app/dist/
    allowBrowse             1
    indexFiles              index.php
    addDefaultCharset       off

    rewrite {
        enable              0
    }
}
# END everlomp FileGator
'''
    anchor = re.search(r'(?m)^context /openlitespeed/\s*\{', text)
    if anchor:
        text = text[:anchor.start()] + block + "\n" + text[anchor.start():]
    else:
        text = text.rstrip() + "\n\n" + block

conf.write_text(text)
PY
fi

if [ -f "$FILEGATOR_APP/dist/index.php" ]; then
    if [ -s "$FILEGATOR_INFO" ]; then
        FILEGATOR_ROOT="$(jq -r '.root // empty' "$FILEGATOR_INFO" 2>/dev/null || true)"
        FILEGATOR_ROOT="$(realpath -m -- "$FILEGATOR_ROOT" 2>/dev/null || true)"
        case "$FILEGATOR_ROOT" in
            /var/www)
                mkdir -p -- "$FILEGATOR_ROOT"
                chown root:nogroup "$FILEGATOR_ROOT"
                chmod 1775 "$FILEGATOR_ROOT"
                echo "[Everlomp boot] Restored protected FileGator /var/www root permissions."
                ;;
            /var/www/*)
                mkdir -p -- "$FILEGATOR_ROOT"
                chown nobody:nogroup "$FILEGATOR_ROOT"
                chmod u+rwx,go+rx "$FILEGATOR_ROOT"
                echo "[Everlomp boot] Restored FileGator storage-root permissions: $FILEGATOR_ROOT"
                ;;
        esac
    fi

    chown -R root:root "$FILEGATOR_APP"
    find "$FILEGATOR_APP" -type d -exec chmod 0755 {} +
    find "$FILEGATOR_APP" -type f -exec chmod 0644 {} +

    if [ -d "$FILEGATOR_APP/private" ]; then
        chown -R nobody:nogroup "$FILEGATOR_APP/private"
        find "$FILEGATOR_APP/private" -type d -exec chmod 0750 {} +
        find "$FILEGATOR_APP/private" -type f -exec chmod 0640 {} +
    fi

    if [ -f "$FILEGATOR_APP/configuration.php" ]; then
        chown root:nogroup "$FILEGATOR_APP/configuration.php"
        chmod 0640 "$FILEGATOR_APP/configuration.php"
    fi
fi

OLS_CONF="/usr/local/lsws/conf/httpd_config.conf"
TLS_CERT="/contract/cfg/tlscert.pem"
TLS_KEY="/contract/cfg/tlskey.pem"

python3 - "$OLS_CONF" <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
text = path.read_text()

block = """perClientConnLimit {
    staticReqPerSec         40
    dynReqPerSec            5
    outBandwidth            0
    inBandwidth             0
    softLimit               15
    hardLimit               20
    blockBadReq             1
    gracePeriod             15
    banPeriod               300
}"""

pattern = re.compile(
    r'(?ms)^perClientConnLimit\s*\{.*?^\}'
)

if pattern.search(text):
    text = pattern.sub(block, text, count=1)
else:
    # Put the block before the server-level accessControl when possible.
    access = re.search(r'(?m)^accessControl\s*\{', text)

    if access:
        text = text[:access.start()] + block + "\n\n" + text[access.start():]
    else:
        text += "\n\n" + block + "\n"

path.write_text(text)
PY

echo "[OpenLiteSpeed] Per-client throttling configured:"
echo "[OpenLiteSpeed]   static requests/sec: 40"
echo "[OpenLiteSpeed]   dynamic requests/sec: 5"
echo "[OpenLiteSpeed]   connection soft/hard: 15/20"
echo "[OpenLiteSpeed]   abuse ban period: 300 seconds"


if [ -z "${EXTERNAL_GPTCP1_PORT:-}" ]; then
    echo "[OpenLiteSpeed] ERROR: EXTERNAL_GPTCP1_PORT is not set"
    exit 1
fi

if [ ! -f "$TLS_CERT" ]; then
    echo "[OpenLiteSpeed] ERROR: Missing TLS certificate: $TLS_CERT"
    exit 1
fi

if [ ! -f "$TLS_KEY" ]; then
    echo "[OpenLiteSpeed] ERROR: Missing TLS key: $TLS_KEY"
    exit 1
fi

echo "[OpenLiteSpeed] GPTCP1 port: ${EXTERNAL_GPTCP1_PORT}"
echo "[OpenLiteSpeed] TLS cert: $TLS_CERT"
echo "[OpenLiteSpeed] TLS key:  $TLS_KEY"

sed -i \
    '/# BEGIN everlomp GPTCP1/,/# END everlomp GPTCP1/d' \
    "$OLS_CONF"

cat >> "$OLS_CONF" <<EOF

# BEGIN everlomp GPTCP1

virtualHost GPTCP1 {
    vhRoot                  /usr/local/lsws/GPTCP1/
    configFile              conf/vhosts/GPTCP1/vhconf.conf
    allowSymbolLink         1
    enableScript            1
    restrained              0
}

listener GPTCP1 {
    address                 *:${EXTERNAL_GPTCP1_PORT}
    secure                  1

    keyFile                 ${TLS_KEY}
    certFile                ${TLS_CERT}
    certChain               1

    map                     GPTCP1 *
}

# END everlomp GPTCP1
EOF

echo "[OpenLiteSpeed] GPTCP1 listener configured"
echo "[OpenLiteSpeed] Site:         https://ANY-DOMAIN:${EXTERNAL_GPTCP1_PORT}/"
echo "[OpenLiteSpeed] Everlomp:     https://ANY-DOMAIN:${EXTERNAL_GPTCP1_PORT}/install.php"
echo "[OpenLiteSpeed] phpMyAdmin:   https://ANY-DOMAIN:${EXTERNAL_GPTCP1_PORT}/phpmyadmin/"
echo "[OpenLiteSpeed] WebAdmin:     https://ANY-DOMAIN:${EXTERNAL_GPTCP1_PORT}/openlitespeed/"

exec /usr/bin/supervisord \
    -c "$SUPERVISOR_CONF"
