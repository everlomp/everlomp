#!/bin/bash

set -e

mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
chown mysql:mysql /var/lib/mysql

if [ ! -d /var/lib/mysql/mysql ]; then
    echo "[MariaDB] Initializing database..."

    mariadb-install-db \
        --user=mysql \
        --datadir=/var/lib/mysql
fi

echo "[MariaDB] Starting server..."

exec /usr/sbin/mariadbd \
    --user=mysql \
    --datadir=/var/lib/mysql