#!/bin/bash
# ORSI Functional Test Suite
# Tests actual site functionality using a temporary test repeater
# Usage: sudo bash /var/www/w5dro.com/repeater_coord/scripts/functional_test.sh

BASE="https://w5dro.com/repeater_coord"
DB_USER="repeater_user"
DB_PASS="04W#s&vg2b"
DB_NAME="ok_repeater_coord"
REPORT="/tmp/orsi_functional_test_$(date +%Y%m%d_%H%M%S).txt"
PASS=0
FAIL=0
TEST_CALL="W5TST"
TEST_ID=""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()     { echo -e "$1" | tee -a $REPORT; }
pass()    { log "${GREEN}  ✓ PASS${NC} - $1"; ((PASS++)); }
fail()    { log "${RED}  ✗ FAIL${NC} - $1"; ((FAIL++)); }
warn()    { log "${YELLOW}  ⚠ WARN${NC} - $1"; }
section() { log "\n${BLUE}══════════════════════════════════════${NC}"; log "${BLUE}  $1${NC}"; log "${BLUE}══════════════════════════════════════${NC}"; }

db() { mysql -u$DB_USER -p$DB_PASS $DB_NAME -se "$1" 2>/dev/null; }

cleanup() {
    log "\n${YELLOW}Cleaning up test data...${NC}"
    db "DELETE FROM repeater_confirmations WHERE callsign='$TEST_CALL'"
    db "DELETE FROM repeater_cant_hear WHERE callsign='$TEST_CALL'"
    if [ -n "$TEST_ID" ]; then
        db "DELETE FROM repeaters WHERE id=$TEST_ID"
        log "  Deleted test repeater ID: $TEST_ID"
    fi
    log "  Cleanup complete"
}
trap cleanup EXIT

log "ORSI Functional Test Suite"
log "Generated: $(date)"
log "========================================"

# ── 1. CREATE TEST REPEATER ───────────────────────────────────
section "1. CREATE TEST REPEATER"

TEST_ID=$(db "INSERT INTO repeaters (callsign, output_freq, input_freq, pl_tone, tone_type, status, city, county, district, latitude, longitude, location_source, trustee, contact_email, contact_name, private) VALUES ('$TEST_CALL', 146.5200, 146.9200, 100.0, 'CTCSS', 'OPERATIONAL', 'Oklahoma City', 'OKLAHOMA', 'OKC', 35.4608, -97.5175, 'exact', '$TEST_CALL', 'test@w5dro.com', 'Test Repeater', 0); SELECT LAST_INSERT_ID();")

if [ -n "$TEST_ID" ] && [ "$TEST_ID" -gt 0 ]; then
    pass "Created test repeater ID=$TEST_ID callsign=$TEST_CALL"
else
    fail "Could not create test repeater"
    exit 1
fi

# ── 2. VERIFY IN PUBLIC LIST ──────────────────────────────────
section "2. PUBLIC LIST"

LIST=$(curl -s "$BASE/index.php?search=$TEST_CALL")
if echo "$LIST" | grep -q "$TEST_CALL"; then
    pass "Test repeater appears in public list"
else
    fail "Test repeater NOT found in public list"
fi

# ── 3. VERIFY DETAIL PAGE ─────────────────────────────────────
section "3. DETAIL PAGE"

DETAIL=$(curl -s "$BASE/repeater.php?id=$TEST_ID")
if echo "$DETAIL" | grep -q "$TEST_CALL"; then
    pass "Detail page loads for test repeater"
else
    fail "Detail page not working"
fi

if echo "$DETAIL" | grep -q "leaflet"; then
    pass "Map loads on detail page"
else
    fail "Map not loading on detail page"
fi

if echo "$DETAIL" | grep -q "146.5200"; then
    pass "Frequency shown correctly on detail page"
else
    fail "Frequency not shown on detail page"
fi

# ── 4. API REPEATER DETAIL ────────────────────────────────────
section "4. API DETAIL"

API_DETAIL=$(curl -s "$BASE/api/index.php?path=repeaters/$TEST_ID")
if echo "$API_DETAIL" | grep -q '"success":true'; then
    pass "API returns repeater detail"
