#!/usr/bin/env bash
#
# Puts the latest code from GitHub live on the Droplet. Push from the laptop
# first, then on the Droplet run as root:
#   bash /var/www/custodicore/deploy/update.sh

set -euo pipefail

APP_ROOT="/var/www/custodicore"
WEB_DIR="$APP_ROOT/Custodicore-Web"
PHP_VERSION="8.3"

as_web() { sudo -u www-data -H "$@"; }

[ "$(id -u)" -eq 0 ] || { echo "Please run as root."; exit 1; }

cd "$WEB_DIR"
as_web php artisan down --retry=30
# Bring the site back up even if a step below fails.
trap 'as_web php artisan up' EXIT

as_web git -C "$APP_ROOT" pull --ff-only
as_web composer install --no-dev --optimize-autoloader --no-interaction
as_web npm ci
as_web npm run build
as_web php artisan migrate --force
as_web php artisan optimize
systemctl reload php$PHP_VERSION-fpm

echo "Updated to: $(as_web git -C "$APP_ROOT" log --oneline -1)"
