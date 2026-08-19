<?php
/**
 * ORSI Silent Key Checker
 * Checks all active trustees against QRZ.com for SK status
 * Run weekly via cron - notifies coordinators of new SKs found
 */
require_once __DIR__ . '/../includes/config.php';
$db = get_db();

function log_msg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
}

log_msg("=== ORSI Silent Key Check Started ===");

// Get QRZ session
$session = @file_get_contents("https://xmldata.qrz.com/xml/current/?username=w5dro&password=DroLeo123");
$sxml = simplexml_load_string($session);
$key = (string)($sxml->Session->Key ?? '');
if (!$key) {
    log_msg("ERROR: QRZ auth failed");
    exit(1);
}
log_msg("QRZ authenticated OK");

// Get all unique active trustees not already on hold
$trustees = $db->query("
    SELECT DISTINCT UPPER(TRIM(r.trustee)) as trustee,
           GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
           ANY_VALUE(r.district) as district
    FROM repeaters r
    WHERE r.archived_at IS NULL
    AND r.trustee != ''
    AND r.status NOT IN ('DEAD','DECOORDINATED','ADMIN HOLD - HOLDER DECEASED','ADMIN HOLD - LICENSE EXPIRED')
    GROUP BY UPPER(TRIM(r.trustee))
    ORDER BY trustee
")->fetchAll();

log_msg("Checking " . count($trustees) . " trustees...");

$new_sk = [];
$errors = 0;

foreach ($trustees as $t) {
    $callsign = $t['trustee'];
    $xml = @file_get_contents("https://xmldata.qrz.com/xml/current/?s={$key}&callsign={$callsign}");
    if (!$xml) {
        log_msg("  WARNING: Could not fetch QRZ data for {$callsign}");
        $errors++;
        usleep(500000);
        continue;
    }

    $data = simplexml_load_string($xml);
    $call_field = (string)($data->Callsign->call ?? '');
    $qslmgr = strtolower((string)($data->Callsign->qslmgr ?? ''));

    $is_sk = str_contains(strtolower($call_field), '/sk') ||
             preg_match('/\bsk\b.*\d{4}/i', $qslmgr) ||
             preg_match('/sk\s+(january|february|march|april|may|june|july|august|september|october|november|december)/i', $qslmgr);

    if ($is_sk) {
        log_msg("  SK FOUND: {$callsign} — {$call_field} — {$qslmgr}");
        $new_sk[] = [
            'trustee'   => $callsign,
            'call_field'=> $call_field,
            'qslmgr'    => $qslmgr,
            'repeaters' => $t['repeaters'],
            'district'  => $t['district'],
        ];

        // Update repeater status
        $deadline = date('Y-m-d', strtotime('+60 days'));
        $db->prepare("UPDATE repeaters 
            SET status='ADMIN HOLD - HOLDER DECEASED',
                hold_reason='Trustee confirmed Silent Key via QRZ.com: ' || ?,
                hold_date=CURDATE(),
                hold_deadline=?,
                hold_notes='Automated SK detection. Club/estate has 60 days to designate new trustee.'
            WHERE UPPER(TRIM(trustee))=?
            AND archived_at IS NULL
            AND status NOT IN ('DEAD','DECOORDINATED','ADMIN HOLD - HOLDER DECEASED')")
           ->execute([$call_field, $deadline, $callsign]);

        // Log action for each affected repeater
        $reps = $db->prepare("SELECT id, callsign FROM repeaters WHERE UPPER(TRIM(trustee))=? AND archived_at IS NULL");
        $reps->execute([$callsign]);
        foreach ($reps->fetchAll() as $rep) {
            $db->prepare("INSERT INTO coordination_actions 
                (repeater_id, action_type, performed_by, notice_method, deadline_date, notes)
                VALUES (?,?,NULL,?,?,?)")
               ->execute([$rep['id'], 'HOLDER_DECEASED', 'EMAIL', $deadline,
                 "Trustee {$callsign} confirmed SK via QRZ ({$call_field}). Coordinators notified."]);
        }
    }

    usleep(200000); // 0.2s delay to avoid QRZ rate limiting
}

log_msg("Check complete. New SKs found: " . count($new_sk) . ", Errors: {$errors}");

// Send notifications if new SKs found
if (!empty($new_sk)) {
    $admins = $db->query("SELECT email, callsign FROM users WHERE role='admin' AND active=1 AND email!=''")->fetchAll();
    $admin_emails = array_column($admins, 'email');

    // Group by district
    $by_district = [];
    foreach ($new_sk as $sk) {
        $by_district[$sk['district']][] = $sk;
    }

    foreach ($by_district as $district => $sks) {
        $coord_emails = get_all_coordinator_emails($district);
        $deadline = date('Y-m-d', strtotime('+60 days'));

        $body = "ORSI Silent Key Alert — New SKs Detected\n";
        $body .= str_repeat("=", 50) . "\n\n";
        $body .= "The following trustee(s) in the {$district} district have been\n";
        $body .= "identified as Silent Key via QRZ.com and placed on ADMIN HOLD.\n\n";

        foreach ($sks as $sk) {
            $body .= "Trustee: {$sk['trustee']}\n";
            $body .= "  QRZ: {$sk['call_field']}\n";
            $body .= "  Repeaters: {$sk['repeaters']}\n";
            $body .= "  60-day deadline: {$deadline}\n\n";
        }

        $body .= "Required Actions:\n";
        $body .= "1. Contact club/estate/known repeater contacts\n";
        $body .= "2. Allow 60 days for trustee change\n";
        $body .= "3. Require FCC license to show replacement trustee\n";
        $body .= "4. If no response, proceed with de-coordination\n\n";
        $body .= "View: " . ORG_URL . "/admin/license_review.php\n\n73,\nORSI System";

        $subject = "ORSI Silent Key Alert — {$district} District — " . count($sks) . " new SK(s)";

        $notified = [];
        foreach (array_merge($coord_emails, $admin_emails) as $email) {
            if (!in_array($email, $notified)) {
                $sent = orsi_mail($email, $subject, $body);
                log_msg("  Notified {$email}: " . ($sent ? "OK" : "FAILED"));
                $notified[] = $email;
            }
        }
    }
}

// ── Check for trustees missing from FCC database ─────────────
log_msg("Checking for trustees missing from FCC database...");
$missing = $db->query("
    SELECT DISTINCT UPPER(TRIM(r.trustee)) as trustee,
           GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
           ANY_VALUE(r.district) as district
    FROM repeaters r
    LEFT JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.trustee != ''
    AND r.status NOT IN ('DEAD','DECOORDINATED','ADMIN HOLD - HOLDER DECEASED','ADMIN HOLD - LICENSE EXPIRED')
    AND f.callsign IS NULL
    GROUP BY UPPER(TRIM(r.trustee))
    ORDER BY trustee
")->fetchAll();

if (!empty($missing)) {
    log_msg("Found " . count($missing) . " trustees missing from FCC database!");
    
    // Try QRZ lookup for each missing trustee
    $still_missing = [];
    foreach ($missing as $m) {
        $xml = @file_get_contents("https://xmldata.qrz.com/xml/current/?s={$key}&callsign={$m['trustee']}");
        if ($xml) {
            $data = simplexml_load_string($xml);
            $call_field = (string)($data->Callsign->call ?? '');
            if ($call_field) {
                // Found on QRZ - insert into fcc_licenses
                $expdate = (string)($data->Callsign->expdate ?? '');
                $efdate  = (string)($data->Callsign->efdate ?? '');
                $class   = (string)($data->Callsign->class ?? '');
                $name    = trim((string)($data->Callsign->fname ?? '') . ' ' . (string)($data->Callsign->name ?? ''));
                $email   = (string)($data->Callsign->email ?? '');
                
                $db->prepare("INSERT INTO fcc_licenses (callsign, licensee_name, license_status, license_class, expiry_date, grant_date, email)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE licensee_name=VALUES(licensee_name), license_status=VALUES(license_status),
                    expiry_date=VALUES(expiry_date), email=VALUES(email)")
                   ->execute([
                       $m['trustee'], $name, 'A', $class,
                       $expdate ?: null, $efdate ?: null, $email ?: null
                   ]);
                log_msg("  {$m['trustee']} — found on QRZ, added to database");
            } else {
                $still_missing[] = $m;
                log_msg("  {$m['trustee']} — NOT found on QRZ either!");
            }
        }
        usleep(200000);
    }

    // Alert admins about callsigns not found anywhere
    if (!empty($still_missing)) {
        $admins = $db->query("SELECT email FROM users WHERE role='admin' AND active=1 AND email!=''")->fetchAll();
        $body = "ORSI Weekly Check — Trustees Not Found in FCC Database
";
        $body .= str_repeat("=", 50) . "

";
        $body .= "The following trustees could not be found in the FCC database OR QRZ.com.
";
        $body .= "These may be invalid callsigns, expired/cancelled licenses not in our FCC import, or data entry errors.

";
        foreach ($still_missing as $m) {
            $body .= "{$m['trustee']} — Repeaters: {$m['repeaters']} — District: {$m['district']}
";
        }
        $body .= "
Please verify these callsigns manually at:
";
        $body .= "https://wireless2.fcc.gov/UlsApp/UlsSearch/searchLicense.jsp

";
        $body .= "View FCC Check: " . ORG_URL . "/admin/fcc_check.php

73,
ORSI System";

        foreach ($admins as $admin) {
            orsi_mail($admin['email'], 
                'ORSI Alert: ' . count($still_missing) . ' Trustees Not Found in FCC Database',
                $body);
        }
        log_msg("Alert sent to admins for " . count($still_missing) . " missing trustees");
    }
} else {
    log_msg("All trustees found in FCC database - OK!");
}

log_msg("=== ORSI Silent Key Check Complete ===");
