FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Etc/UTC
ENV GOTOOLCHAIN=auto

LABEL org.opencontainers.image.source="https://github.com/everlomp/everlomp"

RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d


RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        wget \
        gnupg \
        libssl3 \
        libstdc++6 \
        unzip \
        tar \
        jq \
        rclone \
        tzdata \
        python3 \
        qrencode \
        make \
        openssh-server \
        openssh-client \
        sshpass \
        python3-pexpect \
        python3-cryptography \
        openssl \
        sudo \
        bash \
        nano \
        supervisor \
        cron \
        nginx && \
    ln -fs /usr/share/zoneinfo/Etc/UTC /etc/localtime && \
    dpkg-reconfigure --frontend noninteractive tzdata && \
    rm -f /etc/nginx/sites-enabled/default && \
    mkdir -p /var/log/supervisor && \
    rm -rf /var/lib/apt/lists/*


RUN curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | \
        gpg --dearmor -o /usr/share/keyrings/nodesource.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends nodejs && \
    rm -rf /var/lib/apt/lists/*

COPY everlomp/kopia-htmlui-patch.py /usr/local/sbin/kopia-htmlui-patch.py
COPY everlomp/kopia-replication-page.jsx /usr/local/share/everlomp/kopia-replication-page.jsx
COPY everlomp/kopia-repository-security.jsx /usr/local/share/everlomp/kopia-repository-security.jsx
COPY everlomp/kopia-webui-security.jsx /usr/local/share/everlomp/kopia-webui-security.jsx
COPY everlomp/everlomp-kopia-custom /usr/local/sbin/everlomp-kopia-custom

COPY everlomp/local-kopia/ /usr/local/share/everlomp/kopia-local/

RUN chmod 0755 \
        /usr/local/sbin/kopia-htmlui-patch.py \
        /usr/local/sbin/everlomp-kopia-custom && \
    command -v tar >/dev/null && \
    command -v make >/dev/null && \
    command -v node >/dev/null && \
    command -v npm >/dev/null && \
    command -v qrencode >/dev/null


RUN mkdir -p /usr/local/bin/hotpocket

COPY hotpocket/hpcore /usr/local/bin/hotpocket/hpcore
COPY hotpocket/hpfs /usr/local/bin/hotpocket/hpfs
COPY hotpocket/hpws /usr/local/bin/hotpocket/hpws
COPY hotpocket/evernode-license.pdf /usr/local/bin/hotpocket/

RUN chmod +x /usr/local/bin/hotpocket/*

COPY lib/libblake3.so /usr/local/lib/
COPY lib/libssl.so.1.1 /usr/lib/x86_64-linux-gnu/
COPY lib/libcrypto.so.1.1 /usr/lib/x86_64-linux-gnu/

RUN ldconfig && mkdir -p /contract


RUN wget -O - https://repo.litespeed.sh | bash

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        openlitespeed git \
        lsphp85 \
        lsphp85-common \
        lsphp85-curl \
        lsphp85-imagick \
        lsphp85-imap \
        lsphp85-memcached \
        lsphp85-mysql \
        lsphp85-redis && \
    rm -rf /var/lib/apt/lists/*

RUN ln -sf \
    /usr/local/lsws/lsphp85/bin/lsphp \
    /usr/local/lsws/fcgi-bin/lsphp

RUN PHP_INI=/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/php.ini && \
    test -f "$PHP_INI" && \
    printf '%s\n' \
        '' \
        '; Everlomp resource/upload defaults' \
        'memory_limit = 512M' \
        'max_input_vars = 20000' \
        'upload_max_filesize = 256M' \
        'post_max_size = 300M' \
        'max_execution_time = 300' \
        'max_input_time = 300' \
        'max_file_uploads = 50' \
        >> "$PHP_INI"


RUN mkdir -p /etc/apt/keyrings && \
    curl -fsSL \
        --retry 5 \
        --retry-delay 3 \
        --retry-all-errors \
        https://mariadb.org/mariadb_release_signing_key.pgp \
        -o /etc/apt/keyrings/mariadb-keyring.asc && \
    printf '%s\n' \
        'X-Repolib-Name: MariaDB' \
        'Types: deb' \
        'URIs: https://deb.mariadb.org/12.3/ubuntu' \
        'Suites: noble' \
        'Components: main' \
        'Signed-By: /etc/apt/keyrings/mariadb-keyring.asc' \
        > /etc/apt/sources.list.d/mariadb.sources

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        mariadb-server \
        mariadb-client && \
    rm -rf /var/lib/apt/lists/*

RUN mkdir -p \
        /run/mysqld \
        /var/lib/mysql \
        /var/log/mysql && \
    chown -R mysql:mysql \
        /run/mysqld \
        /var/lib/mysql \
        /var/log/mysql


RUN mkdir -p /var/www/html


RUN cat > /etc/nginx/conf.d/openlitespeed-admin.conf <<'EOF'
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

map $http_authorization $kopia_auth_supplied {
    default 1;
    ''      0;
}

log_format kopia_auth '$remote_addr $everlomp_kopia_auth_status $kopia_auth_supplied $request_uri';

server {
    listen 127.0.0.1:7081;
    server_name _;
    set_real_ip_from 127.0.0.1;
    real_ip_header X-Forwarded-For;
    real_ip_recursive on;
    absolute_redirect off;
    port_in_redirect off;

    location = /openlitespeed {
        return 301 /openlitespeed/;
    }

    location /openlitespeed/ {
        proxy_pass https://127.0.0.1:7080/;
        proxy_http_version 1.1;

        proxy_ssl_verify off;

        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Prefix /openlitespeed;

        proxy_set_header X-Real-IP $http_x_real_ip;
        proxy_set_header X-Forwarded-For $http_x_forwarded_for;

        proxy_set_header Accept-Encoding "";

        proxy_redirect https://127.0.0.1:7080/ /openlitespeed/;
        proxy_redirect / /openlitespeed/;

        proxy_cookie_path / /openlitespeed/;

        sub_filter_once off;

        # Only rewrite CSS assets.
        # HTML is already included by nginx's sub_filter module by default.
        sub_filter_types text/css;

        # Rewrite actual HTML attributes rather than every quoted "/".
        sub_filter 'href="/'   'href="/openlitespeed/';
        sub_filter 'src="/'    'src="/openlitespeed/';
        sub_filter 'action="/' 'action="/openlitespeed/';

        sub_filter "href='/"   "href='/openlitespeed/";
        sub_filter "src='/"    "src='/openlitespeed/";
        sub_filter "action='/" "action='/openlitespeed/";

        # CSS root-relative resources.
        sub_filter 'url(/' 'url(/openlitespeed/';
    }

    location = /kopia {
        return 301 /kopia/;
    }

    # Kopia second-factor gate. The internal subrequest validates the existing
    # Kopia Basic credential and, when 2FA is activated, the signed 2FA session
    # cookie. The challenge endpoint is deliberately outside the gate so it can
    # collect the authenticator code after the Basic password has been accepted.
    location = /__everlomp_kopia_2fa_auth {
        internal;
        proxy_pass http://127.0.0.1:51517/two-factor/auth;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
    }

    # Only an auth-subrequest 403 means the Kopia password succeeded and
    # the browser still needs a TOTP session. A 403 from Kopia itself remains
    # a normal forbidden response.
    location @everlomp_kopia_2fa_required {
        if ($everlomp_kopia_auth_status = 403) {
            return 302 /kopia/everlomp-2fa/challenge;
        }
        return 403;
    }

    location = /kopia/everlomp-2fa/challenge {
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/two-factor/challenge;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 8k;
    }

    location = /kopia/everlomp-api/two-factor/status {
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/two-factor/status;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_buffering off;
    }

    location = /kopia/everlomp-api/two-factor/generate {
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/two-factor/generate;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 16k;
    }

    location = /kopia/everlomp-api/two-factor/activate {
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/two-factor/activate;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 16k;
    }

    location = /kopia/everlomp-api/two-factor/disable {
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/two-factor/disable;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 16k;
    }

    location = /kopia/replication {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51515/;
        proxy_http_version 1.1;

        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Prefix /kopia;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;

        proxy_cookie_path / /kopia/;
        proxy_redirect http://127.0.0.1:51515/ /kopia/;
        proxy_redirect ~^(/.*)$ /kopia$1;
        proxy_buffering off;
    }

    location = /kopia/replication/ {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51515/;
        proxy_http_version 1.1;

        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Prefix /kopia;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;

        proxy_cookie_path / /kopia/;
        proxy_redirect http://127.0.0.1:51515/ /kopia/;
        proxy_redirect ~^(/.*)$ /kopia$1;
        proxy_buffering off;
    }

    # Repository password operations bypass the unprivileged replication API.
    # They terminate in a deliberately tiny root-only admin service that never
    # exposes the repository password to the everlomp worker.
    location = /kopia/everlomp-api/source-password {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/source-password;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 1m;
    }

    location = /kopia/everlomp-api/source-password/change {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/source-password/change;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 1m;
    }

    # WebUI login-password changes also terminate in the narrow root admin
    # service. The authenticated browser proves knowledge of the old password;
    # only the replacement password crosses this endpoint.
    location = /kopia/everlomp-api/webui-password/change {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51517/webui-password/change;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 1m;
    }

    location ^~ /kopia/everlomp-api/ {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51516/;
        proxy_http_version 1.1;

        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;

        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 3700s;
        proxy_send_timeout 3700s;
        client_max_body_size 1m;
    }

    location /kopia/ {
        auth_request /__everlomp_kopia_2fa_auth;
        auth_request_set $everlomp_kopia_auth_status $upstream_status;
        access_log /var/log/nginx/kopia-auth.log kopia_auth;
        error_page 403 = @everlomp_kopia_2fa_required;
        include /etc/nginx/everlomp-kopia-bans.conf;
        proxy_pass http://127.0.0.1:51515/;
        proxy_http_version 1.1;

        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Prefix /kopia;

        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Authorization $http_authorization;

        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;

        # Scope Kopias browser session cookie to the public mount point.
        proxy_cookie_path / /kopia/;

        # Preserve public /kopia/ when Kopia returns root-relative redirects.
        proxy_redirect http://127.0.0.1:51515/ /kopia/;
        proxy_redirect ~^(/.*)$ /kopia$1;

        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        client_max_body_size 0;
    }
}
EOF

RUN touch /etc/nginx/everlomp-kopia-bans.conf && \
    chmod 0644 /etc/nginx/everlomp-kopia-bans.conf && \
    nginx -t


RUN mkdir -p \
        /usr/local/lsws/conf/vhosts/GPTCP1 \
        /usr/local/lsws/GPTCP1/logs && \
    cat > /usr/local/lsws/conf/vhosts/GPTCP1/vhconf.conf <<'EOF'
docRoot                   /var/www/html/

index  {
    useServer               0
    indexFiles              index.php,index.html
}

errorlog /usr/local/lsws/GPTCP1/logs/error.log {
    useServer               0
    logLevel                WARN
    rollingSize             10M
}

accesslog /usr/local/lsws/GPTCP1/logs/access.log {
    useServer               0
    rollingSize             10M
    keepDays                30
}

extprocessor lsphp85 {
    type                    lsapi
    address                 uds://tmp/lshttpd/lsphp85.sock
    maxConns                35
    env                     PHP_LSAPI_CHILDREN=35
    env                     LSAPI_AVOID_FORK=200M
    initTimeout             60
    retryTimeout            0
    persistConn             1
    respBuffer              0
    autoStart               2
    path                    /usr/local/lsws/lsphp85/bin/lsphp
    backlog                 100
    instances               1
}

scripthandler {
    add                     lsapi:lsphp85 php
}

rewrite {
    enable                  1
    autoLoadHtaccess        1
}


extprocessor ols-admin-adapter {
    type                    proxy
    address                 127.0.0.1:7081
    maxConns                20
    initTimeout             60
    retryTimeout            0
    respBuffer              0
}

context /phpmyadmin/ {
    location                /usr/local/everlomp/phpmyadmin/
    allowBrowse             1
    indexFiles              index.php
    addDefaultCharset       off

    accessControl {
        allow                   *
    }

    rewrite {
        enable              0
    }
}


context /openlitespeed/ {
    type                    proxy
    handler                 ols-admin-adapter
    addDefaultCharset       off
}

context /kopia/ {
    type                    proxy
    handler                 ols-admin-adapter
    addDefaultCharset       off
}

EOF

RUN cd /tmp && \
    curl -fsSL \
        https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.zip \
        -o phpMyAdmin-latest-all-languages.zip && \
    unzip -q phpMyAdmin-latest-all-languages.zip && \
    mkdir -p /usr/local/everlomp && \
    rm -rf /usr/local/everlomp/phpmyadmin && \
    mv phpMyAdmin-*-all-languages /usr/local/everlomp/phpmyadmin && \
    rm -f phpMyAdmin-latest-all-languages.zip && \
    mkdir -p /var/lib/phpmyadmin/tmp && \
    chown -R root:root /usr/local/everlomp/phpmyadmin && \
    find /usr/local/everlomp/phpmyadmin -type d -exec chmod 0755 {} \; && \
    find /usr/local/everlomp/phpmyadmin -type f -exec chmod 0644 {} \; && \
    chown -R nobody:nogroup /var/lib/phpmyadmin && \
    chmod 0750 /var/lib/phpmyadmin && \
    chmod 0700 /var/lib/phpmyadmin/tmp


COPY run-mariadb.sh /usr/local/bin/run-mariadb.sh
COPY run-openlitespeed.sh /usr/local/bin/run-openlitespeed.sh

RUN chmod +x \
    /usr/local/bin/run-mariadb.sh \
    /usr/local/bin/run-openlitespeed.sh


RUN useradd -m -s /bin/bash everlomp && \
    usermod -L everlomp && \
    usermod -aG sudo everlomp && \
    chmod 0755 /home/everlomp


RUN useradd --system --no-create-home --shell /usr/sbin/nologin everlomp-build
RUN curl -fsSL \
        https://getcomposer.org/download/2.10.3/composer.phar \
        -o /usr/local/bin/composer.phar && \
    echo '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6  /usr/local/bin/composer.phar' | sha256sum -c - && \
    chmod 0755 /usr/local/bin/composer.phar && \
    printf '#!/bin/bash\nexec /usr/local/lsws/lsphp85/bin/php /usr/local/bin/composer.phar "$@"\n' \
        > /usr/local/bin/composer && \
    chmod 0755 /usr/local/bin/composer && \
    /usr/local/bin/composer --version
COPY everlomp/everlomp-drupal-git /usr/local/sbin/everlomp-drupal-git
RUN chmod 0755 /usr/local/sbin/everlomp-drupal-git
RUN printf '%s\n' \
    'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-drupal-git' \
    'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-drupal-git' \
    'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-drupal-git' \
    > /etc/sudoers.d/everlomp-drupal-git && \
    chmod 0440 /etc/sudoers.d/everlomp-drupal-git && \
    visudo -cf /etc/sudoers.d/everlomp-drupal-git

    
RUN curl -fsSL \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
        -o /usr/local/bin/wp-cli.phar && \
    chmod 0755 /usr/local/bin/wp-cli.phar && \
    printf '#!/bin/bash\nexec /usr/local/lsws/lsphp85/bin/php /usr/local/bin/wp-cli.phar "$@"\n' \
        > /usr/local/bin/wp && \
    chmod 0755 /usr/local/bin/wp && \
    /usr/local/bin/wp --allow-root --info


RUN mkdir -p \
        /var/www/html \
        /home/everlomp && \
    chmod 0755 /home/everlomp

COPY everlomp/index.php /var/www/html/index.php
COPY everlomp/lompinstaller.php /var/www/html/lompinstaller.php

COPY everlomp/terms.md /home/everlomp/terms.md
COPY everlomp/everlomp-drupal /usr/local/sbin/everlomp-drupal
COPY everlomp/everlomp-drupal-runner.php /usr/local/lib/everlomp-drupal-runner.php
COPY everlomp/drupal-11.4.5.tar.gz /home/everlomp/drupal-11.4.5.tar.gz
COPY everlomp/everlomp-database /usr/local/sbin/everlomp-database
COPY everlomp/everlomp-wordpress /usr/local/sbin/everlomp-wordpress
COPY everlomp/everlomp-phpbb /usr/local/sbin/everlomp-phpbb
COPY everlomp/everlomp-external-installer /usr/local/sbin/everlomp-external-installer
COPY everlomp/everlomp-bruteforce-guard /usr/local/sbin/everlomp-bruteforce-guard
COPY everlomp/everlomp-delete-installfile /usr/local/sbin/everlomp-delete-installfile
COPY everlomp/everlomp-realip /usr/local/sbin/everlomp-realip
COPY everlomp/everlomp-hotpocket /usr/local/sbin/everlomp-hotpocket
COPY everlomp/everlomp-ssh /usr/local/sbin/everlomp-ssh
COPY everlomp/everlomp-filegator /usr/local/sbin/everlomp-filegator
COPY everlomp/everlomp-backup /usr/local/sbin/everlomp-backup
COPY everlomp/everlomp-sql-backup /usr/local/sbin/everlomp-sql-backup
COPY everlomp/everlomp-kopia-update /usr/local/sbin/everlomp-kopia-update
COPY everlomp/everlomp-kopia-replication /usr/local/sbin/everlomp-kopia-replication
COPY everlomp/everlomp-backup-scheduler /usr/local/sbin/everlomp-backup-scheduler
COPY everlomp/run-kopia.sh /usr/local/bin/run-kopia.sh
COPY everlomp/everlomp-backup.cron /etc/cron.d/everlomp-backup

COPY everlomp/phpBB-3.3.17.zip /home/everlomp/phpBB-3.3.17.zip
COPY everlomp/wordpress-6.6.7.zip /home/everlomp/wordpress-6.6.7.zip
COPY everlomp/wpaddons /home/everlomp/wpaddons
COPY everlomp/filegator_local.zip /home/everlomp/filegator_local.zip
COPY everlomp/external-installer-example.zip /home/everlomp/external-installer-example.zip
COPY everlomp/everlomp-lsws-password /usr/local/sbin/everlomp-lsws-password
COPY everlomp/everlomp-key /usr/local/sbin/everlomp-key
COPY everlomp/everlomp-secret /usr/local/sbin/everlomp-secret
COPY everlomp/everlomp-kopia-priv /usr/local/sbin/everlomp-kopia-priv
COPY everlomp/hotpocket-contracts /home/everlomp/hotpocket-contracts

RUN chown everlomp:everlomp \
        /home/everlomp/phpBB-3.3.17.zip \
        /home/everlomp/wordpress-6.6.7.zip \
        /home/everlomp/filegator_local.zip \
        /home/everlomp/external-installer-example.zip \
        /home/everlomp/drupal-11.4.5.tar.gz \
        /home/everlomp/terms.md && \
    chown -R everlomp:everlomp /home/everlomp/wpaddons && \
    chmod 0644 \
        /home/everlomp/phpBB-3.3.17.zip \
        /home/everlomp/wordpress-6.6.7.zip \
        /home/everlomp/filegator_local.zip \
        /home/everlomp/external-installer-example.zip \
        /home/everlomp/terms.md && \
    find /home/everlomp/wpaddons -type d -exec chmod 0755 {} \; && \
    find /home/everlomp/wpaddons -type f -exec chmod 0644 {} \; && \
    chmod 0755 \
        /usr/local/bin/run-kopia.sh \
        /usr/local/sbin/everlomp-backup \
        /usr/local/sbin/everlomp-sql-backup \
        /usr/local/sbin/everlomp-kopia-update \
        /usr/local/sbin/everlomp-kopia-replication \
        /usr/local/sbin/everlomp-backup-scheduler \
        /usr/local/sbin/everlomp-key \
        /usr/local/sbin/everlomp-secret \
        /usr/local/sbin/everlomp-drupal \
        /usr/local/sbin/everlomp-kopia-priv && \
    chmod 0644 /etc/cron.d/everlomp-backup && \
    install -d -o everlomp -g everlomp -m 0700 /home/everlomp/kopia && \
    mkdir -p /home/everlomp/kopiasnapshots /home/everlomp/everbackups/sql /home/everlomp/external-installs /home/everlomp/secrets/kopia/replication /var/log/everlomp && \
    chown -R root:root /home/everlomp/secrets && \
    find /home/everlomp/secrets -type d -exec chmod 0700 {} \; && \
    chmod 0700 /home/everlomp/kopiasnapshots /home/everlomp/everbackups /home/everlomp/everbackups/sql && \
    chmod 0755 /home/everlomp/external-installs


RUN chmod 0755 \
        /usr/local/sbin/everlomp-database \
        /usr/local/sbin/everlomp-wordpress \
        /usr/local/sbin/everlomp-phpbb \
        /usr/local/sbin/everlomp-external-installer \
        /usr/local/sbin/everlomp-bruteforce-guard \
        /usr/local/sbin/everlomp-delete-installfile \
        /usr/local/sbin/everlomp-realip \
        /usr/local/sbin/everlomp-hotpocket \
        /usr/local/sbin/everlomp-ssh \
        /usr/local/sbin/everlomp-filegator \
        /usr/local/sbin/everlomp-backup \
        /usr/local/sbin/everlomp-lsws-password && \
    chmod 0644 \
        /var/www/html/index.php \
        /usr/local/lib/everlomp-drupal-runner.php \
        /var/www/html/lompinstaller.php && \
   printf '%s\n' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-database' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-wordpress' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-phpbb' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-external-installer' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-delete-installfile' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-realip' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-filegator' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-backup' \
        'nobody ALL=(everlomp) NOPASSWD: /usr/local/sbin/everlomp-kopia-replication' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-lsws-password' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-key' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-database' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-wordpress' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-phpbb' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-external-installer' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-delete-installfile' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-realip' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-filegator' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-backup' \
        'www-data ALL=(everlomp) NOPASSWD: /usr/local/sbin/everlomp-kopia-replication' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-lsws-password' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-key' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-database' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-wordpress' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-phpbb' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-external-installer' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-delete-installfile' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-realip' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-filegator' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-backup' \
        'lsadm ALL=(everlomp) NOPASSWD: /usr/local/sbin/everlomp-kopia-replication' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-lsws-password' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-key' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-hotpocket' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-ssh' \
        '%nogroup ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-key' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-hotpocket' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-hotpocket' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-ssh' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-ssh' \
        'nobody ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-drupal' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-drupal' \
        'lsadm ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-drupal' \
        > /etc/sudoers.d/everlomp-web && \
    chmod 0440 /etc/sudoers.d/everlomp-web && \
    visudo -cf /etc/sudoers.d/everlomp-web && \
    printf '%s\n' \
        'everlomp ALL=(root) NOPASSWD: /usr/local/sbin/everlomp-kopia-priv' \
        > /etc/sudoers.d/everlomp-kopia-replication && \
    chmod 0440 /etc/sudoers.d/everlomp-kopia-replication && \
    visudo -cf /etc/sudoers.d/everlomp-kopia-replication

RUN mkdir -p /var/run/sshd /etc/ssh/sshd_config.d && \
    printf '%s\n' \
        'Port 22' \
        'PermitRootLogin no' \
        'PasswordAuthentication yes' \
        'KbdInteractiveAuthentication no' \
        'PermitEmptyPasswords no' \
        'AllowUsers everlomp' \
        'ListenAddress 0.0.0.0' \
        > /etc/ssh/sshd_config.d/00-everlomp.conf && \
    chmod 0644 /etc/ssh/sshd_config.d/00-everlomp.conf

COPY start.sh /start.sh
COPY supervisord.conf /etc/everlomp/supervisord.conf

RUN chmod +x /start.sh

WORKDIR /home/everlomp

ENTRYPOINT ["/start.sh"]
