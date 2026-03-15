#!/usr/bin/env bash
set -euo pipefail

############################################
# File: address-validation-deploy.sh
# Purpose: First deploy + future updates
# Ubuntu 22.04
#
# Configuration: Copy deploy.conf.example to
# deploy.conf and edit with your settings.
# deploy.conf is gitignored (safe for secrets)
############################################

# Defaults (override in deploy.conf)
APP_DIR="/var/www/address-validation"
REPO_URL="https://github.com/ryan99alero/Address-Validation.git"
DEFAULT_BRANCH="master"

APP_USER="deploy"
APP_GROUP="www-data"
WEB_USER="www-data"
WEB_GROUP="www-data"

PHP_BIN="/usr/bin/php"
COMPOSER_BIN="/usr/local/bin/composer"
PHP_FPM_SOCK="/var/run/php/php8.4-fpm.sock"

NGINX_SITE="/etc/nginx/sites-available/address-validation"
NGINX_LINK="/etc/nginx/sites-enabled/address-validation"
SUPERVISOR_CONF="/etc/supervisor/conf.d/address-validation-worker.conf"

DOMAIN="your-domain.com"
WWW_DOMAIN="www.your-domain.com"

DB_CONNECTION="mysql"
DB_HOST="localhost"
DB_PORT="3306"
DB_DATABASE="address_validation"
DB_USERNAME="address_validation"
DB_PASSWORD=""
QUEUE_CONNECTION="database"

# Load local config (overrides defaults above)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/deploy.conf" ]; then
    echo "Loading config from ${SCRIPT_DIR}/deploy.conf"
    source "${SCRIPT_DIR}/deploy.conf"
elif [ -f "${APP_DIR}/deploy.conf" ]; then
    echo "Loading config from ${APP_DIR}/deploy.conf"
    source "${APP_DIR}/deploy.conf"
else
    echo "WARNING: No deploy.conf found. Copy deploy.conf.example to deploy.conf and configure it."
    echo "Press Enter to continue with defaults, or Ctrl+C to abort..."
    read -r
fi

# Validate required settings
if [ -z "${DB_PASSWORD}" ]; then
    echo "ERROR: DB_PASSWORD is not set. Configure it in deploy.conf"
    exit 1
fi

log() {
    echo ""
    echo "=== $1 ==="
}

fail() {
    echo "ERROR: $1" >&2
    exit 1
}

run_as_web() {
    sudo -u "${WEB_USER}" "$@"
}

ensure_command() {
    local cmd="$1"
    command -v "$cmd" >/dev/null 2>&1 || fail "Missing required command: $cmd"
}

set_env_value() {
    local key="$1"
    local value="$2"
    local env_file="${APP_DIR}/.env"

    if grep -q "^${key}=" "${env_file}" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${value}|g" "${env_file}"
    else
        echo "${key}=${value}" >> "${env_file}"
    fi
}

set_env_quoted_value() {
    local key="$1"
    local value="$2"
    local env_file="${APP_DIR}/.env"

    if grep -q "^${key}=" "${env_file}" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=\"${value}\"|g" "${env_file}"
    else
        echo "${key}=\"${value}\"" >> "${env_file}"
    fi
}

log "Pre-flight checks"

ensure_command git
ensure_command sudo
ensure_command "${PHP_BIN}"
ensure_command nginx
ensure_command supervisorctl
ensure_command npm

if [ ! -x "${COMPOSER_BIN}" ] && ! command -v composer >/dev/null 2>&1; then
    fail "Composer not found at ${COMPOSER_BIN} and not in PATH"
fi

if [ ! -S "${PHP_FPM_SOCK}" ]; then
    fail "PHP-FPM socket not found at ${PHP_FPM_SOCK}"
fi

log "Create application directory"
sudo mkdir -p "${APP_DIR}"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}"

log "Clone or update repository"
if [ -d "${APP_DIR}/.git" ]; then
    echo "Existing git repository found."
    cd "${APP_DIR}"
    git config --global --add safe.directory "${APP_DIR}" || true
    git fetch origin
    CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
    echo "Using branch: ${CURRENT_BRANCH}"
    git pull origin "${CURRENT_BRANCH}"
else
    if [ -n "$(ls -A "${APP_DIR}" 2>/dev/null || true)" ]; then
        fail "${APP_DIR} is not empty and is not a git repository"
    fi

    git clone --branch "${DEFAULT_BRANCH}" "${REPO_URL}" "${APP_DIR}"
    cd "${APP_DIR}"
    git config --global --add safe.directory "${APP_DIR}" || true
fi

log "Ensure .env exists"
cd "${APP_DIR}"
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
    else
        touch .env
    fi
fi

log "Write environment values"
set_env_quoted_value "APP_NAME" "Address Validation"
set_env_value "APP_ENV" "production"
set_env_value "APP_DEBUG" "false"
set_env_value "APP_URL" "https://${DOMAIN}"
set_env_value "DB_CONNECTION" "${DB_CONNECTION}"
set_env_value "DB_HOST" "${DB_HOST}"
set_env_value "DB_PORT" "${DB_PORT}"
set_env_value "DB_DATABASE" "${DB_DATABASE}"
set_env_value "DB_USERNAME" "${DB_USERNAME}"
set_env_value "DB_PASSWORD" "${DB_PASSWORD}"
set_env_value "QUEUE_CONNECTION" "${QUEUE_CONNECTION}"

log "Prepare directories and base permissions"
sudo mkdir -p "${APP_DIR}/storage/logs"
sudo mkdir -p "${APP_DIR}/storage/framework/cache"
sudo mkdir -p "${APP_DIR}/storage/framework/sessions"
sudo mkdir -p "${APP_DIR}/storage/framework/views"
sudo mkdir -p "${APP_DIR}/bootstrap/cache"

