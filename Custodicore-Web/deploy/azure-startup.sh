#!/usr/bin/env bash

set -eu

storage_root="/home/custodicore/storage"
nginx_config="/home/site/wwwroot/default"

mkdir -p \
    "$storage_root/app/private" \
    "$storage_root/app/public" \
    "$storage_root/framework/cache/data" \
    "$storage_root/framework/sessions" \
    "$storage_root/framework/views" \
    "$storage_root/logs"

chown -R www-data:www-data "$storage_root"
find "$storage_root" -type d -exec chmod 775 {} \;
find "$storage_root" -type f -exec chmod 640 {} \;

if [ ! -f "$nginx_config" ]; then
    printf 'Error: Nginx configuration not found: %s\n' "$nginx_config" >&2
    exit 1
fi

cp "$nginx_config" /etc/nginx/sites-available/default
nginx -t
service nginx reload
