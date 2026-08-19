<?php
require_once __DIR__ . '/../includes/config.php';


$db = get_db();
$org_name = get_setting('org_name', ORG_NAME);
$org_url  = get_setting('org_url',  ORG_URL);
$base_url = 'https://w5dro.com/repeater_coord';

// Can be run from browser by admins or from cron
$is_cron = php_sapi_name() === 'cli';
if (!$is_cron) {
    require_role('admin');
    $page_title = 'Send Renewal Notices';
    include __DIR__ . '/../includes/header.php';
}

$dry_run = isset($_GET['dry_run']) || (isset($argv[1]) && $argv[1] === '--dry-run');

// ── NOPC: Send 48hr reminders and mark 72hr no-responses ────
$nopc_pending = $db->query("
    SELECT n.*, r.applicant_callsign, r.suggested_freq, r.preferred_freq,
           r.city, r.county, r.req_band, r.id as req_id
    FROM nopc_notifications n
    JOIN coordination_requests r ON r.id = n.request_id
    WHERE n.status = 'PENDING'
")->fetchAll();

$nopc_reminder_tpl = get_template($db, 'nopc_reminder');
$nopc_expired_tpl  = get_template($db, 'nopc_expired');

foreach ($nopc_pending as $n) {
    $hours_since = (time() - strtotime($n['sent_at'])) / 3600;
    $hours_left  = (strtotime($n['expires_at']) - time()) / 3600;
    $freq        = $n['suggested_freq'] ?: $n['preferred_freq'];
    $approve_url = "{$base_url}/nopc_response.php?token={$n['token']}&action=approve";
    $decline_url = "{$base_url}/nopc_response.php?token={$n['token']}&action=decline";

    // Send 48hr reminder
    if ($hours_since >= 48 && !$n['reminder_sent'] && $hours_left > 0) {
        if (!$dry_run) {
            $rendered = render_template($nopc_reminder_tpl, [
                '{callsign}'     => $n['applicant_callsign'],
                '{freq}'         => $freq,
                '{city}'         => $n['city'],
                '{county}'       => $n['county'],
                '{approve_link}' => $approve_url,
                '{decline_link}' => $decline_url,
                '{deadline}'     => date('Y-m-d H:i', strtotime($n['expires_at'])),
                '{org_name}'     => $org_name,
                '{org_url}'      => $org_url,
            ]);
            orsi_mail($n['contact_email'], $rendered['subject'], $rendered['body'], MAIL_FROM);
            $db->prepare("UPDATE nopc_notifications SET reminder_sent=1, reminder_sent_at=NOW() WHERE id=?")->execute([$n['id']]);
        }
        if($is_cron) echo date('Y-m-d H:i:s') . " NOPC reminder sent to {$n['state']} for {$n['applicant_callsign']}\n";
    }

    // Auto-proceed after 72hrs
    if ($hours_left <= 0) {
        if (!$dry_run) {
            $db->prepare("UPDATE nopc_notifications SET status='NO_RESPONSE', response_at=NOW() WHERE id=?")->execute([$n['id']]);
            $all_emails = get_all_coordinator_emails('OKC');
            $rendered = render_template($nopc_expired_tpl, [
                '{callsign}'  => $n['applicant_callsign'],
                '{freq}'      => $freq,
                '{state}'     => $n['state'],
                '{sent_date}' => substr($n['sent_at'], 0, 10),
                '{exp_date}'  => substr($n['expires_at'], 0, 10),
                '{req_url}'   => "{$base_url}/admin/requests.php?id={$n['req_id']}",
                '{org_name}'  => $org_name,
                '{org_url}'   => $org_url,
            ]);
            foreach ($all_emails as $email) {
                orsi_mail($email, $rendered['subject'], $rendered['body'], MAIL_FROM);
            }
        }
        if($is_cron) echo date('Y-m-d H:i:s') . " NOPC expired - {$n['state']} no response for {$n['applicant_callsign']}\n";
    }
}

// ── Nudge PROPOSED repeaters stale for 1+ year ──────────────
$stale_proposed = $db->query("
    SELECT id, callsign, trustee, contact_email, contact_name, district,
           date_coordinated, last_update
    FROM repeaters
    WHERE archived_at IS NULL
    AND status = 'PROPOSED'
    AND (last_update < DATE_SUB(NOW(), INTERVAL 1 YEAR) OR last_update IS NULL)
    AND date_coordinated < DATE_SUB(NOW(), INTERVAL 1 YEAR)
")->fetchAll();

$proposed_tpl = get_template($db, 'proposed_nudge');

foreach ($stale_proposed as $rep) {
    if($is_cron) echo date('Y-m-d H:i:s') . " PROPOSED stale: {$rep['callsign']} ({$rep['trustee']}) last update: {$rep['last_update']}\n";

    if (!$dry_run) {
        $name = $rep['contact_name'] ?: $rep['trustee'];

        // Email trustee if we have their address
        if ($rep['contact_email']) {
            $rendered = render_template($proposed_tpl, [
                '{callsign}'        => $rep['callsign'],
                '{contact_name}'    => $name,
                '{date_coordinated}'=> $rep['date_coordinated'],
                '{last_update}'     => $rep['last_update'],
                '{update_url}'      => "{$base_url}/update_request.php?call=" . urlencode($rep['callsign']),
                '{org_name}'        => $org_name,
                '{org_url}'         => $org_url,
            ]);
            orsi_mail($rep['contact_email'], $rendered['subject'], $rendered['body'], MAIL_FROM);
        }

        // Notify district coordinator
        $contact_str = $rep['contact_email'] ?: 'No email on file';
        $nudge_str   = $rep['contact_email'] ? "A nudge email has been sent to the trustee." : "No contact email - manual follow-up may be needed.";
        $coord_emails = get_all_coordinator_emails($rep['district'] ?? 'OKC');
        $coord_subject = "Stale PROPOSED Repeater - {$rep['callsign']}";
        $coord_body  = "The following repeater has been in PROPOSED status for over 1 year:\n\n";
        $coord_body .= "  Callsign:    {$rep['callsign']}\n";
        $coord_body .= "  Trustee:     {$rep['trustee']}\n";
        $coord_body .= "  District:    {$rep['district']}\n";
        $coord_body .= "  Coordinated: {$rep['date_coordinated']}\n";
        $coord_body .= "  Last Update: {$rep['last_update']}\n";
        $coord_body .= "  Contact:     {$contact_str}\n\n";
        $coord_body .= "{$nudge_str}\n\n";
        $coord_body .= "Review: {$base_url}/admin/edit_repeater.php?id={$rep['id']}\n\n73,\nORSI System\n";
        foreach ($coord_emails as $em) {
            orsi_mail($em, $coord_subject, $coord_body, MAIL_FROM);
        }

        $db->prepare("UPDATE repeaters SET last_update=CURDATE() WHERE id=?")->execute([$rep['id']]);
    }
}
if($is_cron) echo date('Y-m-d H:i:s') . " Stale PROPOSED check: " . count($stale_proposed) . " repeaters\n";

// ── Mark repeaters as Unknown after 5 years without renewal ──
$expired = $db->query("
    SELECT id, callsign, output_freq, status, district,
           GREATEST(
               COALESCE(last_renewal_sent, '1900-01-01'),
               COALESCE(last_update,       '1900-01-01'),
               COALESCE(date_coordinated,  '1900-01-01')
           ) AS last_active
    FROM repeaters
    WHERE archived_at IS NULL
    AND status = 'OPERATIONAL'
    AND COALESCE(last_renewal_sent, '1900-01-01') < DATE_SUB(CURDATE(), INTERVAL 5 YEAR)
    AND COALESCE(last_update,       '1900-01-01') < DATE_SUB(CURDATE(), INTERVAL 5 YEAR)
    AND COALESCE(date_coordinated,  '1900-01-01') < DATE_SUB(CURDATE(), INTERVAL 5 YEAR)
    AND private = 0
")->fetchAll();

$marked_unknown = 0;
foreach ($expired as $r) {
    if (!$dry_run) {
        $db->prepare("UPDATE repeaters SET status='UNKNOWN', last_update=CURDATE() WHERE id=?")->execute([$r['id']]);
        audit('AUTO_STATUS', 'repeaters', $r['id'], ['status'=>'OPERATIONAL'], ['status'=>'UNKNOWN', 'reason'=>'No renewal in 5 years']);
        $coord_email = get_coordinator_email($r['district'] ?? 'OKC');
        if ($coord_email) {
            orsi_mail($coord_email,
                "Auto-Status Change: {$r['callsign']} moved to UNKNOWN",
                "The following repeater has been automatically moved to UNKNOWN status due to no renewal in 5 years:\n\n" .
                "Callsign:  {$r['callsign']}\nFrequency: {$r['output_freq']} MHz\nLast Active: {$r['last_active']}\n\n" .
                "View: {$base_url}/repeater.php?id={$r['id']}\n\n73,\nORSI System",
                MAIL_FROM
            );
        }
    }
    $marked_unknown++;
}

// ── Send annual renewal emails ────────────────────────────────
$due_raw = $db->query("
    SELECT *
    FROM repeaters
    WHERE archived_at IS NULL
    AND status IN ('OPERATIONAL','DOWN TEMPORARILY')
    AND private = 0
    AND contact_email IS NOT NULL
    AND contact_email != ''
    AND (last_renewal_sent IS NULL OR last_renewal_sent < DATE_SUB(CURDATE(), INTERVAL 11 MONTH))
    ORDER BY contact_email, output_freq
")->fetchAll();

// Group by contact_email - one email per trustee listing ALL their repeaters
$due_grouped = [];
foreach ($due_raw as $r) {
    $due_grouped[$r['contact_email']][] = $r;
}

$renewal_tpl = get_template($db, 'renewal');
$sent = 0;
$skipped = 0;

foreach ($due_grouped as $trustee_email => $repeaters) {
    $r     = $repeaters[0];
    $count = count($repeaters);
    $name  = $r['contact_name'] ?: ($r['trustee'] ?: 'Repeater Trustee');

    // Generate token and renewal link for each repeater
    $renewal_links = [];
    foreach ($repeaters as $rep) {
        $token     = bin2hex(random_bytes(32));
        $token_exp = date('Y-m-d', strtotime('+30 days'));
        if (!$dry_run) {
            $db->prepare("UPDATE repeaters SET renewal_token=?, renewal_token_exp=?, last_renewal_sent=CURDATE() WHERE id=?")
               ->execute([$token, $token_exp, $rep['id']]);
        }
        $renewal_links[] = "  {$rep['callsign']} - {$rep['output_freq']} MHz ({$rep['city']}, {$rep['status']})\n  {$base_url}/renewal.php?token={$token}";
    }

    $rendered = render_template($renewal_tpl, [
        '{callsign}'     => $count > 1 ? "{$count} repeaters" : $repeaters[0]['callsign'],
        '{output_freq}'  => $repeaters[0]['output_freq'],
        '{city}'         => $r['city'],
        '{county}'       => $r['county'],
        '{status}'       => $r['status'],
        '{contact_name}' => $name,
        '{renewal_link}' => implode("\n\n", $renewal_links),
        '{org_name}'     => $org_name,
        '{org_url}'      => $org_url,
    ]);

    $subject = $count > 1
        ? "Annual Renewal Notice - {$count} Repeaters - ORSI"
        : $rendered['subject'];

    if (!$dry_run) {
        $result = orsi_mail($trustee_email, $subject, $rendered['body'], MAIL_FROM);
        if ($result) $sent++;
        else $skipped++;
    } else {
        if($is_cron) echo date('Y-m-d H:i:s') . " DRY RUN renewal: {$trustee_email} ({$count} repeater(s))\n";
        $sent++;
    }
}


// ── Dead Repeater Notices ─────────────────────────────────────
$dead_due = $db->query("
    SELECT *
    FROM repeaters
    WHERE archived_at IS NULL
    AND status = 'DEAD'
    AND private = 0
    AND contact_email IS NOT NULL
    AND contact_email != ''
    AND dead_notice_response IS NULL
    AND (dead_notice_sent IS NULL OR dead_notice_sent < DATE_SUB(CURDATE(), INTERVAL 1 YEAR))
    ORDER BY contact_email, output_freq
")->fetchAll();

$dead_sent = 0;
foreach ($dead_due as $r) {
    $token = bin2hex(random_bytes(32));
    $exp   = date('Y-m-d H:i:s', strtotime('+90 days'));
    $db->prepare("UPDATE repeaters SET dead_notice_sent=NOW(), dead_notice_token=?, dead_notice_token_exp=? WHERE id=?")
       ->execute([$token, $exp, $r['id']]);

    $tone = $r['pl_tone'] ? $r['pl_tone'] . " Hz" : ($r['tone_type'] ?? 'Carrier');
    $restore_url = "https://w5dro.com" . BASE_PATH . "/dead_response.php?token={$token}&action=restore";
    $remove_url  = "https://w5dro.com" . BASE_PATH . "/dead_response.php?token={$token}&action=remove";

    $subject = "Status Update Required: {$r['callsign']} - {$r['output_freq']} MHz";
    $body  = "Dear " . ($r['contact_name'] ?: $r['trustee']) . ",\n\n";
    $body .= "Our records show the following repeater has been marked as DEAD for an extended period:\n\n";
    $body .= "  Callsign:  {$r['callsign']}\n";
    $body .= "  Output:    {$r['output_freq']} MHz\n";
    $body .= "  Input:     {$r['input_freq']} MHz\n";
    $body .= "  Access:    {$tone}\n";
    $body .= "  Location:  {$r['city']}, Oklahoma\n\n";
    $body .= "Please let us know the status by clicking one of the links below:\n\n";
    $body .= "  RESTORE - I plan to bring this repeater back on air:\n  {$restore_url}\n\n";
    $body .= "  REMOVE - Please remove this repeater from the database:\n  {$remove_url}\n\n";
    $body .= "If we do not receive a response within 90 days, this repeater will be automatically archived.\n\n";
    $body .= "73,\nOklahoma Repeater Society\nhttps://w5dro.com\n";

    if (!$dry_run) {
        orsi_mail($r['contact_email'], $subject, $body);
        $dead_sent++;
    } else {
        if ($is_cron) echo date('Y-m-d H:i:s') . " DRY RUN dead notice: {$r['contact_email']} ({$r['callsign']})\n";
        $dead_sent++;
    }
}

// Auto-archive dead repeaters with no response after 90 days
$auto_archive_due = $db->query("
    SELECT * FROM repeaters
    WHERE archived_at IS NULL
    AND status = 'DEAD'
    AND dead_notice_sent IS NOT NULL
    AND dead_notice_token_exp < NOW()
    AND dead_notice_response IS NULL
")->fetchAll();

$auto_archived = 0;
foreach ($auto_archive_due as $r) {
    if (!$dry_run) {
        $db->prepare("UPDATE repeaters SET archived_at=NOW(), archived_by=0, archived_reason='No response to dead notice after 90 days' WHERE id=?")
           ->execute([$r['id']]);
        audit('DEAD_AUTO_ARCHIVE', 'repeaters', $r['id'], ['status'=>'DEAD'], ['reason'=>'No response to dead notice']);
        $auto_archived++;
    } else {
        $auto_archived++;
    }
}

// ── Output results ────────────────────────────────────────────
if ($is_cron) {
    echo date('Y-m-d H:i:s') . " - Renewals: sent={$sent}, skipped={$skipped}, marked_unknown={$marked_unknown}, dead_notices={$dead_sent}, auto_archived={$auto_archived}\n";
} else {
    ?>
    <div class="page-title"><i class="fa fa-rotate"></i> Annual Renewal System</div>

    <?php if ($dry_run): ?>
    <div class="alert alert-warning"><i class="fa fa-eye"></i> <strong>Dry Run</strong> - No emails sent, no statuses changed.</div>
    <?php endif; ?>

    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);max-width:500px;margin-bottom:20px">
      <div class="stat-card green"><div class="stat-value"><?= $sent ?></div><div class="stat-label">Renewals <?= $dry_run?'Would Send':'Sent' ?></div></div>
      <div class="stat-card orange"><div class="stat-value"><?= $skipped ?></div><div class="stat-label">Failed</div></div>
      <div class="stat-card red"><div class="stat-value"><?= $marked_unknown ?></div><div class="stat-label">Moved to Unknown</div></div>
      <div class="stat-card amber"><div class="stat-value"><?= $dead_sent ?></div><div class="stat-label">Dead Notices <?= $dry_run?'Would Send':'Sent' ?></div></div>
      <div class="stat-card orange"><div class="stat-value"><?= $auto_archived ?></div><div class="stat-label">Auto-Archived</div></div>
    </div>

    <?php if ($marked_unknown > 0): ?>
    <div class="alert alert-warning">
      <i class="fa fa-triangle-exclamation"></i>
      <strong><?= $marked_unknown ?> repeater<?= $marked_unknown!=1?'s':'' ?></strong> moved to UNKNOWN status - no renewal in 5+ years.
    </div>
    <?php endif; ?>

    <?php if ($dead_sent > 0): ?>
    <div class="alert alert-warning">
      <i class="fa fa-skull"></i> <?= $dead_sent ?> dead repeater notice<?= $dead_sent!=1?'s':''?> <?= $dry_run?'would be sent':'sent' ?>.
    </div>
    <?php endif; ?>
    <?php if ($auto_archived > 0): ?>
    <div class="alert alert-danger">
      <i class="fa fa-box-archive"></i> <?= $auto_archived ?> repeater<?= $auto_archived!=1?'s':''?> auto-archived (no response to dead notice).
    </div>
    <?php endif; ?>
    <?php if (!$dry_run && $sent > 0): ?>
    <div class="alert alert-success">
      <i class="fa fa-envelope"></i> <?= $sent ?> renewal email<?= $sent!=1?'s':'' ?> sent successfully.
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><i class="fa fa-clock"></i> Trustees Due for Renewal (<?= count($due_grouped) ?> trustees, <?= count($due_raw) ?> repeaters)</div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Email</th><th>Repeaters</th><th>Last Renewal</th></tr></thead>
          <tbody>
          <?php foreach ($due_grouped as $email => $reps): ?>
          <tr>
            <td><?= h($email) ?></td>
            <td><?php foreach ($reps as $rep): ?>
              <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $rep['id'] ?>"><?= h($rep['callsign']) ?></a> (<?= h($rep['output_freq']) ?>)
            <?php endforeach; ?></td>
            <td><?= $reps[0]['last_renewal_sent'] ? h($reps[0]['last_renewal_sent']) : '<span class="text-muted">Never</span>' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$due_grouped): ?>
          <tr><td colspan="3" class="text-center text-muted" style="padding:20px">No renewals due.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if ($dry_run): ?>
      <a href="<?= BASE_PATH ?>/admin/send_renewals.php" class="btn btn-success" onclick="return confirm('Send renewal emails now?')">
        <i class="fa fa-paper-plane"></i> Send Now (Live Run)
      </a>
      <?php else: ?>
      <a href="<?= BASE_PATH ?>/admin/send_renewals.php?dry_run=1" class="btn btn-secondary">
        <i class="fa fa-eye"></i> Dry Run (Preview Only)
      </a>
      <?php endif; ?>
      <a href="<?= BASE_PATH ?>/admin/send_renewals.php" class="btn btn-primary">
        <i class="fa fa-rotate"></i> Run Again
      </a>
      <a href="<?= BASE_PATH ?>/admin/email_templates.php" class="btn btn-secondary">
        <i class="fa fa-envelope-open-text"></i> Edit Email Templates
      </a>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
}
