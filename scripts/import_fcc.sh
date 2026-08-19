#!/bin/bash
# FCC Amateur License Database Import Script
# Downloads weekly FCC ULS dump and imports HD/EN/AM files

DB_USER="repeater_user"
DB_PASS="04W#s&vg2b"
DB_NAME="ok_repeater_coord"
WORK_DIR="/tmp/fcc_import"
LOG="/var/log/orsi_fcc_import.log"
FCC_URL="https://data.fcc.gov/download/pub/uls/complete/l_amat.zip"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a $LOG; }
db()  { mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "$1" 2>/dev/null; }

log "=== FCC License Import Started ==="

# Create work directory
rm -rf $WORK_DIR
mkdir -p $WORK_DIR

# Download FCC database
log "Downloading FCC amateur license database..."
curl -s --progress-bar -o $WORK_DIR/l_amat.zip "$FCC_URL"
if [ $? -ne 0 ]; then log "ERROR: Download failed"; exit 1; fi
FILESIZE=$(du -sh $WORK_DIR/l_amat.zip | cut -f1)
log "Downloaded: $FILESIZE"

# Extract only the files we need
log "Extracting HD.dat, EN.dat, AM.dat..."
cd $WORK_DIR
unzip -q l_amat.zip HD.dat EN.dat AM.dat
if [ $? -ne 0 ]; then log "ERROR: Extraction failed"; exit 1; fi

log "HD.dat: $(wc -l < HD.dat) records"
log "EN.dat: $(wc -l < EN.dat) records"
log "AM.dat: $(wc -l < AM.dat) records"

# Create temp tables and import
log "Creating temp tables..."
mysql --local-infile=1 -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null << SQL
DROP TABLE IF EXISTS fcc_hd_tmp;
DROP TABLE IF EXISTS fcc_en_tmp;
DROP TABLE IF EXISTS fcc_am_tmp;

CREATE TABLE fcc_hd_tmp (
    record_type VARCHAR(2), unique_system_id VARCHAR(20), uls_file_num VARCHAR(20),
    ebf_number VARCHAR(30), callsign VARCHAR(20), license_status VARCHAR(1),
    radio_service_code VARCHAR(2), grant_date VARCHAR(12), expired_date VARCHAR(12),
    cancellation_date VARCHAR(12), eligibility_rule_num VARCHAR(10),
    reserved VARCHAR(1), alien VARCHAR(1), alien_government VARCHAR(1),
    alien_corporation VARCHAR(1), alien_officer VARCHAR(1), alien_control VARCHAR(1),
    revoked VARCHAR(1), convicted VARCHAR(1), adjudged VARCHAR(1), reserved2 VARCHAR(1),
    common_carrier VARCHAR(1), non_common_carrier VARCHAR(1), private_comm VARCHAR(1),
    fixed VARCHAR(1), mobile VARCHAR(1), radiolocation VARCHAR(1), satellite VARCHAR(1),
    developmental VARCHAR(1), interconnected_service VARCHAR(1),
    certifier_first_name VARCHAR(20), certifier_mi VARCHAR(1),
    certifier_last_name VARCHAR(20), certifier_suffix VARCHAR(3),
    certifier_title VARCHAR(40), gender VARCHAR(1), african_american VARCHAR(1),
    native_american VARCHAR(1), hawaiian VARCHAR(1), asian VARCHAR(1),
    white VARCHAR(1), hispanic VARCHAR(1), last_action_date VARCHAR(12),
    INDEX (callsign)
) ENGINE=InnoDB;

CREATE TABLE fcc_en_tmp (
    record_type VARCHAR(2), unique_system_id VARCHAR(20), uls_file_num VARCHAR(14),
    ebf_number VARCHAR(30), callsign VARCHAR(20), entity_type VARCHAR(2),
    licensee_id VARCHAR(9), entity_name VARCHAR(200), first_name VARCHAR(20),
    mi VARCHAR(1), last_name VARCHAR(20), suffix VARCHAR(3), phone VARCHAR(10),
    fax VARCHAR(10), email VARCHAR(50), street_address VARCHAR(60),
    city VARCHAR(20), state VARCHAR(2), zip_code VARCHAR(9), po_box VARCHAR(20),
    attention_line VARCHAR(35), sgin VARCHAR(3), frn VARCHAR(10),
    applicant_type_code VARCHAR(1), applicant_type_other VARCHAR(40),
    status_code VARCHAR(1), status_date VARCHAR(12),
    INDEX (callsign)
) ENGINE=InnoDB;

