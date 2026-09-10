#!/usr/bin/env bash
# Installs the Microsoft SQL Server driver for PHP (sqlsrv + pdo_sqlsrv) on the
# NOC VM, so biotime:sync can read ZKTeco BioTime's database.
#
#   sudo bash deployment/biotime/install-sqlsrv.sh
#
# Ubuntu 24.04 + PHP 8.3 (override with PHP_VER=8.x). Safe to re-run.
# See BIOTIME_ATTENDANCE_SETUP.md.
set -euo pipefail

PHP_VER="${PHP_VER:-8.3}"

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo." >&2
    exit 1
fi

# shellcheck disable=SC1091
. /etc/os-release

echo "==> Microsoft package repository (Ubuntu ${VERSION_ID})"
if ! dpkg -s packages-microsoft-prod >/dev/null 2>&1; then
    tmp="$(mktemp -d)"
    curl -fsSL -o "${tmp}/packages-microsoft-prod.deb" \
        "https://packages.microsoft.com/config/ubuntu/${VERSION_ID}/packages-microsoft-prod.deb"
    dpkg -i "${tmp}/packages-microsoft-prod.deb"
    rm -rf "${tmp}"
fi
apt-get update

echo "==> ODBC Driver 18 for SQL Server"
ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

echo "==> Build tools for PECL"
apt-get install -y "php${PHP_VER}-dev" php-pear build-essential

for ext in sqlsrv pdo_sqlsrv; do
    if pecl list | grep -q "^${ext} "; then
        echo "==> ${ext} already installed by PECL"
    else
        echo "==> pecl install ${ext}"
        pecl install "${ext}"
    fi
done

echo "==> Enabling for PHP ${PHP_VER} (fpm + cli)"
# pdo_sqlsrv needs PDO loaded first, hence the priorities.
printf '; priority=20\nextension=sqlsrv.so\n' > "/etc/php/${PHP_VER}/mods-available/sqlsrv.ini"
printf '; priority=30\nextension=pdo_sqlsrv.so\n' > "/etc/php/${PHP_VER}/mods-available/pdo_sqlsrv.ini"
phpenmod -v "${PHP_VER}" sqlsrv pdo_sqlsrv

if systemctl is-active --quiet "php${PHP_VER}-fpm"; then
    systemctl restart "php${PHP_VER}-fpm"
fi

echo
if php -m | grep -qx pdo_sqlsrv; then
    echo "OK: pdo_sqlsrv is loaded in the CLI."
    echo "Next: add each BioTime database at /admin/attendance/sources and press Test connection,"
    echo "      or run: php artisan biotime:test"
else
    echo "pdo_sqlsrv did not load — check: php --ini, and /etc/php/${PHP_VER}/cli/conf.d/" >&2
    exit 1
fi