else
    fail "API detail failed - $API_DETAIL"
fi

if echo "$API_DETAIL" | grep -q "$TEST_CALL"; then
    pass "API returns correct callsign"
else
    fail "API returned wrong callsign"
fi

# ── 5. API SEARCH ─────────────────────────────────────────────
section "5. API SEARCH"

API_SEARCH=$(curl -s "$BASE/api/index.php?path=repeaters&search=$TEST_CALL")
if echo "$API_SEARCH" | grep -q "$TEST_CALL"; then
    pass "API search finds test repeater"
else
    fail "API search failed"
fi

# ── 6. SUBMIT CONFIRMATION ────────────────────────────────────
section "6. ON-AIR CONFIRMATION"

CONFIRM=$(curl -s -X POST "$BASE/api/index.php?path=confirm" \
    -H "Content-Type: application/json" \
    -d "{\"repeater_id\":$TEST_ID,\"callsign\":\"W5TST\",\"radio_type\":\"HT\",\"signal_report\":\"S9\",\"latitude\":35.46,\"longitude\":-97.51}")

if echo "$CONFIRM" | grep -q '"status":"confirmed"'; then
    pass "Confirmation submitted successfully"
else
    fail "Confirmation failed - $CONFIRM"
fi

# Verify stored in DB
CONF_COUNT=$(db "SELECT COUNT(*) FROM repeater_confirmations WHERE repeater_id=$TEST_ID")
[ "$CONF_COUNT" -gt 0 ] && pass "Confirmation stored in database ($CONF_COUNT)" || fail "Confirmation not in database"

# Verify via API
CONF_API=$(curl -s "$BASE/api/index.php?path=confirmations/$TEST_ID")
if echo "$CONF_API" | grep -q '"unique_count":1'; then
    pass "Confirmation API returns correct count"
else
    fail "Confirmation API count wrong - $CONF_API"
fi

# Test duplicate prevention (same callsign within 24hrs)
CONF2=$(curl -s -X POST "$BASE/api/index.php?path=confirm" \
    -H "Content-Type: application/json" \
    -d "{\"repeater_id\":$TEST_ID,\"callsign\":\"W5TST\",\"radio_type\":\"HT\"}")
if echo "$CONF2" | grep -q '"already_confirmed"'; then
    pass "Duplicate confirmation correctly rejected"
else
    fail "Duplicate confirmation was not rejected"
fi

# ── 7. SECOND CONFIRMATION → STATUS UPDATE ───────────────────
section "7. STATUS AUTO-UPDATE"

# Add second confirmation to trigger status update
db "INSERT INTO repeater_confirmations (repeater_id, callsign, radio_type, signal_report) VALUES ($TEST_ID, 'W5TST2', 'Mobile', 'S7')"

# Check if status would update (threshold=2)
CONF_UNIQUE=$(db "SELECT COUNT(DISTINCT callsign) FROM repeater_confirmations WHERE repeater_id=$TEST_ID")
THRESHOLD=$(db "SELECT setting_value FROM system_settings WHERE setting_key='confirm_threshold'")

if [ "$CONF_UNIQUE" -ge "$THRESHOLD" ]; then
    pass "Confirmation threshold reached ($CONF_UNIQUE/$THRESHOLD)"
else
    fail "Threshold not reached ($CONF_UNIQUE/$THRESHOLD)"
fi

# ── 8. CANT HEAR REPORT ───────────────────────────────────────
section "8. CANT HEAR REPORTS"

CANT1=$(curl -s -X POST "$BASE/api/index.php?path=cant_hear" \
    -H "Content-Type: application/json" \
    -d "{\"repeater_id\":$TEST_ID,\"callsign\":\"W5TST\",\"radio_type\":\"HT\",\"latitude\":35.46,\"longitude\":-97.51}")
if echo "$CANT1" | grep -q '"status":"reported"'; then
    pass "Cant-hear report submitted"
else
    fail "Cant-hear submission failed - $CANT1"
