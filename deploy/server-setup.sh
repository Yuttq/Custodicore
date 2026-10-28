#!/usr/bin/env bash
#
# One-time setup of a fresh Ubuntu 24.04 DigitalOcean Droplet for the
# CustodiCore demo. Installs Nginx, PHP 8.3, MariaDB and Node, downloads the
# app from GitHub, creates the database, seeds DEMO data, and turns on HTTPS.
#
# Run as root on the Droplet:
#   bash server-setup.sh              # uses <droplet-ip>.sslip.io as the address
#   bash server-setup.sh example.com  # or your own domain (point it at the IP first)
#
# Safe to run again: it keeps existing passwords, .env and data.
# Generated passwords are saved in /root/custodicore-credentials.txt.

set -euo pipefail

REPO_URL="https://github.com/Yuttq/Custodicore.git"
APP_ROOT="/var/www/custodicore"
WEB_DIR="$APP_ROOT/Custodicore-Web"
DB_NAME="custodicore"
DB_USER="custodicore"
CREDS_FILE="/root/custodicore-credentials.txt"
PHP_VERSION="8.3"

step() { echo; echo "==> $*"; }
as_web() { sudo -u www-data -H "$@"; }

[ "$(id -u)" -eq 0 ] || { echo "Please run as root."; exit 1; }

IP=$(curl -s --max-time 3 http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address || true)
[ -n "$IP" ] || IP=$(hostname -I | awk '{print $1}')
DOMAIN="${1:-$IP.sslip.io}"

# --- Passwords (reused on re-runs) -----------------------------------------
if [ -f "$CREDS_FILE" ]; then
    # shellcheck disable=SC1090
    source "$CREDS_FILE"
else
    DB_PASSWORD=$(openssl rand -hex 16)
    SEED_PASSWORD="Demo-$(openssl rand -hex 6)"
    umask 077
    cat > "$CREDS_FILE" <<EOF
DB_PASSWORD=$DB_PASSWORD
SEED_PASSWORD=$SEED_PASSWORD
EOF
    umask 022
fi

# --- System packages --------------------------------------------------------
step "Updating Ubuntu and installing packages (takes a few minutes)"
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
apt-get update -q
apt-get upgrade -yq
apt-get install -yq nginx mariadb-server git unzip curl ufw cron \
    certbot python3-certbot-nginx nodejs npm composer \
    php$PHP_VERSION-fpm php$PHP_VERSION-cli php$PHP_VERSION-mysql \
    php$PHP_VERSION-mbstring php$PHP_VERSION-xml php$PHP_VERSION-curl \
    php$PHP_VERSION-zip php$PHP_VERSION-bcmath php$PHP_VERSION-intl php$PHP_VERSION-gd

# 2 GB of RAM is tight while composer/npm run; swap stops them crashing.
if ! swapon --show | grep -q .; then
    step "Adding 2 GB swap"
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

step "Turning on the firewall (SSH + web only)"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# Mobile registration uploads several documents (up to 10 MB each) at once.
step "Raising PHP upload limits"
cat > /etc/php/$PHP_VERSION/fpm/conf.d/99-custodicore.ini <<EOF
upload_max_filesize = 12M
post_max_size = 64M
expose_php = Off
EOF

# --- Database ---------------------------------------------------------------
step "Creating the database"
mysql <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

# --- App code ---------------------------------------------------------------
step "Downloading CustodiCore from GitHub"
mkdir -p "$APP_ROOT"
chown www-data:www-data "$APP_ROOT" /var/www
if [ -d "$APP_ROOT/.git" ]; then
    as_web git -C "$APP_ROOT" pull --ff-only
else
    as_web git clone "$REPO_URL" "$APP_ROOT"
fi

FIRST_RUN=0
if [ ! -f "$WEB_DIR/.env" ]; then
    FIRST_RUN=1
    step "Writing .env"
    as_web tee "$WEB_DIR/.env" > /dev/null <<EOF
APP_NAME=CustodiCore
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=Asia/Manila
APP_URL=http://$DOMAIN

SEED_PASSWORD=$SEED_PASSWORD

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SANCTUM_EXPIRATION=43200
CACHE_STORE=database
QUEUE_CONNECTION=sync

MAIL_MAILER=log
STAFF_INVITATION_EXPIRE_HOURS=72
SMS_DRIVER=log
SEMAPHORE_API_KEY=
SEMAPHORE_SENDER_NAME=
GOOGLE_CLIENT_IDS=
LEGAL_DPO_CONTACT=
EOF
    chmod 640 "$WEB_DIR/.env"
fi

step "Installing PHP and JavaScript libraries, building the website"
cd "$WEB_DIR"
as_web composer install --no-dev --optimize-autoloader --no-interaction
as_web npm ci
as_web npm run build
if [ "$FIRST_RUN" -eq 1 ]; then
    as_web php artisan key:generate --force
fi

step "Setting up database tables"
as_web php artisan migrate --force
if [ "$FIRST_RUN" -eq 1 ]; then
    step "Adding DEMO data (fake people only)"
    as_web php artisan db:seed --force
fi

# --- Web server -------------------------------------------------------------
step "Configuring Nginx for $DOMAIN"
cat > /etc/nginx/sites-available/custodicore <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $WEB_DIR/public;
    index index.php;
    client_max_body_size 64M;
    server_tokens off;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
EOF
ln -sf /etc/nginx/sites-available/custodicore /etc/nginx/sites-enabled/custodicore
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
systemctl restart php$PHP_VERSION-fpm

step "Turning on HTTPS"
if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
        --register-unsafely-without-email --redirect; then
    as_web sed -i "s#^APP_URL=.*#APP_URL=https://$DOMAIN#" "$WEB_DIR/.env"
    grep -q '^SESSION_SECURE_COOKIE=' "$WEB_DIR/.env" \
        || echo 'SESSION_SECURE_COOKIE=true' | as_web tee -a "$WEB_DIR/.env" > /dev/null
    SCHEME=https
else
    echo "!! HTTPS failed - the site still works on http://$DOMAIN. Send Claude the error above."
    SCHEME=http
fi

# Expires unconfirmed visits nightly (routes/console.php).
step "Scheduling nightly tasks"
echo "* * * * * www-data cd $WEB_DIR && php artisan schedule:run >> /dev/null 2>&1" \
    > /etc/cron.d/custodicore

as_web php artisan optimize

echo
echo "============================================================"
echo " CustodiCore is live:  $SCHEME://$DOMAIN"
echo
echo " Demo logins (fake data): a.domingo@bjmp.gov.ph (Warden),"
echo " r.salcedo@bjmp.gov.ph, n.fernandez@bjmp.gov.ph, maria.santos@example.com"
echo " Password for all of them: $SEED_PASSWORD"
echo
echo " Passwords are saved in $CREDS_FILE (root only)."
echo "============================================================"
