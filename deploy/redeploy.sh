#!/bin/bash
# =============================================================================
# TesoTunes Beta - Quick Redeploy Script (Security-Hardened)
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

echo "▸ Running security audit before deployment..."
php artisan security:audit-routes --fail-on-issues || {
    echo "❌ SECURITY AUDIT FAILED — Deployment aborted!"
    echo "Fix route security issues before redeploying."
    exit 1
}
php artisan security:audit-models --fail-on-issues || {
    echo "❌ MODEL SECURITY AUDIT FAILED — Deployment aborted!"
    echo "Fix model $fillable issues before redeploying."
    exit 1
}

# Verify Sanctum token expiration is set
if grep -q "'expiration' => null" config/sanctum.php; then
    echo "❌ CRITICAL: Sanctum token expiration is null! Deployment aborted."
    exit 1
fi

echo "▸ Running migrations..."
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

echo "▸ Reloading PHP-FPM and Nginx..."
systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
nginx -t && systemctl reload nginx

echo ""
echo "▸ Running post-deployment security smoke test..."
bash "$SITE_DIR/scripts/security-smoke-test.sh" "https://api.tesotunes.com/api" || {
    echo "⚠️  WARNING: Post-deployment security smoke test had failures!"
    echo "Check the output above and fix immediately."
}

echo "▸ Clearing Laravel optimization caches..."
php artisan optimize:clear

echo ""
echo "✓ Redeployed! Check:"
echo "  https://api.tesotunes.com"
echo "  https://api.tesotunes.com/api/health"
