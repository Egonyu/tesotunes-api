#!/bin/bash
# =============================================================================
# TesoTunes Beta - Quick Redeploy Script (Security-Hardened)
# =============================================================================
# Run after pushing changes: sudo ./deploy/redeploy.sh
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SITE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PUBLIC_DIR="/var/www/api.tesotunes.com"
cd "$SITE_DIR"

PREVIOUS_PUBLIC_TARGET=""
if [ -L "$PUBLIC_DIR" ]; then
    PREVIOUS_PUBLIC_TARGET="$(readlink -f "$PUBLIC_DIR")"
fi

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
echo "▸ Clearing Laravel optimization caches..."
php artisan optimize:clear

echo "▸ Switching public site to the synced checkout..."
ln -sfnT "$SITE_DIR" "$PUBLIC_DIR"

echo ""
echo "▸ Running post-deployment security smoke test..."
bash "$SITE_DIR/scripts/security-smoke-test.sh" "https://api.tesotunes.com/api" || {
    echo "⚠️  WARNING: Post-deployment security smoke test had failures!"
    if [ -n "$PREVIOUS_PUBLIC_TARGET" ]; then
        ln -sfnT "$PREVIOUS_PUBLIC_TARGET" "$PUBLIC_DIR"
        echo "↩ Restored previous public symlink target: $PREVIOUS_PUBLIC_TARGET"
    fi
    echo "Check the output above and fix immediately."
    exit 1
}

echo ""
echo "✓ Redeployed! Check:"
echo "  https://api.tesotunes.com"
echo "  https://api.tesotunes.com/api/health"
