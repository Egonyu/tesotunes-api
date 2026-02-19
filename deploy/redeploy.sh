#!/bin/bash
# =============================================================================
# TesoTunes Beta - Quick Redeploy Script
# =============================================================================
# Run after pushing changes: sudo ./deploy/redeploy.sh
# =============================================================================

set -e

SITE_DIR="/var/www/api.tesotunes.com"
cd "$SITE_DIR"

echo "▸ Pulling latest code..."
git pull origin main

echo "▸ Updating Laravel..."
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan storage:link --force 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "▸ Restarting queue worker..."
supervisorctl restart tesotunes-worker:* 2>/dev/null || true

echo "▸ Reloading Nginx..."
nginx -t && systemctl reload nginx

echo ""
echo "✓ Redeployed! Check:"
echo "  https://api.tesotunes.com"
echo "  https://api.tesotunes.com/api/health"
