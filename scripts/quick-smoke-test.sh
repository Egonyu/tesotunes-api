#!/bin/bash

# Quick Smoke Test for TesoTunes Launch
# Tests all critical pages and modules

echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║  🧪 TESOTUNES QUICK SMOKE TEST                                   ║"
echo "║  Testing all critical modules and pages...                       ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

BASE_URL="http://localhost:8000"
FAILED=0
PASSED=0

# Function to test a URL
test_url() {
    local url=$1
    local name=$2
    local expected_code=${3:-200}
    
    response=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL$url" 2>/dev/null)
    
    if [ "$response" == "$expected_code" ]; then
        echo -e "${GREEN}✓${NC} $name ($url) - HTTP $response"
        ((PASSED++))
    else
        echo -e "${RED}✗${NC} $name ($url) - HTTP $response (expected $expected_code)"
        ((FAILED++))
    fi
}

echo "📊 Testing Critical Routes..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Music Module (Green) 🟢
echo "🎵 MUSIC MODULE (Green)"
test_url "/" "Home Page"
test_url "/timeline" "Timeline"
test_url "/dashboard" "Dashboard" "302"  # Redirects if not logged in
test_url "/trending" "Trending"
test_url "/genres" "Genres"
test_url "/artists" "Artists"
echo ""

# Store Module (Green) 🟢
echo "🛒 STORE MODULE (Green)"
test_url "/store" "Store Index"
test_url "/store/products" "Products"
test_url "/store/cart" "Shopping Cart"
echo ""

# SACCO Module (Blue) 🔵
echo "💰 SACCO MODULE (Blue)"
test_url "/sacco" "SACCO Landing"
test_url "/sacco/dashboard" "SACCO Dashboard" "302"  # Redirects if not logged in
echo ""

# Forum Module (Purple) 🟣
echo "💬 FORUM MODULE (Purple)"
test_url "/forum" "Forum Index"
test_url "/forum/polls" "Polls"
echo ""

# Events Module (Amber) 🟠
echo "🎫 EVENTS MODULE (Amber)"
test_url "/events" "Events Index"
echo ""

# Awards Module (Yellow) 🟡
echo "🏆 AWARDS MODULE (Yellow)"
test_url "/awards" "Awards Index"
test_url "/awards/current-season" "Current Season"
echo ""

# Auth Pages
echo "🔐 AUTH PAGES"
test_url "/login" "Login Page"
test_url "/register" "Register Page"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📊 TEST RESULTS:"
echo "   ✓ Passed: $PASSED"
echo "   ✗ Failed: $FAILED"
echo "   Total: $((PASSED + FAILED))"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 ALL TESTS PASSED!${NC}"
    echo "✅ Ready for next phase"
    exit 0
else
    echo -e "${RED}⚠️  SOME TESTS FAILED!${NC}"
    echo "❌ Please fix issues before proceeding"
    exit 1
fi