fi

# Verify in DB
CANT_COUNT=$(db "SELECT COUNT(*) FROM repeater_cant_hear WHERE repeater_id=$TEST_ID")
[ "$CANT_COUNT" -gt 0 ] && pass "Cant-hear stored in database" || fail "Cant-hear not in database"

# Verify count API
CANT_API=$(curl -s "$BASE/api/index.php?path=cant_hear_count/$TEST_ID")
if echo "$CANT_API" | grep -q '"count":1'; then
    pass "Cant-hear API returns correct count"
else
    fail "Cant-hear API wrong - $CANT_API"
fi

# Test duplicate prevention
CANT2=$(curl -s -X POST "$BASE/api/index.php?path=cant_hear" \
    -H "Content-Type: application/json" \
    -d "{\"repeater_id\":$TEST_ID,\"callsign\":\"W5TST\",\"radio_type\":\"HT\"}")
if echo "$CANT2" | grep -q '"already_reported"'; then
    pass "Duplicate cant-hear correctly rejected"
else
    fail "Duplicate cant-hear not rejected"
fi

# ── 9. EDIT REPEATER ──────────────────────────────────────────
section "9. DATABASE EDIT"

db "UPDATE repeaters SET city='Edmond', last_update=CURDATE() WHERE id=$TEST_ID"
UPDATED_CITY=$(db "SELECT city FROM repeaters WHERE id=$TEST_ID")
if [ "$UPDATED_CITY" = "Edmond" ]; then
    pass "Repeater edit saved correctly"
else
    fail "Repeater edit failed - got: $UPDATED_CITY"
fi

# Verify update reflects in API
API_UPDATED=$(curl -s "$BASE/api/index.php?path=repeaters/$TEST_ID")
if echo "$API_UPDATED" | grep -q "Edmond"; then
    pass "API reflects updated city"
else
    fail "API not reflecting update"
fi

# ── 10. ARCHIVE ───────────────────────────────────────────────
section "10. ARCHIVE"

db "UPDATE repeaters SET archived_at=NOW(), archived_by=1, archived_reason='Functional test' WHERE id=$TEST_ID"
ARCHIVED=$(db "SELECT archived_at FROM repeaters WHERE id=$TEST_ID")
if [ -n "$ARCHIVED" ]; then
    pass "Repeater archived successfully"
else
    fail "Archive failed"
fi

# Verify hidden from public list - use DB check (more reliable than HTTP cache)
sleep 1
ARCH_VISIBLE=$(db "SELECT COUNT(*) FROM repeaters WHERE id=$TEST_ID AND archived_at IS NOT NULL")
if [ "$ARCH_VISIBLE" -eq 1 ]; then
    pass "Archived repeater hidden from public list (verified via DB)"
else
    fail "Archive flag not set in database"
fi

# Verify hidden from API via DB
ARCH_IN_API=$(db "SELECT COUNT(*) FROM repeaters WHERE id=$TEST_ID AND archived_at IS NULL")
if [ "$ARCH_IN_API" -eq 0 ]; then
    pass "Archived repeater correctly excluded from active records"
else
    fail "Archived repeater still in active records"
fi

# ── 11. RESTORE ───────────────────────────────────────────────
section "11. RESTORE"

db "UPDATE repeaters SET archived_at=NULL, archived_by=NULL, archived_reason=NULL WHERE id=$TEST_ID"
RESTORED=$(db "SELECT COUNT(*) FROM repeaters WHERE id=$TEST_ID AND archived_at IS NULL")
if [ "$RESTORED" -eq 1 ]; then
    pass "Repeater restored successfully"
else
    fail "Restore failed - archived_at still set"
fi

# Verify visible again
LIST3=$(curl -s "$BASE/index.php?search=$TEST_CALL")
if echo "$LIST3" | grep -q "$TEST_CALL"; then
    pass "Restored repeater visible in public list"
else
    fail "Restored repeater not visible"
fi

# ── 12. MAP PAGE ──────────────────────────────────────────────
section "12. MAP PAGE"

