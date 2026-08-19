#!/usr/bin/env python3
"""FCC data loader - imports HD/EN/AM dat files into MySQL"""
import sys, os, mysql.connector
from datetime import datetime

DB = dict(host='localhost', user='YOUR_DB_USER', password='YOUR_DB_PASSWORD', database='YOUR_DB_NAME')
WORK = '/tmp/fcc_import'

def parse_date(s):
    if not s or not s.strip(): return None
    try: return datetime.strptime(s.strip(), '%m/%d/%Y').date()
    except: return None

def log(msg):
    print(f"{datetime.now().strftime('%Y-%m-%d %H:%M:%S')} {msg}", flush=True)

log("Connecting to database...")
conn = mysql.connector.connect(**DB)
cur = conn.cursor()

# Load HD - license header
log("Loading HD.dat...")
hd = {}
STATUS_PRIORITY = {'A': 4, 'L': 3, 'E': 2, 'T': 1, 'C': 0}
with open(f'{WORK}/HD.dat', 'r', encoding='latin-1') as f:
    for line in f:
        p = line.rstrip('\n').split('|')
        if len(p) < 43: continue
        cs = p[4].strip().upper()
        if not cs: continue
        status = p[5].strip()
        new_rec = {
            'status': status,
            'grant_date': parse_date(p[7]),
            'expiry_date': parse_date(p[8]),
            'cancel_date': parse_date(p[9]),
            'last_action': parse_date(p[42]),
        }
        existing = hd.get(cs)
        if not existing:
            hd[cs] = new_rec
        else:
            # Keep highest priority status
            new_pri = STATUS_PRIORITY.get(status, 0)
            old_pri = STATUS_PRIORITY.get(existing['status'], 0)
            if new_pri > old_pri:
                hd[cs] = new_rec
            elif new_pri == old_pri and new_rec['expiry_date'] and existing['expiry_date']:
                if new_rec['expiry_date'] > existing['expiry_date']:
                    hd[cs] = new_rec
log(f"  {len(hd)} HD records loaded")

# Load EN - entity/contact info
log("Loading EN.dat...")
en = {}
with open(f'{WORK}/EN.dat', 'r', encoding='latin-1') as f:
    for line in f:
        p = line.rstrip('\n').split('|')
        if len(p) < 20: continue
        if p[5].strip() != 'L': continue
        cs = p[4].strip().upper()
        if not cs: continue
        fname = p[8].strip()
        lname = p[10].strip()
        ename = p[7].strip()
        name = ' '.join(filter(None, [fname, lname])) or ename
        en[cs] = {
            'name': name.strip(),
            'phone': p[12].strip() or None,
            'email': p[14].strip() or None,
            'address': p[15].strip() or None,
            'city': p[16].strip() or None,
            'state': p[17].strip() or None,
            'zip': p[18].strip() or None,
        }
log(f"  {len(en)} EN records loaded")

# Load AM - amateur class
log("Loading AM.dat...")
am = {}
with open(f'{WORK}/AM.dat', 'r', encoding='latin-1') as f:
    for line in f:
        p = line.rstrip('\n').split('|')
        if len(p) < 6: continue
        cs = p[4].strip().upper()
        if cs: am[cs] = p[5].strip()
log(f"  {len(am)} AM records loaded")

# Merge and insert
log("Merging and inserting into fcc_licenses...")
cur.execute("TRUNCATE TABLE fcc_licenses")
# Disable indexes for faster bulk insert
cur.execute("ALTER TABLE fcc_licenses DISABLE KEYS")
cur.execute("SET FOREIGN_KEY_CHECKS=0")
cur.execute("SET UNIQUE_CHECKS=0")
cur.execute("SET autocommit=0")

sql = """INSERT INTO fcc_licenses
    (callsign, licensee_name, license_status, license_class,
     grant_date, expiry_date, cancellation_date, last_action_date,
     email, phone, street_address, city, state, zip_code, previous_callsign)
    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
    ON DUPLICATE KEY UPDATE
    licensee_name=VALUES(licensee_name), license_status=VALUES(license_status),
    license_class=VALUES(license_class), grant_date=VALUES(grant_date),
    expiry_date=VALUES(expiry_date), cancellation_date=VALUES(cancellation_date),
    last_action_date=VALUES(last_action_date), email=VALUES(email),
    phone=VALUES(phone), street_address=VALUES(street_address),
    city=VALUES(city), state=VALUES(state), zip_code=VALUES(zip_code), previous_callsign=VALUES(previous_callsign)"""

batch = []
count = 0
for cs, h in hd.items():
    e = en.get(cs, {})
    batch.append((
        cs[:20], (e.get('name','') or '')[:255], h['status'][:10], (am.get(cs,'') or '')[:10],
        h['grant_date'], h['expiry_date'], h['cancel_date'], h['last_action'],
        (e.get('email') or '')[:50] or None, (e.get('phone') or '')[:10] or None,
        (e.get('address') or '')[:100] or None, (e.get('city') or '')[:50] or None,
        (e.get('state') or '')[:2] or None, (e.get('zip') or '')[:15] or None,
        None
    ))
    if len(batch) >= 50000:
        try:
            cur.executemany(sql, batch)
            conn.commit()
            count += len(batch)
            batch = []
            if count % 100000 == 0: log(f"  {count:,} records inserted...")
        except Exception as e:
            log(f"  ERROR in batch at {count}: {e}")
            conn.rollback()
            # Try inserting one by one to identify bad records
            for row in batch:
                try:
                    cur.execute(sql, row)
                    conn.commit()
                    count += 1
                except Exception as e2:
                    log(f"  Skipped record {row[0]}: {e2}")
            batch = []

if batch:
    try:
        cur.executemany(sql, batch)
        conn.commit()
        count += len(batch)
    except Exception as e:
        log(f"  ERROR in final batch: {e}")
        conn.rollback()
        for row in batch:
            try:
                cur.execute(sql, row)
                conn.commit()
                count += 1
            except Exception as e2:
                log(f"  Skipped record {row[0]}: {e2}")

# Re-enable indexes and constraints
cur.execute("ALTER TABLE fcc_licenses ENABLE KEYS")
cur.execute("SET FOREIGN_KEY_CHECKS=1")
cur.execute("SET UNIQUE_CHECKS=1")
cur.execute("SET autocommit=1")
conn.commit()
cur.execute("SELECT COUNT(*) FROM fcc_licenses")
total = cur.fetchone()[0]
log(f"Import complete! {total:,} licenses in database")
conn.close()
