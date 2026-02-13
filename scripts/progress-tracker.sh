#!/bin/bash

# Progress Counter Script
# Run this anytime to see migration progress

echo "=================================================="
echo "     UI CONSOLIDATION PROGRESS TRACKER"
echo "=================================================="
echo ""

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m'

# Count total pages
TOTAL=$(find resources/views/frontend -name "*.blade.php" -type f | wc -l)

# Count migrated pages (using new layouts)
MUSIC_MIGRATED=$(grep -r "@extends('frontend.layouts.music')" resources/views/frontend --include="*.blade.php" 2>/dev/null | wc -l)
STORE_MIGRATED=$(grep -r "@extends('frontend.layouts.store')" resources/views/frontend --include="*.blade.php" 2>/dev/null | wc -l)
SACCO_MIGRATED=$(grep -r "@extends('frontend.layouts.sacco')" resources/views/frontend --include="*.blade.php" 2>/dev/null | wc -l)

# Count old layout usage
OLD_APP=$(grep -r "@extends('frontend.layouts.app')" resources/views/frontend --include="*.blade.php" 2>/dev/null | wc -l)
OLD_MODERN=$(grep -r "@extends('frontend.layouts.modern')" resources/views/frontend --include="*.blade.php" 2>/dev/null | wc -l)
OLD_UNIFIED=$(grep -r "@extends('frontend.layouts.unified')" resources/views/frontend --include="*.blade.php" 2>/dev/null | wc -l)

# Calculate totals
TOTAL_MIGRATED=$((MUSIC_MIGRATED + STORE_MIGRATED + SACCO_MIGRATED))
TOTAL_OLD=$((OLD_APP + OLD_MODERN + OLD_UNIFIED))
PERCENTAGE=$((TOTAL_MIGRATED * 100 / TOTAL))

# Days until launch
LAUNCH_DATE="2025-01-10"
TODAY=$(date +%Y-%m-%d)
DAYS_LEFT=$(( ( $(date -d "$LAUNCH_DATE" +%s) - $(date -d "$TODAY" +%s) ) / 86400 ))

# Display summary
echo -e "${BLUE}📊 OVERALL PROGRESS${NC}"
echo "===================="
echo -e "Total Pages:        ${YELLOW}$TOTAL${NC}"
echo -e "Migrated:           ${GREEN}$TOTAL_MIGRATED${NC} (${PERCENTAGE}%)"
echo -e "Still on Old:       ${RED}$TOTAL_OLD${NC}"
echo -e "Remaining:          ${YELLOW}$((TOTAL - TOTAL_MIGRATED))${NC}"
echo ""

# Progress bar
echo -n "Progress: ["
FILLED=$((PERCENTAGE / 5))
for ((i=0; i<20; i++)); do
    if [ $i -lt $FILLED ]; then
        echo -n "="
    else
        echo -n " "
    fi
done
echo "] ${PERCENTAGE}%"
echo ""

# Breakdown by section
echo -e "${BLUE}📁 BY SECTION${NC}"
echo "=============="
echo -e "Music Layout:   ${GREEN}$MUSIC_MIGRATED${NC} pages"
echo -e "Store Layout:   ${GREEN}$STORE_MIGRATED${NC} pages"
echo -e "SACCO Layout:   ${GREEN}$SACCO_MIGRATED${NC} pages"
echo ""

# Old layouts still in use
echo -e "${BLUE}⚠️  OLD LAYOUTS REMAINING${NC}"
echo "=========================="
echo -e "layouts.app:     ${RED}$OLD_APP${NC} pages"
echo -e "layouts.modern:  ${RED}$OLD_MODERN${NC} pages"
echo -e "layouts.unified: ${RED}$OLD_UNIFIED${NC} pages"
echo ""

# Timeline
echo -e "${BLUE}⏰ TIMELINE${NC}"
echo "==========="
echo -e "Launch Date:    ${YELLOW}January 10, 2025${NC}"
echo -e "Days Left:      ${YELLOW}$DAYS_LEFT${NC} days"
if [ $DAYS_LEFT -gt 0 ]; then
    PAGES_PER_DAY=$(((TOTAL - TOTAL_MIGRATED) / DAYS_LEFT))
    echo -e "Pages per day:  ${YELLOW}~$PAGES_PER_DAY${NC} (to finish on time)"
fi
echo ""

# Recommendations
echo -e "${BLUE}💡 RECOMMENDATIONS${NC}"
echo "==================="
if [ $PERCENTAGE -lt 25 ]; then
    echo -e "${RED}⚠️  Just getting started! Focus on creating master layout.${NC}"
    echo "   Next: Migrate home, timeline, dashboard pages first."
elif [ $PERCENTAGE -lt 50 ]; then
    echo -e "${YELLOW}⚠️  Good progress! Keep the momentum going.${NC}"
    echo "   Target: 15-20 pages per day"
elif [ $PERCENTAGE -lt 75 ]; then
    echo -e "${GREEN}✓ Great progress! You're over halfway there.${NC}"
    echo "   Focus: Complete one section at a time"
elif [ $PERCENTAGE -lt 95 ]; then
    echo -e "${GREEN}✓ Almost done! Push to finish.${NC}"
    echo "   Focus: Remaining pages + testing"
else
    echo -e "${GREEN}✓ Excellent! Start final testing phase.${NC}"
    echo "   Focus: QA, bug fixes, performance"
fi
echo ""

# Recent migrations
echo -e "${BLUE}📝 RECENTLY MIGRATED (Last 5)${NC}"
echo "==============================="
find resources/views/frontend -name "*.blade.php" -type f -mtime -1 -exec grep -l "@extends('frontend.layouts.\(music\|store\|sacco\)')" {} \; 2>/dev/null | tail -5 | while read file; do
    echo "  ✓ $(basename $file)"
done
echo ""

# Pages still needing migration (sample)
if [ $TOTAL_OLD -gt 0 ]; then
    echo -e "${BLUE}📋 NEXT TO MIGRATE (Sample of 10)${NC}"
    echo "==================================="
    grep -r "@extends('frontend.layouts.app')" resources/views/frontend --include="*.blade.php" -l 2>/dev/null | head -10 | while read file; do
        echo "  ⏳ $(basename $file)"
    done
    echo ""
fi

# Quick commands
echo -e "${BLUE}🔧 QUICK COMMANDS${NC}"
echo "=================="
echo "View full plan:     cat UI_CONSOLIDATION_PLAN_JAN10.md"
echo "Implementation:     cat IMPLEMENTATION_GUIDE_JAN10.md"
echo "Daily progress:     cat DAILY_PROGRESS_JAN10.md"
echo "Clear caches:       php artisan optimize:clear"
echo "Run this again:     ./scripts/progress-tracker.sh"
echo ""

echo "=================================================="
if [ $PERCENTAGE -eq 100 ]; then
    echo -e "${GREEN}🎉 MIGRATION COMPLETE! Ready to launch! 🚀${NC}"
else
    echo -e "${YELLOW}Keep going! You're doing great! 💪${NC}"
fi
echo "=================================================="
