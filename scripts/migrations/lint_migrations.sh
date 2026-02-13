#!/bin/bash

###############################################################################
# Migration Linter Script
#
# Purpose: Detect migrations that rename/drop columns without approval token
#
# Usage:
#   ./scripts/migrations/lint_migrations.sh [migration_file]
#   ./scripts/migrations/lint_migrations.sh                    # Check all
#
# Exit Codes:
#   0 - All migrations pass lint checks
#   1 - Found unapproved rename/drop operations
#
# Approval Token Format:
#   // MIGRATION-ALTER-APPROVED: TICKET-1234
#
###############################################################################

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MIGRATIONS_DIR="$PROJECT_ROOT/database/migrations"

# Colors
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Counters
CHECKED=0
VIOLATIONS=0

echo "🔍 Migration Linter - Checking for unapproved schema alterations..."
echo ""

###############################################################################
# Check if a migration has approval token
###############################################################################
has_approval_token() {
    local file="$1"
    grep -q "MIGRATION-ALTER-APPROVED:" "$file"
    return $?
}

###############################################################################
# Check for dangerous operations
###############################################################################
check_migration() {
    local file="$1"
    local filename=$(basename "$file")
    local violations_found=0
    
    # Skip if file doesn't exist or is not readable
    if [[ ! -r "$file" ]]; then
        return 0
    fi
    
    # Check for renameColumn
    if grep -q "renameColumn\|rename(" "$file"; then
        if ! has_approval_token "$file"; then
            echo -e "${RED}❌ VIOLATION${NC}: $filename"
            echo "   Contains: renameColumn() or rename()"
            echo "   Missing approval token: // MIGRATION-ALTER-APPROVED: TICKET-XXX"
            echo ""
            violations_found=1
        fi
    fi
    
    # Check for dropColumn
    if grep -q "dropColumn\|drop(['\"]" "$file"; then
        if ! has_approval_token "$file"; then
            echo -e "${RED}❌ VIOLATION${NC}: $filename"
            echo "   Contains: dropColumn() or drop()"
            echo "   Missing approval token: // MIGRATION-ALTER-APPROVED: TICKET-XXX"
            echo ""
            violations_found=1
        fi
    fi
    
    # Check for change() (column type changes)
    if grep -q "->change()" "$file"; then
        if ! has_approval_token "$file"; then
            echo -e "${YELLOW}⚠️  WARNING${NC}: $filename"
            echo "   Contains: ->change() (column type modification)"
            echo "   Consider adding approval token for column type changes"
            echo ""
            # Don't count as violation, just warning
        fi
    fi
    
    return $violations_found
}

###############################################################################
# Main
###############################################################################

if [[ $# -eq 1 ]]; then
    # Check single file
    TARGET_FILE="$1"
    if [[ ! -f "$TARGET_FILE" ]]; then
        echo -e "${RED}Error: File not found: $TARGET_FILE${NC}"
        exit 1
    fi
    
    echo "Checking: $(basename "$TARGET_FILE")"
    echo ""
    
    if check_migration "$TARGET_FILE"; then
        echo -e "${GREEN}✅ No violations found${NC}"
        exit 0
    else
        exit 1
    fi
else
    # Check all migrations
    if [[ ! -d "$MIGRATIONS_DIR" ]]; then
        echo -e "${RED}Error: Migrations directory not found: $MIGRATIONS_DIR${NC}"
        exit 1
    fi
    
    for migration in "$MIGRATIONS_DIR"/*.php; do
        if [[ -f "$migration" ]]; then
            CHECKED=$((CHECKED + 1))
            
            if ! check_migration "$migration"; then
                VIOLATIONS=$((VIOLATIONS + 1))
            fi
        fi
    done
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo "📊 Summary:"
    echo "   Migrations checked: $CHECKED"
    echo "   Violations found: $VIOLATIONS"
    echo ""
    
    if [[ $VIOLATIONS -eq 0 ]]; then
        echo -e "${GREEN}✅ All migrations passed lint checks!${NC}"
        exit 0
    else
        echo -e "${RED}❌ Found $VIOLATIONS migration(s) with violations${NC}"
        echo ""
        echo "To approve a migration, add this comment at the top:"
        echo "  // MIGRATION-ALTER-APPROVED: TICKET-1234"
        echo ""
        exit 1
    fi
fi
