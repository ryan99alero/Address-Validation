#!/bin/bash

# Fix Storage Permissions Script
# Automatically elevates to root if needed
#
# This script:
# 1. Creates all required storage directories
# 2. Sets proper ownership and permissions
# 3. Ensures web server can write to storage

set -e

# Re-run with sudo if not root
if [[ $EUID -ne 0 ]]; then
    echo "Elevating privileges..."
    exec sudo "$0" "$@"
fi

# Detect the script directory (project root)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STORAGE_DIR="$SCRIPT_DIR/storage"

# Detect web user (macOS Herd uses current user, Linux typically www-data)
if [[ "$OSTYPE" == "darwin"* ]]; then
    # On macOS, get the user who invoked sudo (not root)
    WEB_USER="${SUDO_USER:-$(whoami)}"
    WEB_GROUP="staff"
else
    WEB_USER="${WEB_USER:-www-data}"
    WEB_GROUP="${WEB_GROUP:-www-data}"
fi

echo "================================"
echo "Fixing Storage Permissions"
echo "================================"
echo "Storage path: $STORAGE_DIR"
echo "Owner: $WEB_USER:$WEB_GROUP"
echo ""

# Create all required directories
echo "Creating directory structure..."

DIRECTORIES=(
    "storage/app"
    "storage/app/private"
    "storage/app/private/export-templates"
    "storage/app/private/exports"
    "storage/app/private/imports"
    "storage/app/private/livewire-tmp"
    "storage/app/public"
    "storage/app/workers"
    "storage/framework"
    "storage/framework/cache"
    "storage/framework/cache/data"
    "storage/framework/cache/laravel-excel"
    "storage/framework/sessions"
    "storage/framework/testing"
    "storage/framework/views"
    "storage/framework/views/blaze"
    "storage/framework/views/livewire"
    "storage/invoices"
    "storage/invoices/input"
    "storage/invoices/processed"
    "storage/logs"
    "bootstrap/cache"
)

for dir in "${DIRECTORIES[@]}"; do
    if [[ ! -d "$SCRIPT_DIR/$dir" ]]; then
        echo "  Creating: $dir"
        mkdir -p "$SCRIPT_DIR/$dir"
    fi
done

echo ""
echo "Setting permissions..."

# Set ownership recursively on storage and bootstrap/cache
chown -R "$WEB_USER:$WEB_GROUP" "$STORAGE_DIR"
chown -R "$WEB_USER:$WEB_GROUP" "$SCRIPT_DIR/bootstrap/cache"

# Set directory permissions (775 = rwxrwxr-x)
find "$STORAGE_DIR" -type d -exec chmod 775 {} \;
find "$SCRIPT_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;

# Set file permissions (664 = rw-rw-r--)
find "$STORAGE_DIR" -type f -exec chmod 664 {} \;
find "$SCRIPT_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;

echo ""
echo "================================"
echo "Storage permissions fixed!"
echo "================================"
echo ""
echo "Directories created: ${#DIRECTORIES[@]}"
echo "Owner: $WEB_USER:$WEB_GROUP"
echo "Directory permissions: 775"
echo "File permissions: 664"
