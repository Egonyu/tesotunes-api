#!/bin/bash
# =============================================================================
# TesoTunes Beta - Quick Redeploy Script
# =============================================================================
# Run after pushing changes: sudo ./deploy/redeploy.sh
# =============================================================================

set -e

SITE_DIR="/var/www/beta.tesotunes.com"
cd "$SITE_DIR"

echo "▸ Pulling latest code..."
git pull origin main

echo "▸ Updating Laravel..."
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "▸ Restarting queue worker..."
systemctl restart tesotunes-beta-queue.service 2>/dev/null || true

echo "▸ Rebuilding & restarting Next.js..."
docker compose -f deploy/docker-compose.beta.yml up -d --build

echo "▸ Reloading Nginx..."
nginx -t && systemctl reload nginx

echo ""
echo "✓ Redeployed! Check:"
echo "  https://beta.tesotunes.com"
echo "  https://engine.tesotunes.com/api/health"
