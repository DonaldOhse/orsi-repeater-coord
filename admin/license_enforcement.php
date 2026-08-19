<?php
/**
 * ORSI License Enforcement Workflow
 * Handles expired licenses, deceased holders, trustee changes
 * Run via cron or manually from admin panel
 */
require_once __DIR__ . '/../includes/config.php';
$db = get_db();
$is_cli = (php_sapi_name() === 'cli');
$dry_run = isset($_GET['dry_run']) || in_array('--dry-run', $argv ?? []);

function log_action(PDO $db, int $repeater_id, string $action_type, string $notes, 
                     ?string $email_to = null, ?string $deadline = null, int $performed_by = 0): void {
    $db->prepare("INSERT INTO coordination_actions 
        (repeater_id, action_type, performed_by, notes, email_sent_to, deadline_date)
        VALUES (?,?,?,?,?,?)")
       ->execute([$repeater_id, $action_type, $performed_by ?: null, $notes, $email_to, $deadline]);
}

function out(string $msg): void {
    global $is_cli;
    if ($is_cli) echo $msg . "\n";
    else echo nl2br(htmlspecialchars($msg)) . "<br>";
}

$stats = ['expired'=>0,'warning'=>0,'decoord'=>0,'trustee'=>0,'emails'=>0,'errors'=>0];

out("=== ORSI License Enforcement Run: " . date('Y-m-d H:i:s') . " ===");
if ($dry_run) out("** DRY RUN MODE - No changes will be made **");

// ── 1. Find repeaters with expired trustee licenses ──────────────
$expired = $db->query("
    SELECT r.id, r.callsign, r.trustee, r.status, r.district, r.city, r.county,
           r.contact_email, r.contact_name, r.hold_date, r.hold_deadline,
           f.licensee_name, f.license_status, f.expiry_date,
           f.email as fcc_email, f.phone as fcc_phone,
           f.street_address, f.city as fcc_city, f.state as fcc_state, f.zip_code,
           DATEDIFF(CURDATE(), f.expiry_date) as days_expired,
           DATEDIFF(r.hold_deadline, CURDATE()) as days_until_deadline
    FROM repeaters r
    JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED','UNCOORDINATED','ADMIN HOLD - HOLDER DECEASED')
    AND (f.expiry_date < CURDATE() OR f.license_status IN ('C','T'))
    ORDER BY days_expired DESC
")->fetchAll();

out("\nFound " . count($expired) . " repeaters with expired/cancelled trustee licenses");

foreach ($expired as $rep) {
    $cs = $rep['callsign'];
    $trustee = $rep['trustee'];
    $days_exp = (int)$rep['days_expired'];
    $deadline = $rep['hold_deadline'];
    $days_left = $deadline ? (int)$rep['days_until_deadline'] : null;
    $email = $rep['contact_email'] ?: $rep['fcc_email'];
    $name = $rep['contact_name'] ?: $rep['licensee_name'];

    out("\n--- $cs (Trustee: $trustee, Expired: {$rep['expiry_date']}, $days_exp days ago) ---");

    // ── Case A: Already on hold — check deadline ─────────────────
    if (str_starts_with($rep['status'], 'ADMIN HOLD')) {
        if ($days_left === null) {
            out("  WARNING: On hold but no deadline set — setting 60-day deadline");
            if (!$dry_run) {
                $new_deadline = date('Y-m-d', strtotime('+60 days'));
                $db->prepare("UPDATE repeaters SET hold_deadline=? WHERE id=?")
                   ->execute([$new_deadline, $rep['id']]);
            }
        } elseif ($days_left <= 0) {
            // Deadline passed — send de-coordination notice
            out("  DEADLINE PASSED — Sending de-coordination notice");
            $subject = "ORSI Notice of Proposed De-Coordination — {$cs}";
            $body = "Dear {$name},\n\n"
                . "This is formal notice that the Oklahoma Repeater Society, Inc. (ORSI) "
                . "is proceeding with de-coordination of repeater {$cs} on {$rep['city']}, {$rep['county']} County.\n\n"
                . "REASON: The FCC license for trustee {$trustee} expired on {$rep['expiry_date']} "
                . "({$days_exp} days ago) and has not been renewed despite previous notices.\n\n"
                . "DE-COORDINATION DATE: " . date('Y-m-d', strtotime('+30 days')) . "\n\n"
                . "RIGHT TO APPEAL: You may appeal this decision to the ORSI Frequency Coordination "
                . "Oversight Committee within 30 days by contacting " . MAIL_REPLY_TO . ".\n\n"
                . "To prevent de-coordination, you must provide proof of license renewal or "
                . "appointment of a licensed trustee before the de-coordination date.\n\n"
                . "This notice has been sent by email and will be followed by first-class mail "
                . "to the address on file with the FCC.\n\n"
                . "Sincerely,\nOklahoma Repeater Society, Inc.\n" . MAIL_REPLY_TO;

            if (!$dry_run && $email) {
                orsi_mail($email, $subject, $body);
                $stats['emails']++;
                log_action($db, $rep['id'], 'DECOORD_NOTICE', 
                    "De-coordination notice sent. License expired {$days_exp} days ago.",
                    $email, date('Y-m-d', strtotime('+30 days')));
            }
            out("  De-coordination notice sent to: $email");
            $stats['decoord']++;

        } elseif ($days_left <= 15) {
            // Final warning
            out("  FINAL WARNING: $days_left days until de-coordination");
            $subject = "FINAL NOTICE: ORSI De-Coordination in {$days_left} Days — {$cs}";
            $body = "Dear {$name},\n\n"
                . "FINAL NOTICE: You have {$days_left} days remaining to resolve the expired "
                . "FCC license for repeater {$cs} before ORSI proceeds with de-coordination.\n\n"
                . "Trustee: {$trustee}\n"
                . "License expired: {$rep['expiry_date']} ({$days_exp} days ago)\n"
                . "De-coordination deadline: {$deadline}\n\n"
                . "To prevent de-coordination:\n"
                . "1. Renew your FCC license at https://wireless2.fcc.gov/\n"
                . "2. Or appoint a licensed trustee and notify ORSI at " . MAIL_REPLY_TO . "\n\n"
                . "Sincerely,\nOklahoma Repeater Society, Inc.\n" . MAIL_REPLY_TO;

            if (!$dry_run && $email) {
                orsi_mail($email, $subject, $body);
                $stats['emails']++;
                log_action($db, $rep['id'], 'DEADLINE_WARNING',
                    "Final warning sent. {$days_left} days until deadline.",
                    $email, $deadline);
            }
            out("  Final warning sent to: $email");
            $stats['warning']++;

        } elseif ($days_left <= 30) {
            // 30-day warning
            out("  30-day warning: $days_left days remaining");
            $subject = "ORSI License Warning: {$days_left} Days Remaining — {$cs}";
            $body = "Dear {$name},\n\n"
                . "This is a reminder that repeater {$cs} is currently on Administrative Hold "
                . "due to an expired FCC license for trustee {$trustee}.\n\n"
                . "You have {$days_left} days remaining ({$deadline}) to resolve this issue "
                . "before ORSI sends a formal de-coordination notice.\n\n"
                . "To resolve:\n"
                . "1. Renew your FCC license at https://wireless2.fcc.gov/\n"
                . "2. Or appoint a licensed trustee\n"
                . "3. Notify ORSI at " . MAIL_REPLY_TO . " when resolved\n\n"
                . "Sincerely,\nOklahoma Repeater Society, Inc.\n" . MAIL_REPLY_TO;

            if (!$dry_run && $email) {
                orsi_mail($email, $subject, $body);
                $stats['emails']++;
                log_action($db, $rep['id'], 'DEADLINE_WARNING',
                    "30-day warning sent. {$days_left} days until deadline.",
                    $email, $deadline);
            }
            out("  30-day warning sent to: $email");
            $stats['warning']++;
        } else {
            out("  On hold, {$days_left} days remaining — no action needed");
        }

    } else {
        // ── Case B: Not yet on hold — set to ADMIN HOLD ──────────
        out("  NEW: Setting to ADMIN HOLD - LICENSE EXPIRED");
        $new_deadline = date('Y-m-d', strtotime('+60 days'));
        $hold_reason = "FCC license for trustee {$trustee} expired on {$rep['expiry_date']} ({$days_exp} days ago). "
            . "Status: " . ($rep['license_status'] === 'C' ? 'CANCELLED' : 'EXPIRED') . ".";

        if (!$dry_run) {
            $db->prepare("UPDATE repeaters SET 
                status='ADMIN HOLD - LICENSE EXPIRED',
                hold_reason=?, hold_date=CURDATE(), hold_deadline=?,
                hold_notes='Automated enforcement. Initial notice sent.'
                WHERE id=?")
               ->execute([$hold_reason, $new_deadline, $rep['id']]);
            log_action($db, $rep['id'], 'LICENSE_EXPIRED',
                $hold_reason, null, $new_deadline);
        }

        // Send initial notice
        $subject = "ORSI Administrative Hold — License Expired — {$cs}";
        $body = "Dear {$name},\n\n"
            . "The Oklahoma Repeater Society, Inc. (ORSI) has placed repeater {$cs} "
            . "on Administrative Hold due to an expired FCC license.\n\n"
            . "DETAILS:\n"
            . "Repeater: {$cs} — {$rep['city']}, {$rep['county']} County\n"
            . "Trustee: {$trustee} ({$rep['licensee_name']})\n"
            . "License Status: " . ($rep['license_status'] === 'C' ? 'CANCELLED' : 'EXPIRED') . "\n"
            . "Expiration Date: {$rep['expiry_date']}\n"
            . "Days Since Expiration: {$days_exp}\n\n"
            . "WHAT THIS MEANS:\n"
            . "Your coordination remains reserved for 60 days ({$new_deadline}).\n"
            . "The repeater frequency will not be reassigned during this period.\n\n"
            . "TO RESOLVE THIS ISSUE:\n"
            . "1. Renew your FCC license at https://wireless2.fcc.gov/\n"
            . "2. Or appoint a new licensed trustee\n"
            . "3. Notify ORSI at " . MAIL_REPLY_TO . " with proof of resolution\n\n"
            . "If unresolved by {$new_deadline}, ORSI will send a formal Notice of "
            . "Proposed De-Coordination. You will have the right to appeal to the "
            . "ORSI Frequency Coordination Oversight Committee.\n\n"
            . "This notice is being sent to the email address on file. "
            . "For coordination disputes, you may also send written correspondence to:\n"
            . ORG_NAME . "\n\n"
            . "Sincerely,\nOklahoma Repeater Society, Inc.\n" . MAIL_REPLY_TO . "\n"
            . ORG_URL;

        if (!$dry_run && $email) {
            orsi_mail($email, $subject, $body);
            $stats['emails']++;
            log_action($db, $rep['id'], 'NOTICE_SENT',
                "Initial hold notice sent. 60-day clock started.",
                $email, $new_deadline);
        }
        out("  Initial notice sent to: " . ($email ?: 'NO EMAIL ON FILE'));
        if (!$email) out("  WARNING: No email address — manual contact required!");
        $stats['expired']++;
    }
}

// ── 2. Check for callsign changes (vanity) ────────────────────
$cs_changes = $db->query("
    SELECT r.id, r.callsign, r.trustee, r.district, r.city,
           f.callsign as new_callsign, f.licensee_name, f.expiry_date
    FROM repeaters r
    JOIN fcc_licenses f ON f.previous_callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND f.license_status = 'A'
    AND r.status NOT IN ('DEAD','DECOORDINATED')
")->fetchAll();

if (!empty($cs_changes)) {
    out("\n\nFound " . count($cs_changes) . " repeaters with trustee callsign changes:");
    foreach ($cs_changes as $rep) {
        out("  {$rep['callsign']}: trustee {$rep['trustee']} → {$rep['new_callsign']} ({$rep['licensee_name']})");
        if (!$dry_run) {
            $db->prepare("UPDATE repeaters SET 
                status='TRUSTEE CHANGE REQUIRED',
                hold_reason=?, hold_date=CURDATE(), hold_deadline=?
                WHERE id=? AND status NOT IN ('TRUSTEE CHANGE REQUIRED','ADMIN HOLD - LICENSE EXPIRED','ADMIN HOLD - HOLDER DECEASED')")
               ->execute([
                   "Trustee callsign changed from {$rep['trustee']} to {$rep['new_callsign']}. Administrative update required.",
                   date('Y-m-d', strtotime('+60 days')),
                   $rep['id']
               ]);
            if ($db->rowCount() > 0) {
                log_action($db, $rep['id'], 'TRUSTEE_CHANGE_REQUIRED',
                    "Callsign change detected: {$rep['trustee']} → {$rep['new_callsign']}");
                $stats['trustee']++;
            }
        }
    }
}

// ── Summary ───────────────────────────────────────────────────
out("\n=== Summary ===");
out("New holds set: {$stats['expired']}");
out("Warnings sent: {$stats['warning']}");
out("De-coord notices: {$stats['decoord']}");
out("Trustee changes flagged: {$stats['trustee']}");
out("Emails sent: {$stats['emails']}");
if ($dry_run) out("** DRY RUN - No changes were made **");

// Email summary to admins
if (!$dry_run && !$is_cli && ($stats['expired'] + $stats['decoord'] + $stats['trustee']) > 0) {
    $admins = $db->query("SELECT email FROM users WHERE role='admin' AND active=1 AND email != ''")->fetchAll();
    $summary = "ORSI License Enforcement Run — " . date('Y-m-d') . "\n\n"
        . "New holds: {$stats['expired']}\n"
        . "Warnings: {$stats['warning']}\n"
        . "De-coord notices: {$stats['decoord']}\n"
        . "Trustee changes: {$stats['trustee']}\n"
        . "Emails sent: {$stats['emails']}\n\n"
        . "View details at: " . ORG_URL . BASE_PATH . "/admin/license_enforcement.php";
    foreach ($admins as $admin) {
        orsi_mail($admin['email'], 'ORSI License Enforcement Report — ' . date('Y-m-d'), $summary);
    }
}
