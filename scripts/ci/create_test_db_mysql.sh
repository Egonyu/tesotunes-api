#!/bin/bash
#
# Create MySQL Test Database for CI/Local Testing
# Usage: ./scripts/ci/create_test_db_mysql.sh
#
# This script ensures a clean test database exists before running migrations.
# It reads configuration from environment variables or uses sensible defaults.
#
# Required: MySQL client tools (mysql command)
# Privileges: User must have CREATE DATABASE and GRANT privileges
#

set -e  # Exit on error

# Read configuration from environment or use defaults
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-music_test}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_ROOT_USER="${DB_ROOT_USER:-root}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-${DB_PASSWORD}}"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Creating MySQL Test Database"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Host: $DB_HOST:$DB_PORT"
echo "Database: $DB_DATABASE"
echo "User: $DB_USERNAME"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Build mysql command with optional password
MYSQL_CMD="mysql -h${DB_HOST} -P${DB_PORT} -u${DB_ROOT_USER}"
if [ -n "$DB_ROOT_PASSWORD" ]; then
    MYSQL_CMD="$MYSQL_CMD -p${DB_ROOT_PASSWORD}"
fi

# Check MySQL connection
echo "→ Testing MySQL connection..."
if ! echo "SELECT 1;" | $MYSQL_CMD > /dev/null 2>&1; then
    echo "✗ ERROR: Cannot connect to MySQL at $DB_HOST:$DB_PORT"
    echo "  Please check your MySQL server is running and credentials are correct."
    exit 1
fi
echo "✓ MySQL connection successful"

# Drop existing database if it exists
echo "→ Dropping existing database if present..."
$MYSQL_CMD -e "DROP DATABASE IF EXISTS \`$DB_DATABASE\`;" 2>&1 || {
    echo "✗ ERROR: Failed to drop database $DB_DATABASE"
    exit 1
}
echo "✓ Database dropped (if existed)"

# Create fresh database
echo "→ Creating database $DB_DATABASE..."
$MYSQL_CMD -e "CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1 || {
    echo "✗ ERROR: Failed to create database $DB_DATABASE"
    exit 1
}
echo "✓ Database created"

# Grant privileges (skip if user is already root or same as root user)
if [ "$DB_USERNAME" != "$DB_ROOT_USER" ]; then
    echo "→ Granting privileges to $DB_USERNAME..."
    $MYSQL_CMD -e "GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'%';" 2>&1 || {
        echo "⚠ Warning: Failed to grant privileges (user may not exist or already has privileges)"
    }
    $MYSQL_CMD -e "FLUSH PRIVILEGES;" 2>&1 || true
    echo "✓ Privileges granted"
fi

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✓ Test database ready: $DB_DATABASE"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

exit 0
