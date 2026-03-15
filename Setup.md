# Address Validation - Server Setup Guide

Complete deployment guide for Ubuntu 22.04/24.04 LTS with MySQL.

## Table of Contents

1. [Server Requirements](#server-requirements)
2. [Install System Dependencies](#install-system-dependencies)
3. [Install PHP & Extensions](#install-php--extensions)
4. [Install MySQL](#install-mysql)
5. [Install Composer & Node.js](#install-composer--nodejs)
6. [Deploy Application](#deploy-application)
7. [Configure Nginx](#configure-nginx)
8. [Configure Supervisor (Queue Workers)](#configure-supervisor-queue-workers)
9. [SSL Certificate (Let's Encrypt)](#ssl-certificate-lets-encrypt)
10. [Maintenance & Commands](#maintenance--commands)
11. [Troubleshooting](#troubleshooting)

---

## Server Requirements

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| CPU | 2 cores | 4 cores |
| RAM | 2 GB | 4 GB |
| Disk | 20 GB | 50 GB SSD |
| OS | Ubuntu 22.04 LTS | Ubuntu 24.04 LTS |

---

## Install System Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install essential packages
sudo apt install -y \
    git \
    curl \
    wget \
    unzip \
    supervisor \
    nginx \
    software-properties-common \
    apt-transport-https \
    ca-certificates
```

---

## Install PHP & Extensions

```bash
# Add PHP repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP 8.4 with required extensions
sudo apt install -y \
    php8.4-fpm \
    php8.4-cli \
    php8.4-mysql \
    php8.4-pgsql \
    php8.4-sqlite3 \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-curl \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-gd \
    php8.4-intl \
    php8.4-redis \
    php8.4-opcache

# Verify installation
php -v
```

### Configure PHP

```bash
# Edit PHP-FPM config
sudo nano /etc/php/8.4/fpm/php.ini
```

Recommended settings:
```ini
memory_limit = 512M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
max_input_time = 300
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.4-fpm
```

---

## Install MySQL

```bash
# Install MySQL 8
sudo apt install -y mysql-server

# Secure installation
sudo mysql_secure_installation
# - Set root password
# - Remove anonymous users: Yes
# - Disallow root login remotely: Yes
# - Remove test database: Yes
# - Reload privilege tables: Yes

# Create database and user
sudo mysql -u root -p
```

```sql
CREATE DATABASE address_validation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'address_validation'@'localhost' IDENTIFIED BY 'your_secure_password_here';
GRANT ALL PRIVILEGES ON address_validation.* TO 'address_validation'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## Install Composer & Node.js

### Composer

```bash
# Install Composer globally
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### Node.js (for asset compilation)

```bash
# Install Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

---

## Deploy Application

### Clone Repository

```bash
cd /var/www

# Clone the repository (creates the directory)
git clone https://github.com/your-org/address-validation.git address-validation

# Or for private repos with deploy key:
git clone git@github.com:your-org/address-validation.git address-validation

cd address-validation

# Set ownership
sudo chown -R $USER:www-data /var/www/address-validation
```

### Install Dependencies

```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies and build assets
npm ci
npm run build
```

### Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Edit environment file
nano .env
```

Essential `.env` settings:
```env
APP_NAME="Address Validation"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=address_validation
DB_USERNAME=address_validation
DB_PASSWORD=your_secure_password_here

QUEUE_CONNECTION=database

# Carrier API credentials (configure in admin panel)
# UPS and FedEx credentials are stored encrypted in database
```

### Generate Application Key

```bash
php artisan key:generate
```

### Run Deployment Command

```bash
# Run full deployment (migrations, seeders, optimizations)
php artisan deploy:install --seed

# Or run individual steps:
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

### Set Permissions

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/address-validation
sudo chmod -R 755 /var/www/address-validation
sudo chmod -R 775 /var/www/address-validation/storage
sudo chmod -R 775 /var/www/address-validation/bootstrap/cache
```

---

## Configure Nginx

### Create Site Configuration

```bash
sudo nano /etc/nginx/sites-available/address-validation
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/address-validation/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    # Handle Laravel routes
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Deny access to hidden files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Handle PHP files
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Increase upload size for batch imports
    client_max_body_size 100M;
}
```

### Enable Site

```bash
# Enable site
sudo ln -s /etc/nginx/sites-available/address-validation /etc/nginx/sites-enabled/

# Remove default site (optional)
sudo rm /etc/nginx/sites-enabled/default

# Test configuration
sudo nginx -t

# Restart Nginx
sudo systemctl restart nginx
sudo systemctl enable nginx
```

---

## Configure Supervisor (Queue Workers)

### Generate Supervisor Config

```bash
cd /var/www/address-validation
sudo php artisan deploy:supervisor --install --user=www-data --procs=2 --memory=512
```

Or manually create the config:

```bash
sudo nano /etc/supervisor/conf.d/address-validation-worker.conf
```

```ini
[program:address-validation-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/address-validation/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/address-validation/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=3600
```

### Start Workers

```bash
# Read new config
sudo supervisorctl reread
sudo supervisorctl update

# Start workers
sudo supervisorctl start address-validation-worker:*

# Check status
sudo supervisorctl status
```

### Supervisor Commands

```bash
# View all workers
sudo supervisorctl status

# Restart all workers
sudo supervisorctl restart address-validation-worker:*

# Stop all workers
sudo supervisorctl stop address-validation-worker:*

# View worker logs
tail -f /var/www/address-validation/storage/logs/worker.log
```

---

## SSL Certificate (Let's Encrypt)

### Install Certbot

```bash
sudo apt install -y certbot python3-certbot-nginx
```

### Obtain Certificate

```bash
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

### Auto-Renewal

Certbot adds auto-renewal automatically. Test it:
```bash
sudo certbot renew --dry-run
```

---

## Maintenance & Commands

### Daily Operations

```bash
# Check application health
php artisan about

# View queue status
php artisan queue:monitor

# Clear all caches (after code changes)
php artisan optimize:clear

# Rebuild caches
php artisan optimize
```

### Updating Application

```bash
cd /var/www/address-validation

# Enable maintenance mode
php artisan down

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Run migrations
php artisan migrate --force

# Clear and rebuild caches
php artisan optimize

# Restart queue workers
sudo supervisorctl restart address-validation-worker:*

# Disable maintenance mode
php artisan up
```

### Backup Database

```bash
# Create backup
mysqldump -u address_validation -p address_validation > backup_$(date +%Y%m%d_%H%M%S).sql

# Restore backup
mysql -u address_validation -p address_validation < backup_file.sql
```

### Log Files

```bash
# Application logs
tail -f /var/www/address-validation/storage/logs/laravel.log

# Queue worker logs
tail -f /var/www/address-validation/storage/logs/worker.log

# Nginx access logs
tail -f /var/log/nginx/access.log

# Nginx error logs
tail -f /var/log/nginx/error.log
```

---

## Troubleshooting

### Common Issues

#### 1. 502 Bad Gateway
```bash
# Check PHP-FPM is running
sudo systemctl status php8.4-fpm

# Restart PHP-FPM
sudo systemctl restart php8.4-fpm

# Check logs
sudo tail -f /var/log/nginx/error.log
```

#### 2. Permission Denied Errors
```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/address-validation
sudo chmod -R 775 /var/www/address-validation/storage
sudo chmod -R 775 /var/www/address-validation/bootstrap/cache
```

#### 3. Queue Jobs Not Processing
```bash
# Check Supervisor status
sudo supervisorctl status

# Check worker logs
tail -100 /var/www/address-validation/storage/logs/worker.log

# Restart workers
sudo supervisorctl restart address-validation-worker:*
```

#### 4. Database Connection Refused
```bash
# Check MySQL is running
sudo systemctl status mysql

# Test connection
mysql -u address_validation -p -e "SELECT 1"
```

#### 5. Out of Memory During Import/Export
```bash
# Increase PHP memory limit
sudo nano /etc/php/8.4/cli/php.ini
# Set: memory_limit = 1G

# Increase worker memory
sudo nano /etc/supervisor/conf.d/address-validation-worker.conf
# Change: --memory=1024

sudo supervisorctl reread
sudo supervisorctl update
```

#### 6. Slow Performance
```bash
# Enable OPcache (should be enabled by default)
php -m | grep -i opcache

# Check if caches are built
php artisan route:list --columns=uri,name | head

# Rebuild caches
php artisan optimize
```

### Health Check Script

Create `/var/www/address-validation/health-check.sh`:

```bash
#!/bin/bash

echo "=== Address Validation Health Check ==="
echo ""

# Check services
echo "Services:"
systemctl is-active --quiet nginx && echo "  ✓ Nginx: Running" || echo "  ✗ Nginx: Stopped"
systemctl is-active --quiet mysql && echo "  ✓ MySQL: Running" || echo "  ✗ MySQL: Stopped"
systemctl is-active --quiet php8.4-fpm && echo "  ✓ PHP-FPM: Running" || echo "  ✗ PHP-FPM: Stopped"
systemctl is-active --quiet supervisor && echo "  ✓ Supervisor: Running" || echo "  ✗ Supervisor: Stopped"

echo ""
echo "Queue Workers:"
sudo supervisorctl status | grep address-validation

echo ""
echo "Disk Usage:"
df -h /var/www/address-validation | tail -1

echo ""
echo "Memory Usage:"
free -h | grep Mem

echo ""
echo "Recent Errors (last 5):"
tail -5 /var/www/address-validation/storage/logs/laravel.log 2>/dev/null | grep -i error || echo "  No recent errors"
```

```bash
chmod +x /var/www/address-validation/health-check.sh
```

---

## Firewall Configuration (UFW)

```bash
# Enable UFW
sudo ufw enable

# Allow SSH
sudo ufw allow ssh

# Allow HTTP/HTTPS
sudo ufw allow 'Nginx Full'

# Check status
sudo ufw status
```

---

## Cron Jobs (Scheduled Tasks)

Add Laravel scheduler to crontab:

```bash
sudo crontab -e -u www-data
```

Add this line:
```
* * * * * cd /var/www/address-validation && php artisan schedule:run >> /dev/null 2>&1
```

---

## Quick Reference

| Task | Command |
|------|---------|
| Deploy updates | `git pull && composer install --no-dev && npm run build && php artisan migrate --force && php artisan optimize` |
| Restart workers | `sudo supervisorctl restart address-validation-worker:*` |
| View logs | `tail -f storage/logs/laravel.log` |
| Clear cache | `php artisan optimize:clear` |
| Maintenance mode | `php artisan down` / `php artisan up` |
| Create admin user | `php artisan make:filament-user` |
| Check queue | `php artisan queue:monitor` |

---

## Support

For issues specific to this application:
1. Check the [Troubleshooting](#troubleshooting) section
2. Review logs in `storage/logs/`
3. Contact the development team

For Laravel-specific questions:
- [Laravel Documentation](https://laravel.com/docs)
- [Filament Documentation](https://filamentphp.com/docs)
