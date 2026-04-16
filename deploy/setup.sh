#!/usr/bin/env bash
# =============================================================================
# Intake — One-time server setup script
# Run once on a fresh Ubuntu 24.04 LTS DigitalOcean Droplet.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/YOUR_ORG/intake-license/main/deploy/setup.sh | \
#     DOMAIN=intake.works \
#     REPO=git@github.com:YOUR_ORG/intake-license.git \
#     DB_PASSWORD=REPLACE_ME \
#     APP_KEY=REPLACE_ME \
#     CF_API_TOKEN=REPLACE_ME \
#     bash
#
# Or clone the repo first and run:
#   DOMAIN=intake.works DB_PASSWORD=... bash deploy/setup.sh
#
# Required env vars:
#   DOMAIN        — your root domain (e.g. intake.works)
#   REPO          — git remote URL
#   DB_PASSWORD   — strong password for the intake MySQL user
#   APP_KEY       — Laravel app key (php artisan key:generate --show)
#   CF_API_TOKEN  — Cloudflare API token for wildcard SSL auto-renewal
#
# Optional:
#   DEPLOY_USER   — system user to own the app (default: deployer)
#   APP_ENV       — laravel environment (default: production)
#   DB_NAME       — database name (default: intake)
#   DB_USER       — database user (default: intake)
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
DOMAIN="${DOMAIN:?DOMAIN is required}"
REPO="${REPO:?REPO is required}"
DB_PASSWORD="${DB_PASSWORD:?DB_PASSWORD is required}"
APP_KEY="${APP_KEY:?APP_KEY is required}"
CF_API_TOKEN="${CF_API_TOKEN:?CF_API_TOKEN is required}"
DEPLOY_USER="${DEPLOY_USER:-deployer}"
APP_ENV="${APP_ENV:-production}"
DB_NAME="${DB_NAME:-intake}"
DB_USER="${DB_USER:-intake}"
APP_DIR="/var/www/intake"
PHP_VERSION="8.3"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RESET='\033[0m'
log()  { echo -e "${GREEN}[SETUP]${RESET} $*"; }
warn() { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
die()  { echo -e "${RED}[ERROR]${RESET} $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root (sudo bash setup.sh)"

log "Starting Intake server setup for ${DOMAIN}..."

# ---------------------------------------------------------------------------
# 1. System packages
# ---------------------------------------------------------------------------
log "Installing system packages..."

add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
apt-get update -qq

apt-get install -y -qq \
  nginx \
  "php${PHP_VERSION}-fpm" \
  "php${PHP_VERSION}-cli" \
  "php${PHP_VERSION}-mysql" \
  "php${PHP_VERSION}-redis" \
  "php${PHP_VERSION}-curl" \
  "php${PHP_VERSION}-mbstring" \
  "php${PHP_VERSION}-xml" \
  "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-intl" \
  "php${PHP_VERSION}-bcmath" \
  "php${PHP_VERSION}-tokenizer" \
  mysql-server \
  redis-server \
  supervisor \
  certbot \
  python3-certbot-dns-cloudflare \
  unzip \
  git \
  curl \
  acl

# Composer
if ! command -v composer &> /dev/null; then
  log "Installing Composer..."
  curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "System packages installed."

# ---------------------------------------------------------------------------
# 2. Create deploy user
# ---------------------------------------------------------------------------
if ! id "${DEPLOY_USER}" &>/dev/null; then
  log "Creating deploy user: ${DEPLOY_USER}..."
  useradd -m -s /bin/bash "${DEPLOY_USER}"
  mkdir -p "/home/${DEPLOY_USER}/.ssh"
  # Copy root's authorized_keys so the same SSH key works
  cp /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys" 2>/dev/null || true
  chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh"
  chmod 700 "/home/${DEPLOY_USER}/.ssh"
  chmod 600 "/home/${DEPLOY_USER}/.ssh/authorized_keys"
fi

# www-data needs to be in the deploy group for shared directory access
usermod -aG "${DEPLOY_USER}" www-data
usermod -aG www-data "${DEPLOY_USER}"

log "Deploy user ready."

# ---------------------------------------------------------------------------
# 3. MySQL
# ---------------------------------------------------------------------------
log "Configuring MySQL..."

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

log "MySQL configured."

# ---------------------------------------------------------------------------
# 4. Redis — enable auth
# ---------------------------------------------------------------------------
log "Configuring Redis..."
REDIS_PASSWORD=$(openssl rand -base64 32)
sed -i "s/^# requirepass .*/requirepass ${REDIS_PASSWORD}/" /etc/redis/redis.conf
sed -i "s/^requirepass .*/requirepass ${REDIS_PASSWORD}/" /etc/redis/redis.conf
systemctl restart redis-server
log "Redis configured."

# ---------------------------------------------------------------------------
# 5. PHP-FPM tuning
# ---------------------------------------------------------------------------
log "Tuning PHP-FPM..."
PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"
sed -i 's/^memory_limit = .*/memory_limit = 256M/'     "${PHP_INI}"
sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 10M/' "${PHP_INI}"
sed -i 's/^post_max_size = .*/post_max_size = 10M/'     "${PHP_INI}"
sed -i 's/^max_execution_time = .*/max_execution_time = 60/' "${PHP_INI}"
systemctl restart "php${PHP_VERSION}-fpm"
log "PHP-FPM tuned."

# ---------------------------------------------------------------------------
# 6. Clone / pull app
# ---------------------------------------------------------------------------
log "Deploying application..."

if [[ -d "${APP_DIR}/.git" ]]; then
  warn "App directory exists — pulling latest..."
  sudo -u "${DEPLOY_USER}" git -C "${APP_DIR}" pull
else
  sudo -u "${DEPLOY_USER}" git clone "${REPO}" "${APP_DIR}"
fi

chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${APP_DIR}"
setfacl -R -m u:www-data:rX "${APP_DIR}"
setfacl -R -m u:www-data:rwX "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

# ---------------------------------------------------------------------------
# 7. Laravel .env
# ---------------------------------------------------------------------------
log "Writing .env..."

cat > "${APP_DIR}/.env" <<ENV
APP_NAME="Intake"
APP_ENV=${APP_ENV}
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMAIN}
APP_DOMAIN=${DOMAIN}

LOG_CHANNEL=daily
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@${DOMAIN}"
MAIL_FROM_NAME="Intake"

FILAMENT_PATH=admin
ENV

chown "${DEPLOY_USER}:${DEPLOY_USER}" "${APP_DIR}/.env"
chmod 640 "${APP_DIR}/.env"

# ---------------------------------------------------------------------------
# 8. Composer + artisan
# ---------------------------------------------------------------------------
log "Running Composer install..."
sudo -u "${DEPLOY_USER}" composer install \
  --no-dev \
  --optimize-autoloader \
  --no-interaction \
  --working-dir="${APP_DIR}"

log "Running migrations..."
sudo -u "${DEPLOY_USER}" php "${APP_DIR}/artisan" migrate --force

log "Seeding config cache..."
sudo -u "${DEPLOY_USER}" php "${APP_DIR}/artisan" config:cache
sudo -u "${DEPLOY_USER}" php "${APP_DIR}/artisan" route:cache
sudo -u "${DEPLOY_USER}" php "${APP_DIR}/artisan" view:cache
sudo -u "${DEPLOY_USER}" php "${APP_DIR}/artisan" storage:link

log "Laravel configured."

# ---------------------------------------------------------------------------
# 9. Nginx
# ---------------------------------------------------------------------------
log "Configuring Nginx..."

cat > /etc/nginx/sites-available/intake <<NGINX
# Redirect all HTTP → HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} *.${DOMAIN};
    return 301 https://\$host\$request_uri;
}