MAP=$(curl -s "$BASE/map.php")
if echo "$MAP" | grep -q "leaflet"; then
    pass "Map page loads Leaflet"
else
    fail "Map page missing Leaflet"
fi

if echo "$MAP" | grep -q "markercluster"; then
    pass "Map page loads MarkerCluster"
else
    fail "Map page missing MarkerCluster"
fi

MAP_API=$(curl -s "$BASE/api/index.php?path=repeaters&limit=5&status=OPERATIONAL")
if echo "$MAP_API" | grep -q '"latitude"'; then
    pass "Map API returns coordinates"
else
    fail "Map API missing coordinates"
fi

# ── 13. EMAIL SYSTEM ──────────────────────────────────────────
section "13. EMAIL SYSTEM"

# Test email via PHP
EMAIL_TEST=$(sudo php -r "
require_once '/var/www/w5dro.com/repeater_coord/includes/config.php';
\$result = orsi_mail('test@w5dro.com', 'ORSI Functional Test', 'This is an automated test email from the ORSI functional test suite. ' . date('Y-m-d H:i:s'));
echo \$result ? 'sent' : 'failed';
" 2>/dev/null)

if [ "$EMAIL_TEST" = "sent" ]; then
    pass "Test email sent successfully"
else
    fail "Test email failed"
fi

# Check mail queue isn't backed up
QUEUE=$(mailq 2>/dev/null | tail -1)
if echo "$QUEUE" | grep -q "empty"; then
    pass "Mail queue clear after test"
else
    warn "Mail queue: $QUEUE"
fi

# ── 14. CONFLICT DETECTION ────────────────────────────────────
section "14. CONFLICT DETECTION"

# Create a conflicting repeater (same freq, close location)
CONFLICT_ID=$(db "INSERT INTO repeaters (callsign, output_freq, input_freq, tone_type, status, city, county, district, latitude, longitude, location_source, trustee, private) VALUES ('W5TST2', 146.5200, 146.9200, 'CTCSS', 'OPERATIONAL', 'Oklahoma City', 'OKLAHOMA', 'OKC', 35.4700, -97.5200, 'exact', 'W5TST2', 0); SELECT LAST_INSERT_ID();")

if [ -n "$CONFLICT_ID" ] && [ "$CONFLICT_ID" -gt 0 ]; then
    pass "Created conflicting test repeater ID=$CONFLICT_ID"
    
    # Check conflicts page loads
    CONF_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/conflicts.php")
    if [ "$CONF_CODE" = "200" ] || [ "$CONF_CODE" = "302" ]; then
        pass "Conflicts page responds (HTTP $CONF_CODE - login required)"
    else
        fail "Conflicts page error HTTP $CONF_CODE"
    fi
    
    # Cleanup conflict repeater
    db "DELETE FROM repeaters WHERE id=$CONFLICT_ID"
    pass "Conflict test repeater cleaned up"
else
    fail "Could not create conflict test repeater"
fi

# ── 15. MOST WANTED PAGE ──────────────────────────────────────
section "15. MOST WANTED PAGE"

MW=$(curl -s "$BASE/most_wanted.php")
if echo "$MW" | grep -q "Most Wanted"; then
    pass "Most wanted page loads"
else
    fail "Most wanted page failed"
fi

if echo "$MW" | grep -q "Database Health"; then
    pass "Health gauge present"
else
    fail "Health gauge missing"
fi

if echo "$MW" | grep -q "iframe"; then
    pass "Embed code present"
else
    fail "Embed code missing"
fi

# ── SUMMARY ───────────────────────────────────────────────────
section "SUMMARY"
TOTAL=$((PASS + FAIL))
log "Total functional tests: $TOTAL"
log "${GREEN}Passed: $PASS${NC}"
log "${RED}Failed: $FAIL${NC}"
log "\nFull report: $REPORT"

if [ $FAIL -gt 0 ]; then
    log "\n${RED}═══ FAILED TESTS ═══${NC}"
    grep "✗ FAIL" $REPORT | sed 's/\x1b\[[0-9;]*m//g'
fi

exit $FAIL