sudo touch "${APP_DIR}/storage/logs/laravel.log"
sudo touch "${APP_DIR}/storage/logs/worker.log"

sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}/storage"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}/bootstrap/cache"

sudo find "${APP_DIR}" -type d -exec chmod 755 {} \;
sudo find "${APP_DIR}" -type f -exec chmod 644 {} \;

sudo find "${APP_DIR}/storage" -type d -exec chmod 775 {} \;
sudo find "${APP_DIR}/storage" -type f -exec chmod 664 {} \;
sudo find "${APP_DIR}/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "${APP_DIR}/bootstrap/cache" -type f -exec chmod 664 {} \;

sudo chmod g+s "${APP_DIR}/storage"
sudo chmod g+s "${APP_DIR}/bootstrap/cache"

log "Generate app key if needed"
if ! grep -q '^APP_KEY=' .env || grep -q '^APP_KEY=$' .env; then
    ${PHP_BIN} artisan key:generate --force
else
    echo "APP_KEY already present."
fi

log "Install Composer dependencies"
if [ -x "${COMPOSER_BIN}" ]; then
    "${COMPOSER_BIN}" install --no-dev --optimize-autoloader
else
    composer install --no-dev --optimize-autoloader
fi

log "Install Node dependencies"
if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi

log "Build frontend assets"
npm run build

log "Publish Livewire and Filament assets"
"${PHP_BIN}" artisan livewire:publish --assets
"${PHP_BIN}" artisan filament:assets

log "Re-apply runtime ownership for Laravel"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}/public"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}/vendor" 2>/dev/null || true
sudo chown -R "${WEB_USER}:${WEB_GROUP}" "${APP_DIR}/storage"
sudo chown -R "${WEB_USER}:${WEB_GROUP}" "${APP_DIR}/bootstrap/cache"

sudo find "${APP_DIR}/storage" -type d -exec chmod 775 {} \;
sudo find "${APP_DIR}/storage" -type f -exec chmod 664 {} \;
sudo find "${APP_DIR}/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "${APP_DIR}/bootstrap/cache" -type f -exec chmod 664 {} \;

log "Clear Laravel caches"
run_as_web "${PHP_BIN}" artisan config:clear
run_as_web "${PHP_BIN}" artisan cache:clear
run_as_web "${PHP_BIN}" artisan route:clear
run_as_web "${PHP_BIN}" artisan view:clear

log "Run database migrations"
run_as_web "${PHP_BIN}" artisan migrate --force

log "Seed database (if empty)"
USER_COUNT=$(run_as_web "${PHP_BIN}" artisan tinker --execute="echo App\Models\User::count();" 2>/dev/null | tr -d '[:space:]' || echo "0")
if [ "${USER_COUNT}" = "0" ] || [ -z "${USER_COUNT}" ]; then
    echo "No users found - running seeders..."
    run_as_web "${PHP_BIN}" artisan db:seed --force
else
    echo "Users exist (${USER_COUNT}) - skipping seeders."
fi

log "Create storage link if needed"
if [ -L "${APP_DIR}/public/storage" ]; then
    echo "Storage link already exists."
elif [ -e "${APP_DIR}/public/storage" ]; then
    echo "public/storage exists but is not a symlink. Removing it."
    sudo rm -rf "${APP_DIR}/public/storage"
    sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}/public"
    "${PHP_BIN}" artisan storage:link
else
    sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}/public"
    "${PHP_BIN}" artisan storage:link
fi

log "Cache Laravel config, routes, views"
run_as_web "${PHP_BIN}" artisan config:cache
run_as_web "${PHP_BIN}" artisan route:cache
run_as_web "${PHP_BIN}" artisan view:cache
run_as_web "${PHP_BIN}" artisan optimize

log "Write Nginx config"
sudo tee "${NGINX_SITE}" >/dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} ${WWW_DOMAIN};
    root ${APP_DIR}/public;

    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    client_max_body_size 100M;
}
EOF

sudo ln -sf "${NGINX_SITE}" "${NGINX_LINK}"
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl enable nginx
sudo systemctl reload nginx

log "Write Supervisor config"
sudo tee "${SUPERVISOR_CONF}" >/dev/null <<EOF
[program:address-validation-worker]
process_name=%(program_name)s_%(process_num)02d
directory=${APP_DIR}
command=${PHP_BIN} ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=${WEB_USER}
numprocs=2
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=3600
EOF

sudo systemctl enable supervisor
sudo systemctl restart supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart address-validation-worker:* || true

log "Configure scheduler"
CRON_LINE="* * * * * cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"
if sudo crontab -u "${WEB_USER}" -l 2>/dev/null | grep -Fq "${CRON_LINE}"; then
    echo "Cron entry already exists."
else
    (
        sudo crontab -u "${WEB_USER}" -l 2>/dev/null || true
        echo "${CRON_LINE}"
    ) | sudo crontab -u "${WEB_USER}" -
fi

log "Final ownership check"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}"
sudo chown -R "${WEB_USER}:${WEB_GROUP}" "${APP_DIR}/storage"
sudo chown -R "${WEB_USER}:${WEB_GROUP}" "${APP_DIR}/bootstrap/cache"

log "Status"
echo ""
echo "Nginx:"
sudo systemctl --no-pager --full status nginx | head -15 || true

echo ""
echo "Supervisor:"
sudo supervisorctl status || true

echo ""
echo "Storage symlink:"
ls -l "${APP_DIR}/public/storage" || true

echo ""
echo "Done."
