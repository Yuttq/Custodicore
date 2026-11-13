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

# Laravel scheduler (routes/console.php: visits:expire daily at 00:05).
# App Service has no cron, so `schedule:work` runs in the background for the
# life of the container and runs due tasks every minute. It runs as www-data
# so the cache and log files it writes stay readable by the web app. A
# restart starts a new container, so there is never a second copy.
app_root="/home/site/wwwroot"
scheduler_log="$storage_root/logs/scheduler.log"

if [ -f "$app_root/artisan" ]; then
    touch "$scheduler_log"
    chown www-data:www-data "$scheduler_log"
    nohup su -s /bin/sh www-data -c \
        "cd '$app_root' && exec php artisan schedule:work" \
        >> "$scheduler_log" 2>&1 &
else
    printf 'Warning: %s not found; the scheduler was not started.\n' "$app_root/artisan" >&2
fi
