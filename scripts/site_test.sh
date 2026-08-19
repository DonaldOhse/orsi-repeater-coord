#!/bin/bash
# ORSI Site Test Script
# Usage: sudo bash /var/www/w5dro.com/repeater_coord/scripts/site_test.sh

BASE="https://w5dro.com/repeater_coord"
DB_USER="repeater_user"
DB_PASS="04W#s&vg2b"
DB_NAME="ok_repeater_coord"
REPORT="/tmp/orsi_site_test_$(date +%Y%m%d_%H%M%S).txt"
PASS=0
FAIL=0
WARN=0

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()     { echo -e "$1" | tee -a $REPORT; }
pass()    { log "${GREEN}  ✓ PASS${NC} - $1"; ((PASS++)); }
fail()    { log "${RED}  ✗ FAIL${NC} - $1"; ((FAIL++)); }
warn()    { log "${YELLOW}  ⚠ WARN${NC} - $1"; ((WARN++)); }
section() { log "\n${BLUE}══════════════════════════════════════${NC}"; log "${BLUE}  $1${NC}"; log "${BLUE}══════════════════════════════════════${NC}"; }

log "ORSI Site Test Report"
log "Generated: $(date)"
log "========================================"

# ── PUBLIC PAGES ──────────────────────────────────────────────
section "PUBLIC PAGES"

check_page() {
    local url="$1" name="$2" expect="$3"
    local code=$(curl -s -o /tmp/orsi_page.html -w "%{http_code}" --max-time 10 "$url")
    if [ "$code" != "200" ]; then
        fail "$name - HTTP $code"
    elif [ -n "$expect" ] && ! grep -qi "$expect" /tmp/orsi_page.html; then
        fail "$name - Missing: '$expect'"
    else
        pass "$name"
    fi
}

check_page "$BASE/index.php"         "Repeater List"     "repeater"
check_page "$BASE/map.php"           "Map Page"          "leaflet"
check_page "$BASE/most_wanted.php"   "Most Wanted"       "Most Wanted"
check_page "$BASE/request.php"       "New Request Form"  "coordination"
check_page "$BASE/login.php"         "Login Page"        "username"
check_page "$BASE/export.php"        "CSV Export"        ""
check_page "$BASE/kml_export.php"    "KML Export"        ""

REP_ID=$(mysql -u$DB_USER -p$DB_PASS $DB_NAME -se "SELECT id FROM repeaters WHERE archived_at IS NULL AND latitude IS NOT NULL AND status='OPERATIONAL' LIMIT 1" 2>/dev/null)
if [ -n "$REP_ID" ]; then
    check_page "$BASE/repeater.php?id=$REP_ID" "Repeater Detail" "leaflet"
else
    fail "Repeater Detail - No repeater found"
fi

# ── API ENDPOINTS ─────────────────────────────────────────────
section "API ENDPOINTS"

check_api() {
    local url="$1" name="$2" expect="$3"
    local response=$(curl -s --max-time 10 "$url")
    if echo "$response" | grep -q '"success":true'; then
        if [ -n "$expect" ] && ! echo "$response" | grep -q "$expect"; then
            fail "$name - Missing: '$expect'"
        else
            pass "$name"
        fi
    else
        fail "$name - $(echo $response | cut -c1-80)"
    fi
}

check_api "$BASE/api/index.php?path=repeaters&limit=1"    "API Repeaters"       "data"
check_api "$BASE/api/index.php?path=repeaters/$REP_ID"    "API Repeater Detail" "callsign"
check_api "$BASE/api/index.php?path=bands"                "API Bands"           "band"
check_api "$BASE/api/index.php?path=stats"                "API Stats"           "total"
check_api "$BASE/api/index.php?path=confirmations/$REP_ID" "API Confirmations"  "confirmations"
check_api "$BASE/api/index.php?path=cant_hear_count/$REP_ID" "API Cant Hear"    "count"

# Test confirm POST
CONFIRM=$(curl -s -X POST "$BASE/api/index.php?path=confirm" \
    -H "Content-Type: application/json" \
    -d "{\"repeater_id\":$REP_ID,\"callsign\":\"W5TST\"}")
