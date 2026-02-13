#!/bin/bash

#######################################
# Log Cleanup Script for TesoTunes
# Purpose: Archive large logs and clean old files
# Schedule: Run daily via cron
#######################################

set -e

APP_DIR="/var/www/tesotunes"
LOG_DIR="$APP_DIR/storage/logs"
ARCHIVE_DIR="$LOG_DIR/archive"
MAX_SIZE_MB=50
DAYS_TO_KEEP=30

cd "$APP_DIR"

# Create archive directory
mkdir -p "$ARCHIVE_DIR"

echo "🧹 Starting log cleanup - $(date)"

# Find and archive large log files
find "$LOG_DIR" -maxdepth 1 -type f -name "*.log" -size +${MAX_SIZE_MB}M | while read -r logfile; do
    filename=$(basename "$logfile")
    echo "  📦 Archiving large file: $filename"
    gzip -c "$logfile" > "$ARCHIVE_DIR/${filename%.log}-$(date +%Y%m%d-%H%M%S).log.gz"
    > "$logfile"  # Clear the file
done

# Delete old archived logs
find "$ARCHIVE_DIR" -name "*.gz" -mtime +$DAYS_TO_KEEP -delete
echo "  🗑️  Deleted archives older than $DAYS_TO_KEEP days"

# Delete old daily log files
find "$LOG_DIR" -name "*.log" -mtime +14 -delete
find "$LOG_DIR" -name "laravel-*.log" -mtime +14 -delete
echo "  🗑️  Deleted daily logs older than 14 days"

# Report current usage
TOTAL_SIZE=$(du -sh "$LOG_DIR" | cut -f1)
echo "  📊 Current log directory size: $TOTAL_SIZE"

# Cleanup PHP session files (optional)
find "$APP_DIR/storage/framework/sessions" -type f -mtime +7 -delete 2>/dev/null || true

echo "✅ Log cleanup complete - $(date)"
