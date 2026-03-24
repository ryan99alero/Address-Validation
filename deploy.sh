#!/bin/bash

# Deploy script - Git pull and run migrations
# Usage: ./deploy.sh
# Automatically elevates to root for permission fixes

set -e

# Re-run with sudo if not root (needed for permission fixes)
if [[ $EUID -ne 0 ]]; then
    echo "Elevating privileges for deployment..."
    exec sudo "$0" "$@"
fi

# Get the original user (for running non-root commands)
DEPLOY_USER="${SUDO_USER:-$(whoami)}"

echo "================================"
echo "Starting deployment..."
echo "================================"
echo "Running as: root (for permissions)"
echo "Deploy user: $DEPLOY_USER"

# Get current branch (run as deploy user)
BRANCH=$(sudo -u "$DEPLOY_USER" git rev-parse --abbrev-ref HEAD)
echo "Current branch: $BRANCH"

# Pull latest changes (run as deploy user)
echo ""
echo "Pulling latest changes..."
sudo -u "$DEPLOY_USER" git pull origin "$BRANCH"

# Fix storage permissions (already running as root)
echo ""
echo "Fixing storage permissions..."
./fix-storage-permissions.sh

# Run migrations (run as deploy user)
echo ""
echo "Running migrations..."
sudo -u "$DEPLOY_USER" php artisan migrate --force

# Clear and rebuild caches (run as deploy user)
echo ""
echo "Clearing caches..."
sudo -u "$DEPLOY_USER" php artisan config:clear
sudo -u "$DEPLOY_USER" php artisan cache:clear
sudo -u "$DEPLOY_USER" php artisan view:clear
sudo -u "$DEPLOY_USER" php artisan route:clear

# Rebuild caches for production (run as deploy user)
echo ""
echo "Rebuilding caches..."
sudo -u "$DEPLOY_USER" php artisan config:cache
sudo -u "$DEPLOY_USER" php artisan route:cache
sudo -u "$DEPLOY_USER" php artisan view:cache

echo ""
echo "================================"
echo "Deployment complete!"
echo "================================"