if echo "$CONFIRM" | grep -q '"success":true'; then
    pass "API Confirm POST"
else
    fail "API Confirm POST - $(echo $CONFIRM | cut -c1-80)"
fi

# ── DATABASE ──────────────────────────────────────────────────
section "DATABASE"

db_query() {
    mysql -u$DB_USER -p$DB_PASS $DB_NAME -se "$1" 2>/dev/null
}

db_check() {
    local result=$(db_query "$1")
    [ $? -eq 0 ] && pass "$2 ($result)" || fail "$2 - Query failed"
}

db_check "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL" "Active repeaters"
db_check "SELECT COUNT(*) FROM repeater_confirmations" "Confirmations table"
db_check "SELECT COUNT(*) FROM repeater_cant_hear" "Cant hear table"
db_check "SELECT COUNT(*) FROM coordination_requests" "Requests table"
db_check "SELECT COUNT(*) FROM email_templates" "Email templates"
db_check "SELECT COUNT(*) FROM system_settings" "System settings"
db_check "SELECT COUNT(*) FROM users WHERE active=1" "Active users"

for setting in confirm_threshold confirm_days cant_hear_threshold; do
    val=$(db_query "SELECT setting_value FROM system_settings WHERE setting_key='$setting'")
    [ -n "$val" ] && pass "Setting: $setting=$val" || fail "Missing setting: $setting"
done

