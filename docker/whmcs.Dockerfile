# WHMCS 9 sandbox runtime: PHP-FPM 8.3 + ionCube Loader + the PHP extensions WHMCS lists.
# NOT BUILT YET (no WHMCS licence / release archive available when this file was written).
#
# Requirements verified on 2026-09-14 against
# https://docs.whmcs.com/9-0/installation-guide/system-requirements/ (page "Last modified: 2026 August 4"):
#   PHP 8.2 or 8.3; ionCube Loader 14.4.0 or higher for PHP 8.3.
#   Required extensions: default compiled ones, cURL with SSL, GD2, IMAP, ionCube, JSON, PDO,
#   PDO_MySQL (mysqlnd), Reflection, XML.
#   Recommended: BC Math, Fileinfo, GMP, iconv, Intl, mbstring, OpenSSL, SOAP. Memory limit 64 MB (128 MB recommended).
# The official php image already compiles in curl, json, pdo, mysqlnd, reflection, xml, fileinfo, iconv,
# mbstring and openssl; the RUN below adds the rest (+ zip).
#
# Base image: Debian bookworm, not Alpine. ionCube ships no musl loader for aarch64 (Apple Silicon hosts),
# and the IMAP extension needs libc-client, which bookworm still packages (trixie dropped it).
FROM php:8.3.33-fpm-bookworm

ARG TARGETARCH

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        curl ca-certificates \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libc-client-dev libkrb5-dev \
        libicu-dev libgmp-dev libxml2-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-configure imap --with-kerberos --with-imap-ssl; \
    docker-php-ext-install -j"$(nproc)" gd imap pdo_mysql bcmath gmp intl soap zip; \
    rm -rf /var/lib/apt/lists/*

# ionCube Loader. The download URL always serves the current release (15.5.0 on 2026-09-14, which is >= 14.4.0
# and ships ioncube_loader_lin_8.3.so for both x86-64 and aarch64). The version check below (sort -V: minimum first)
# fails the build if the loader is missing or older than the WHMCS minimum 14.4.0; any newer major passes.
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) ic_arch=x86-64 ;; \
        arm64) ic_arch=aarch64 ;; \
        *) echo "unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_${ic_arch}.tar.gz" -o /tmp/ioncube.tar.gz; \
    tar -xzf /tmp/ioncube.tar.gz -C /tmp; \
    ext_dir="$(php -r 'echo ini_get("extension_dir");')"; \
    cp /tmp/ioncube/ioncube_loader_lin_8.3.so "$ext_dir/"; \
    echo "zend_extension=ioncube_loader_lin_8.3.so" > /usr/local/etc/php/conf.d/00-ioncube.ini; \
    rm -rf /tmp/ioncube /tmp/ioncube.tar.gz; \
    ic_version="$(php -v | sed -n 's/.*ionCube PHP Loader v\([0-9][0-9.]*\).*/\1/p')"; \
    [ -n "$ic_version" ]; \
    printf '%s\n' 14.4.0 "$ic_version" | sort -V -C; \
    for ext in curl gd imap json pdo pdo_mysql Reflection xml bcmath fileinfo gmp iconv intl mbstring openssl soap zip; do \
        php -m | grep -qix "$ext" || { echo "missing PHP extension: $ext" >&2; exit 1; }; \
    done

# Sandbox PHP settings. Mail: configure WHMCS SMTP to host "mailpit", port 1025, no auth (see sandbox/README.md).
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 32M'; \
        echo 'max_execution_time = 120'; \
        echo 'date.timezone = UTC'; \
        echo 'register_argc_argv = On'; \
    } > /usr/local/etc/php/conf.d/zz-whmcs-sandbox.ini

WORKDIR /var/www/html