CREATE TABLE fcc_am_tmp (
    record_type VARCHAR(2), unique_system_id VARCHAR(20), uls_file_num VARCHAR(14),
    ebf_number VARCHAR(30), callsign VARCHAR(20), operator_class VARCHAR(1),
    group_code VARCHAR(1), region_code VARCHAR(1), trustee_callsign VARCHAR(20),
    trustee_indicator VARCHAR(1), physician_certification VARCHAR(1),
    ve_signature VARCHAR(1), systematic_callsign_change VARCHAR(1),
    vanity_callsign_change VARCHAR(1), vanity_relationship VARCHAR(12),
    previous_callsign VARCHAR(20), previous_operator_class VARCHAR(1),
    trustee_name VARCHAR(50),
    INDEX (callsign)
) ENGINE=InnoDB;
SQL

log "Importing HD.dat..."
mysql --local-infile=1 -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null -e "
LOAD DATA LOCAL INFILE '$WORK_DIR/HD.dat'
INTO TABLE fcc_hd_tmp FIELDS TERMINATED BY '|' LINES TERMINATED BY '\n';"

log "Importing EN.dat..."
mysql --local-infile=1 -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null -e "
LOAD DATA LOCAL INFILE '$WORK_DIR/EN.dat'
INTO TABLE fcc_en_tmp FIELDS TERMINATED BY '|' LINES TERMINATED BY '\n';"

log "Importing AM.dat..."
mysql --local-infile=1 -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null -e "
LOAD DATA LOCAL INFILE '$WORK_DIR/AM.dat'
INTO TABLE fcc_am_tmp FIELDS TERMINATED BY '|' LINES TERMINATED BY '\n';"

log "Merging into fcc_licenses..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null << SQL
TRUNCATE TABLE fcc_licenses;
INSERT INTO fcc_licenses (
    callsign, licensee_name, license_status, license_class,
    grant_date, expiry_date, cancellation_date, last_action_date,
    email, phone, street_address, city, state, zip_code)
SELECT
    hd.callsign,
    TRIM(CONCAT(COALESCE(en.first_name,''),' ',COALESCE(en.last_name,''),' ',COALESCE(en.entity_name,''))) as licensee_name,
    hd.license_status,
    am.operator_class,
    CASE WHEN hd.grant_date       != '' THEN STR_TO_DATE(hd.grant_date,       '%m/%d/%Y') ELSE NULL END,
    CASE WHEN hd.expired_date     != '' THEN STR_TO_DATE(hd.expired_date,     '%m/%d/%Y') ELSE NULL END,
    CASE WHEN hd.cancellation_date!= '' THEN STR_TO_DATE(hd.cancellation_date,'%m/%d/%Y') ELSE NULL END,
    CASE WHEN hd.last_action_date != '' THEN STR_TO_DATE(hd.last_action_date, '%m/%d/%Y') ELSE NULL END,
    NULLIF(TRIM(en.email),''),
    NULLIF(TRIM(en.phone),''),
    NULLIF(TRIM(en.street_address),''),
    NULLIF(TRIM(en.city),''),
    NULLIF(TRIM(en.state),''),
    NULLIF(TRIM(en.zip_code),'')
FROM fcc_hd_tmp hd
LEFT JOIN fcc_en_tmp en ON en.callsign = hd.callsign AND en.entity_type = 'L'
LEFT JOIN fcc_am_tmp am ON am.callsign = hd.callsign
WHERE hd.callsign != '';

DROP TABLE IF EXISTS fcc_hd_tmp;
DROP TABLE IF EXISTS fcc_en_tmp;
DROP TABLE IF EXISTS fcc_am_tmp;
SQL

COUNT=$(db "SELECT COUNT(*) FROM fcc_licenses" | tail -1)
log "Import complete! $COUNT licenses in database"

# Cleanup
rm -rf $WORK_DIR
log "=== FCC License Import Complete ==="