# Archive columns exist
ARCH=$(db_query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='repeaters' AND COLUMN_NAME='archived_at'")
[ "$ARCH" -eq 1 ] && pass "Archive columns exist" || fail "Archive columns missing"

# Dead notice columns exist  
DEAD=$(db_query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='repeaters' AND COLUMN_NAME='dead_notice_sent'")
[ "$DEAD" -eq 1 ] && pass "Dead notice columns exist" || fail "Dead notice columns missing"

# ── PHP SYNTAX ────────────────────────────────────────────────
section "PHP SYNTAX CHECK"

PHPFILES=$(find /var/www/w5dro.com/repeater_coord -name "*.php" | grep -v debug_login | wc -l)
PHPERRORS=0
while IFS= read -r phpfile; do
    result=$(php -l "$phpfile" 2>&1)
    if ! echo "$result" | grep -q "No syntax errors"; then
        fail "PHP syntax: $(basename $phpfile) - $result"
        ((PHPERRORS++))
    fi
done < <(find /var/www/w5dro.com/repeater_coord -name "*.php" | grep -v debug_login)
[ $PHPERRORS -eq 0 ] && pass "All $PHPFILES PHP files pass syntax check"

# ── EMAIL SYSTEM ──────────────────────────────────────────────
section "EMAIL SYSTEM"

systemctl is-active --quiet postfix   && pass "Postfix running"   || fail "Postfix not running"
systemctl is-active --quiet opendkim  && pass "OpenDKIM running"  || fail "OpenDKIM not running"

QUEUE=$(mailq 2>/dev/null | tail -1)
echo "$QUEUE" | grep -q "empty" && pass "Mail queue empty" || warn "Mail queue: $QUEUE"

SPF=$(dig +short TXT w5dro.com 2>/dev/null | grep "v=spf1")
[ -n "$SPF" ] && pass "SPF record found" || fail "SPF record missing"

DKIM=$(dig +short TXT mail._domainkey.w5dro.com 2>/dev/null | grep "v=DKIM1")
[ -n "$DKIM" ] && pass "DKIM record found" || fail "DKIM record missing"

# ── CRON ──────────────────────────────────────────────────────
section "CRON JOBS"

sudo crontab -l 2>/dev/null | grep -q "send_renewals.php" && pass "Renewal cron configured" || fail "Renewal cron missing"

if [ -f /var/log/orsi_renewals.log ]; then
    SIZE=$(wc -c < /var/log/orsi_renewals.log)
    [ "$SIZE" -gt 0 ] && pass "Renewal log has content" || warn "Renewal log empty (not run yet)"
else
    fail "Renewal log missing"
fi

# ── FILES ─────────────────────────────────────────────────────
section "KEY FILES"

for f in \
    "includes/config.php" \
    "includes/header.php" \
    "includes/footer.php" \
    "api/index.php" \
    "api/.htaccess" \
    "assets/css/style.css" \
    "dead_response.php" \
    "most_wanted.php" \
    "renewal.php" \
    "admin/cant_hear_review.php" \
    "admin/archive.php" \
    "admin/send_renewals.php"; do
    [ -f "/var/www/w5dro.com/repeater_coord/$f" ] && pass "$f" || fail "$f missing"
done

[ -w /var/www/w5dro.com/repeater_coord/splat_cache ] && pass "splat_cache writable" || fail "splat_cache not writable"

# ── DATA QUALITY ──────────────────────────────────────────────
section "DATA QUALITY"

MISSING_EMAIL=$(db_query "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND status='OPERATIONAL' AND (contact_email IS NULL OR contact_email='')")
CITY_GPS=$(db_query "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND location_source='CITY'")
UNKNOWN_CT=$(db_query "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND status='UNKNOWN'")
DEAD_CT=$(db_query "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND status='DEAD'")
ARCHIVED_CT=$(db_query "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NOT NULL")
PENDING_REQ=$(db_query "SELECT COUNT(*) FROM coordination_requests WHERE status='PENDING'")
PENDING_UPD=$(db_query "SELECT COUNT(*) FROM update_requests WHERE status='PENDING'")
CANT_HEAR=$(db_query "SELECT COUNT(DISTINCT repeater_id) FROM repeater_cant_hear WHERE reported_at > DATE_SUB(NOW(), INTERVAL 120 DAY)")
DEAD_NOTICE=$(db_query "SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND status='DEAD' AND dead_notice_response IS NULL AND (dead_notice_sent IS NULL OR dead_notice_sent < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) AND contact_email IS NOT NULL")

[ "$MISSING_EMAIL" -eq 0 ] && pass "All operational repeaters have email" || warn "$MISSING_EMAIL operational repeaters missing email"
[ "$CITY_GPS" -lt 10 ] && pass "City GPS only: $CITY_GPS" || warn "Repeaters with city-only GPS: $CITY_GPS"
[ "$UNKNOWN_CT" -lt 50 ] && pass "Unknown repeaters: $UNKNOWN_CT" || warn "High unknown count: $UNKNOWN_CT"
[ "$DEAD_CT" -lt 30 ] && pass "Dead repeaters: $DEAD_CT" || warn "Dead repeaters: $DEAD_CT"
pass "Archived repeaters: $ARCHIVED_CT"
[ "$PENDING_REQ" -eq 0 ] && pass "No pending coordination requests" || warn "Pending requests: $PENDING_REQ"
[ "$PENDING_UPD" -eq 0 ] && pass "No pending update requests" || warn "Pending updates: $PENDING_UPD"
[ "$CANT_HEAR" -eq 0 ] && pass "No cant-hear reports" || warn "Repeaters with cant-hear reports: $CANT_HEAR"
[ "$DEAD_NOTICE" -eq 0 ] && pass "No dead notices pending" || warn "Dead notices due to send: $DEAD_NOTICE"

# ── SUMMARY ───────────────────────────────────────────────────
section "SUMMARY"
TOTAL=$((PASS + FAIL + WARN))
log "Total checks:  $TOTAL"
log "${GREEN}Passed:   $PASS${NC}"
log "${YELLOW}Warnings: $WARN${NC}"
log "${RED}Failed:   $FAIL${NC}"
log "\nFull report: $REPORT"

if [ $FAIL -gt 0 ]; then
    log "\n${RED}═══ FAILED CHECKS ═══${NC}"
    grep "✗ FAIL" $REPORT | sed 's/\x1b\[[0-9;]*m//g'
fi

if [ $WARN -gt 0 ]; then
    log "\n${YELLOW}═══ WARNINGS ═══${NC}"
    grep "⚠ WARN" $REPORT | sed 's/\x1b\[[0-9;]*m//g'
fi

exit $FAIL

# This appends functional tests - but actually let's rewrite properly
