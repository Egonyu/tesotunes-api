#!/bin/bash

# Update Laravel .env to use KeyDB for production
# This script updates cache, session, and queue to use Redis (KeyDB)

ENV_FILE="/var/www/tesotunes/.env"
BACKUP_FILE="/var/www/tesotunes/.env.backup.$(date +%Y%m%d_%H%M%S)"

echo "Backing up .env to $BACKUP_FILE"
cp "$ENV_FILE" "$BACKUP_FILE"

echo "Updating Laravel configuration to use KeyDB..."

# Update Cache
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=redis/' "$ENV_FILE"
if ! grep -q "^CACHE_STORE=" "$ENV_FILE"; then
    echo "CACHE_STORE=redis" >> "$ENV_FILE"
fi

# Update Session
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=redis/' "$ENV_FILE"
if ! grep -q "^SESSION_DRIVER=" "$ENV_FILE"; then
    echo "SESSION_DRIVER=redis" >> "$ENV_FILE"
fi

# Update Queue
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' "$ENV_FILE"
if ! grep -q "^QUEUE_CONNECTION=" "$ENV_FILE"; then
    echo "QUEUE_CONNECTION=redis" >> "$ENV_FILE"
fi

# Ensure Redis settings are correct
sed -i 's/^REDIS_CLIENT=.*/REDIS_CLIENT=phpredis/' "$ENV_FILE"
sed -i 's/^REDIS_HOST=.*/REDIS_HOST=127.0.0.1/' "$ENV_FILE"
sed -i 's/^REDIS_PORT=.*/REDIS_PORT=6379/' "$ENV_FILE"

if ! grep -q "^REDIS_CLIENT=" "$ENV_FILE"; then
    echo "REDIS_CLIENT=phpredis" >> "$ENV_FILE"
fi

echo "Configuration updated!"
echo ""
echo "Current Redis/Cache/Queue settings:"
grep -E "^(REDIS|CACHE|SESSION|QUEUE)" "$ENV_FILE"
