#!/bin/bash

# Deploy script - Git pull and run migrations
# Usage: ./deploy.sh

set -e

echo "================================"
echo "Starting deployment..."
echo "================================"

# Get current branch
BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo "Current branch: $BRANCH"

# Pull latest changes
echo ""
echo "Pulling latest changes..."
git pull origin "$BRANCH"

# Run migrations
echo ""
echo "Running migrations..."
php artisan migrate --force

# Clear and rebuild caches
echo ""
echo "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Rebuild caches for production
echo ""
echo "Rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo ""
echo "================================"
echo "Deployment complete!"
echo "================================"
