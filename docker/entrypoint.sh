#!/bin/sh
# =============================================================================
# TesoTunes API - Docker Entrypoint
# =============================================================================
# Runs migrations and caching on container start, then starts supervisord.
# =============================================================================

set -e

echo "=== TesoTunes API Starting ==="

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
max_tries=30
count=0
until php artisan db:monitor --databases=mysql 2>/dev/null || [ $count -ge $max_tries ]; do
    count=$((count + 1))
    echo "MySQL not ready yet (attempt $count/$max_tries)..."
    sleep 2
done

if [ $count -ge $max_tries ]; then
    echo "WARNING: MySQL may not be ready, proceeding anyway..."
fi

# Run migrations
echo "Running migrations..."
php artisan migrate --force --no-interaction

# Cache configuration for production performance
echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Create storage link
php artisan storage:link --force 2>/dev/null || true

echo "=== TesoTunes API Ready ==="

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
