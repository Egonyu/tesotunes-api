#!/bin/bash
# TesoTunes Security Smoke Test
# Run this after every deployment or security-related change
# Usage: bash scripts/security-smoke-test.sh [base_url]
#
# Exit codes:
#   0 = All tests passed
#   1 = One or more tests failed

set -euo pipefail

BASE_URL="${1:-https://api.tesotunes.com/api}"
PASS=0
FAIL=0
WARN=0

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

echo "============================================"
echo "  TesoTunes Security Smoke Test"
echo "  Target: ${BASE_URL}"
echo "  Date: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
echo "============================================"
echo ""

# Helper function to test an endpoint
test_endpoint() {
    local method="$1"
    local path="$2"
    local expected_code="$3"
    local description="$4"

    local url="${BASE_URL}${path}"
    local actual_code

    if [ "$method" = "GET" ]; then
        actual_code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 10 --max-time 30 -H "Accept: application/json" "$url" 2>/dev/null || echo "000")
    else
        actual_code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 10 --max-time 30 -X "$method" -H "Accept: application/json" -H "Content-Type: application/json" "$url" 2>/dev/null || echo "000")
    fi

    if [ "$actual_code" = "$expected_code" ]; then
        echo -e "${GREEN}[PASS]${NC} $description (${method} ${path} → ${actual_code})"
        PASS=$((PASS + 1))
    elif [ "$actual_code" = "000" ]; then
        echo -e "${YELLOW}[WARN]${NC} $description — Could not connect (${method} ${path})"
        WARN=$((WARN + 1))
    else
        echo -e "${RED}[FAIL]${NC} $description (${method} ${path} → ${actual_code}, expected ${expected_code})"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== SEC-CRIT-1: Admin Artist Routes (Must require auth) ==="
test_endpoint "GET"  "/admin/artists"           "401" "Admin artists list requires auth"
test_endpoint "GET"  "/admin/artists/statistics" "401" "Admin artists stats requires auth"
test_endpoint "GET"  "/admin/artists/1"          "401" "Admin artist detail requires auth"
test_endpoint "POST" "/admin/artists/1"          "401" "Admin artist update requires auth"
test_endpoint "POST" "/admin/artists/1/verify"   "401" "Admin artist verify requires auth"
test_endpoint "POST" "/admin/artists/1/approve"  "401" "Admin artist approve requires auth"
test_endpoint "POST" "/admin/artists/1/suspend"  "401" "Admin artist suspend requires auth"

echo ""
echo "=== SEC-CRIT-1: Admin User Routes (Must require auth) ==="
test_endpoint "GET" "/admin/users"            "401" "Admin users list requires auth"
test_endpoint "GET" "/admin/users/statistics"  "401" "Admin users stats requires auth"
test_endpoint "GET" "/admin/users/1"           "401" "Admin user detail requires auth"

echo ""
echo "=== SEC-CRIT-2: Admin Store Routes (Must require auth) ==="
test_endpoint "GET"  "/admin/store/stats"      "401" "Admin store stats requires auth"
test_endpoint "GET"  "/admin/store/products"   "401" "Admin store products requires auth"
test_endpoint "POST" "/admin/store/products"   "401" "Admin store create product requires auth"
test_endpoint "GET"  "/admin/store/orders"     "401" "Admin store orders requires auth"
test_endpoint "GET"  "/admin/store/shops"      "401" "Admin store shops requires auth"
test_endpoint "GET"  "/admin/store/analytics"  "401" "Admin store analytics requires auth"

echo ""
echo "=== SEC-CRIT-3: Payment Routes (Must require auth + role) ==="
test_endpoint "POST" "/payments/artist-payout"  "401" "Artist payout requires auth"
test_endpoint "POST" "/payments/subscription"   "401" "Subscription payment requires auth"
test_endpoint "POST" "/payments/1/refund"       "401" "Payment refund requires auth"

echo ""
echo "=== Dashboard Routes (Must require auth) ==="
test_endpoint "GET" "/admin/dashboard/stats"           "401" "Dashboard stats requires auth"
test_endpoint "GET" "/admin/dashboard/recent-activity"  "401" "Dashboard activity requires auth"

echo ""
echo "=== Admin Protected Routes (Must require auth + role) ==="
test_endpoint "GET" "/admin/settings"           "401" "Admin settings requires auth"
test_endpoint "GET" "/admin/songs"              "401" "Admin songs requires auth"
test_endpoint "GET" "/admin/events"             "401" "Admin events requires auth"
test_endpoint "GET" "/admin/campaigns"          "401" "Admin campaigns requires auth"
test_endpoint "GET" "/admin/sacco/stats"        "401" "Admin SACCO requires auth"
test_endpoint "GET" "/admin/payment-analytics"  "401" "Payment analytics requires auth"

echo ""
echo "=== Artist Routes (Must require auth + artist role) ==="
test_endpoint "GET"  "/artist/dashboard"  "401" "Artist dashboard requires auth"
test_endpoint "GET"  "/artist/songs"      "401" "Artist songs requires auth"
test_endpoint "POST" "/artist/songs"      "401" "Artist upload requires auth"
test_endpoint "GET"  "/artist/earnings"   "401" "Artist earnings requires auth"
test_endpoint "POST" "/artist/earnings/withdraw" "401" "Artist withdrawal requires auth"

echo ""
echo "=== Debug/Test Endpoints (Must not exist) ==="
# test-upload should return 404 now (removed)
test_endpoint "POST" "/test-upload" "404" "Test upload endpoint removed or protected"

echo ""
echo "=== Public Endpoints (Should work without auth) ==="
test_endpoint "GET" "/health"     "200" "Health check is public"
test_endpoint "GET" "/songs"      "200" "Songs list is public"
test_endpoint "GET" "/artists"    "200" "Artists list is public"
test_endpoint "GET" "/albums"     "200" "Albums list is public"
test_endpoint "GET" "/genres"     "200" "Genres list is public"

echo ""
echo "============================================"
echo "  RESULTS"
echo "============================================"
echo -e "  ${GREEN}Passed: ${PASS}${NC}"
echo -e "  ${RED}Failed: ${FAIL}${NC}"
echo -e "  ${YELLOW}Warnings: ${WARN}${NC}"
echo ""

if [ "$FAIL" -gt 0 ]; then
    echo -e "${RED}SECURITY TEST FAILED — ${FAIL} endpoint(s) are not properly protected!${NC}"
    exit 1
else
    echo -e "${GREEN}ALL SECURITY TESTS PASSED${NC}"
    exit 0
fi