# Main HTTPS server — handles ALL subdomains via wildcard
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${DOMAIN} *.${DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    root ${APP_DIR}/public;
    index index.php;

    # Max upload size
    client_max_body_size 10M;

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml;

    # Static assets — long cache
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2|woff)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;

        # Pass subdomain to Laravel for tenant resolution
        fastcgi_param HTTP_HOST \$http_host;
        fastcgi_param SERVER_NAME \$server_name;
    }

    location ~ /\.ht  { deny all; }
    location ~ /\.git { deny all; }
    location ~ /\.env { deny all; }
}
NGINX

# Remove default site and enable ours
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/intake /etc/nginx/sites-enabled/intake

nginx -t
systemctl reload nginx
log "Nginx configured (HTTP only until cert is issued)."

# ---------------------------------------------------------------------------
# 10. Wildcard SSL via Cloudflare DNS challenge
# ---------------------------------------------------------------------------
log "Setting up Cloudflare DNS credentials for certbot..."

mkdir -p /root/.secrets/certbot
cat > /root/.secrets/certbot/cloudflare.ini <<CF
dns_cloudflare_api_token = ${CF_API_TOKEN}
CF
chmod 600 /root/.secrets/certbot/cloudflare.ini

log "Requesting wildcard SSL certificate..."
certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials /root/.secrets/certbot/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d "${DOMAIN}" \
  -d "*.${DOMAIN}" \
  --non-interactive \
  --agree-tos \
  --email "hello@${DOMAIN}"

log "SSL certificate issued."

# Auto-renew via cron
echo "0 3 * * * root certbot renew --quiet --post-hook 'systemctl reload nginx'" \
  > /etc/cron.d/certbot-renew

nginx -t && systemctl reload nginx
log "Nginx reloaded with SSL."

# ---------------------------------------------------------------------------
# 11. Supervisor — queue worker
# ---------------------------------------------------------------------------
log "Configuring Supervisor queue worker..."

cat > /etc/supervisor/conf.d/intake-worker.conf <<SUPERVISOR
[program:intake-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_DIR}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --queue=default,emails
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=${DEPLOY_USER}
numprocs=2
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
SUPERVISOR

cat > /etc/supervisor/conf.d/intake-scheduler.conf <<SUPERVISOR
[program:intake-scheduler]
command=php ${APP_DIR}/artisan schedule:work
autostart=true
autorestart=true
user=${DEPLOY_USER}
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/scheduler.log
SUPERVISOR

supervisorctl reread
supervisorctl update
supervisorctl start "intake-worker:*"
supervisorctl start intake-scheduler
log "Supervisor configured."

# ---------------------------------------------------------------------------
# 12. Firewall (UFW)
# ---------------------------------------------------------------------------
log "Configuring firewall..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
log "Firewall configured."

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
echo ""
echo -e "${GREEN}======================================================${RESET}"
echo -e "${GREEN}  Intake is live at https://${DOMAIN}${RESET}"
echo -e "${GREEN}======================================================${RESET}"
echo ""
echo "  App directory:  ${APP_DIR}"
echo "  Deploy user:    ${DEPLOY_USER}"
echo "  Database:       ${DB_NAME} (user: ${DB_USER})"
echo "  Redis password: ${REDIS_PASSWORD}"
echo ""
echo "  Next steps:"
echo "  1. Add REDIS_PASSWORD above to ${APP_DIR}/.env"
echo "  2. Set MAIL_MAILER + credentials in ${APP_DIR}/.env"
echo "  3. Add your DEPLOY_SSH_KEY to GitHub → Settings → Secrets"
echo "  4. Push to main and the GitHub Action will handle future deploys"
echo ""
warn "Save the Redis password printed above — it's not stored anywhere else."
