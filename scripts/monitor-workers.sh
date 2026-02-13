#!/bin/bash

# KeyDB and Worker Monitoring Script for TesoTunes
# Usage: ./monitor-workers.sh

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

clear
echo "══════════════════════════════════════════════════════════════"
echo "  TesoTunes - KeyDB & Worker Monitoring"
echo "══════════════════════════════════════════════════════════════"
echo ""

# Check KeyDB Status
echo -e "${BLUE}━━━ KeyDB Status ━━━${NC}"
if systemctl is-active --quiet keydb; then
    echo -e "${GREEN}✓${NC} KeyDB is running"
    UPTIME=$(keydb-cli INFO server | grep uptime_in_seconds | cut -d: -f2 | tr -d '\r')
    MEMORY=$(keydb-cli INFO memory | grep used_memory_human | cut -d: -f2 | tr -d '\r')
    KEYS=$(keydb-cli DBSIZE | grep -v grep)
    echo "  Uptime: $UPTIME seconds"
    echo "  Memory: $MEMORY"
    echo "  Keys: $KEYS"
else
    echo -e "${RED}✗${NC} KeyDB is not running"
fi

# Test connection
if keydb-cli ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} KeyDB responding to ping"
else
    echo -e "${RED}✗${NC} KeyDB not responding"
fi
echo ""

# Check Laravel Queue Workers
echo -e "${BLUE}━━━ Queue Workers ━━━${NC}"
WORKER_COUNT=$(supervisorctl status tesotunes-worker:* 2>/dev/null | grep RUNNING | wc -l)
TOTAL_WORKERS=$(supervisorctl status tesotunes-worker:* 2>/dev/null | wc -l)

if [ $WORKER_COUNT -gt 0 ]; then
    echo -e "${GREEN}✓${NC} $WORKER_COUNT/$TOTAL_WORKERS workers running"
    supervisorctl status tesotunes-worker:* | while read line; do
        if echo "$line" | grep -q RUNNING; then
            echo -e "  ${GREEN}●${NC} $line"
        else
            echo -e "  ${RED}●${NC} $line"
        fi
    done
else
    echo -e "${RED}✗${NC} No workers running"
fi
echo ""

# Queue Statistics
echo -e "${BLUE}━━━ Queue Statistics ━━━${NC}"
cd /var/www/tesotunes || exit
PENDING=$(php artisan queue:work redis --once --stop-when-empty 2>&1 | grep -c "No jobs")
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Queue system operational"
else
    echo -e "${YELLOW}⚠${NC} Queue system check inconclusive"
fi

# Check for failed jobs
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>/dev/null)
if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo -e "${YELLOW}⚠${NC} Failed jobs: $FAILED_COUNT"
else
    echo -e "${GREEN}✓${NC} No failed jobs"
fi
echo ""

# System Resources
echo -e "${BLUE}━━━ System Resources ━━━${NC}"
echo "Memory Usage:"
free -h | grep -E "Mem:|Swap:"

echo ""
echo "Disk Usage (/var/www/tesotunes):"
du -sh /var/www/tesotunes 2>/dev/null || echo "N/A"

echo ""
echo "KeyDB Process:"
ps aux | grep keydb-server | grep -v grep | awk '{printf "  CPU: %s%% | MEM: %s%% | PID: %s\n", $3, $4, $2}'

echo ""
echo "Worker Processes:"
ps aux | grep "queue:work" | grep -v grep | wc -l | xargs echo "  Active workers:"
echo ""

# Recent Logs
echo -e "${BLUE}━━━ Recent Logs (last 5 lines) ━━━${NC}"
echo -e "${YELLOW}Worker Log:${NC}"
tail -5 /var/www/tesotunes/storage/logs/worker.log 2>/dev/null || echo "No logs found"
echo ""
echo -e "${YELLOW}KeyDB Log:${NC}"
tail -5 /var/log/keydb/keydb-server.log 2>/dev/null || echo "No logs found"
echo ""

# Quick Commands
echo "══════════════════════════════════════════════════════════════"
echo -e "${BLUE}Quick Commands:${NC}"
echo "  Restart workers:    supervisorctl restart tesotunes-worker:*"
echo "  Restart KeyDB:      systemctl restart keydb"
echo "  View worker logs:   tail -f /var/www/tesotunes/storage/logs/worker.log"
echo "  View KeyDB logs:    tail -f /var/log/keydb/keydb-server.log"
echo "  Flush queue:        php artisan queue:flush"
echo "  Retry failed jobs:  php artisan queue:retry all"
echo "══════════════════════════════════════════════════════════════"
