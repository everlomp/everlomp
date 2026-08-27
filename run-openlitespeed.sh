#!/bin/bash

set -e

shutdown()
{
    echo "[OpenLiteSpeed] Stopping..."

    /usr/local/lsws/bin/lswsctrl stop || true

    exit 0
}

trap shutdown TERM INT

echo "[OpenLiteSpeed] Starting..."

/usr/local/lsws/bin/lswsctrl start

sleep 2


while true; do

    if ! /usr/local/lsws/bin/lswsctrl status >/dev/null 2>&1; then
        echo "[OpenLiteSpeed] Server stopped unexpectedly."
        exit 1
    fi

    sleep 5 &
    wait $!

done