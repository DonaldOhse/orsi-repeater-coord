<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db = get_db();

$rep_id = (int)($_GET['id'] ?? $_POST['rep_id'] ?? 0);
if (!$rep_id) { flash('danger', 'No repeater specified.'); header("Location: " . BASE_PATH . "/admin/license_review.php"); exit; }

// Get repeater data
$rep = $db->prepare("SELECT r.*, 
    f.licensee_name as fcc_name, f.license_status as fcc_status, 
    f.expiry_date as fcc_expiry, f.email as fcc_email,
    f.street_address as fcc_address, f.city as fcc_city, 
    f.state as fcc_state, f.zip_code as fcc_zip
    FROM repeaters r
    LEFT JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.id=?");
$rep->execute([$rep_id]);
$rep = $rep->fetch();
if (!$rep) { flash('danger', 'Repeater not found.'); header("Location: " . BASE_PATH . "/admin/license_review.php"); exit; }

// Handle form submission - generate PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_name  = trim($_POST['recipient_name'] ?? '');
    $recipient_addr1 = trim($_POST['recipient_addr1'] ?? '');
    $recipient_addr2 = trim($_POST['recipient_addr2'] ?? '');
    $recipient_email = trim($_POST['recipient_email'] ?? '');
    $letter_date     = trim($_POST['letter_date'] ?? date('F j, Y'));
    $subject_line    = trim($_POST['subject_line'] ?? '');
    $opening         = trim($_POST['opening'] ?? '');
    $body_text       = trim($_POST['body_text'] ?? '');
    $send_email      = isset($_POST['send_email']);
    $coordinator     = trim($_POST['coordinator_name'] ?? 'Donald Ohse, W5DRO');
    $coord_title     = trim($_POST['coordinator_title'] ?? 'Frequency Coordinator');

    // Generate PDF via Python
    $data = json_encode([
        'recipient_name'  => $recipient_name,
        'recipient_addr1' => $recipient_addr1,
        'recipient_addr2' => $recipient_addr2,
        'letter_date'     => $letter_date,
        'subject_line'    => $subject_line,
        'opening'         => $opening,
        'body_text'       => $body_text,
        'coordinator'     => $coordinator,
        'coord_title'     => $coord_title,
        'rep_callsign'    => $rep['callsign'],
        'org_url'         => ORG_URL,
    ]);

    $json_file = tempnam('/tmp', 'orsi_letter_') . '.json';
    file_put_contents($json_file, $data);

    $pdf_file = '/tmp/orsi_letter_' . $rep['callsign'] . '_' . date('Ymd_His') . '.pdf';
    $script = __DIR__ . '/../scripts/generate_letter.py';

    $output = shell_exec("python3 " . escapeshellarg($script) . " " . escapeshellarg($json_file) . " " . escapeshellarg($pdf_file) . " 2>&1");

    if (file_exists($pdf_file)) {
        // Send email if requested
        if ($send_email && $recipient_email) {
            $mail_body = $opening . "\n\n" . $body_text . "\n\nSincerely,\n" . $coordinator . "\n" . $coord_title . "\nOklahoma Repeater Society, Inc.";
            $email_sent = orsi_mail($recipient_email, "RE: " . $subject_line, $mail_body);
            if ($email_sent) {
                flash('success', "Letter generated and emailed to {$recipient_email}! ✅");
            } else {
                flash('danger', "PDF generated but email FAILED to send to {$recipient_email}. Check mail server.");
            }
        } else {
            flash('success', "Letter generated successfully!");
        }
        // Log action
        $db->prepare("INSERT INTO coordination_actions (repeater_id, action_type, performed_by, notice_method, notes, email_sent_to)
            VALUES (?,?,?,?,?,?)")
           ->execute([$rep_id, 'NOTICE_SENT', $_SESSION['user']['id'], 
             $send_email ? ($email_sent ? 'EMAIL' : 'NONE') : 'NONE',
             "Letter generated: {$subject_line}" . ($send_email ? ($email_sent ? " — Email sent OK" : " — Email FAILED") : ""),
             $send_email ? $recipient_email : null]);

        // Stream PDF to browser
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="ORSI_Letter_' . $rep['callsign'] . '_' . date('Ymd') . '.pdf"');
        header('Content-Length: ' . filesize($pdf_file));
        readfile($pdf_file);
        unlink($pdf_file);
        unlink($json_file);
        exit;
    } else {
        flash('danger', "PDF generation failed: " . $output);
        unlink($json_file);
    }
}

// Build default letter content based on repeater status
$default_subject = "Request to Update Repeater Coordination Records — {$rep['callsign']}";
$default_opening = "Dear [Recipient Name]:";

$systems_table = "System: {$rep['callsign']} — " . number_format((float)$rep['output_freq'],4) . " MHz\n";
$systems_table .= "Location: {$rep['city']}, {$rep['county']} County\n";
$systems_table .= "Status: {$rep['status']}\n";
$systems_table .= "Trustee: {$rep['trustee']}" . ($rep['fcc_name'] ? " ({$rep['fcc_name']})" : "") . "\n";

$default_body = "I am writing on behalf of the Oklahoma Repeater Society, Inc. (ORSI) as part of our ongoing effort to keep Oklahoma's amateur repeater coordination records accurate and current.\n\n";
$default_body .= "Our review found the following coordination record that needs attention:\n\n";
$default_body .= $systems_table . "\n";

if ($rep['status'] === 'ADMIN HOLD - HOLDER DECEASED') {
    $default_body .= "The trustee on record, {$rep['trustee']}, has been identified as a Silent Key. Per ORSI policy, we are allowing 60 days for the club or estate to designate a new trustee and update the FCC license accordingly.\n\n";
    $default_body .= "Please let us know whether your club or another responsible party intends to maintain this repeater. If so, please provide the new trustee callsign, updated FCC license information, and current contact details.\n\n";
    $default_body .= "If there are no plans to continue operating this system, we respectfully ask that the coordination be released so the frequency pair can be made available for future coordination.\n\n";
} elseif ($rep['status'] === 'ADMIN HOLD - LICENSE EXPIRED') {
    $default_body .= "The FCC license for trustee {$rep['trustee']} expired on {$rep['fcc_expiry']}. Per ORSI policy, the coordination has been placed on Administrative Hold for 60 days to allow time to renew the license or designate a new licensed trustee.\n\n";
    $default_body .= "To resolve this issue, please renew your FCC license at https://wireless2.fcc.gov/ and notify us at coordination@oklahomarepeatersociety.org with proof of renewal.\n\n";
} else {
    $default_body .= "We would like to verify that your repeater information is current and accurate. Please review the information above and provide any necessary corrections.\n\n";
}

$default_body .= "This is an administrative coordination-record request, not an enforcement inquiry. ORSI's preference is to keep valid coordination in place for active systems.\n\n";
$default_body .= "Please email updated information to coordination@oklahomarepeatersociety.org within 30 days. Thank you for helping us keep Oklahoma repeater coordination information accurate and useful to the amateur radio community.";

$page_title = "Generate Letter — {$rep['callsign']}";
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-file-pdf"></i> Generate Coordination Letter — <?=h($rep['callsign'])?></div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">
<div>
<div class="card">
  <div class="card-header"><i class="fa fa-pen"></i> Letter Details</div>
  <div style="padding:20px">
    <form method="post">
      <input type="hidden" name="rep_id" value="<?=$rep_id?>">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="form-group">
          <label>Letter Date</label>
          <input type="text" name="letter_date" value="<?=date('F j, Y')?>">
        </div>
        <div class="form-group">
          <label>Coordinator Name</label>
          <input type="text" name="coordinator_name" value="Donald Ohse, W5DRO">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Coordinator Title</label>
        <input type="text" name="coordinator_title" value="Frequency Coordinator — <?=h($rep['district'])?> District">
      </div>

      <hr style="margin:16px 0">
      <h4 style="font-size:.9rem;font-weight:700;margin-bottom:12px">Recipient</h4>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="form-group">
          <label>Recipient Name</label>
          <input type="text" name="recipient_name" 
            value="<?=h($rep['contact_name'] ?: $rep['fcc_name'] ?: '')?>">
        </div>
        <div class="form-group">
          <label>Recipient Email</label>
          <input type="email" name="recipient_email" 
            value="<?=h($rep['contact_email'] ?: $rep['fcc_email'] ?: '')?>">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Street Address</label>
        <input type="text" name="recipient_addr1" 
          value="<?=h($rep['fcc_address'] ?: '')?>">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>City, State ZIP</label>
        <input type="text" name="recipient_addr2" 
          value="<?=h(trim(($rep['fcc_city'] ?? '') . ', ' . ($rep['fcc_state'] ?? '') . ' ' . ($rep['fcc_zip'] ?? '')))?>">
      </div>

      <hr style="margin:16px 0">
      <h4 style="font-size:.9rem;font-weight:700;margin-bottom:12px">Letter Content</h4>

      <div class="form-group" style="margin-bottom:12px">
        <label>RE: Subject Line</label>
        <input type="text" name="subject_line" value="<?=h($default_subject)?>">
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Opening (Dear...)</label>
        <input type="text" name="opening" value="<?=h($default_opening)?>">
      </div>

      <div class="form-group" style="margin-bottom:16px">
        <label>Letter Body</label>
        <textarea name="body_text" rows="12" style="font-size:.85rem;line-height:1.5"><?=h($default_body)?></textarea>
      </div>

      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">
          <i class="fa fa-file-pdf"></i> Generate PDF Letter
        </button>
        <?php if ($rep['contact_email'] || $rep['fcc_email']): ?>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" name="send_email" value="1">
          Also email to recipient
        </label>
        <?php endif; ?>
        <a href="<?=BASE_PATH?>/admin/license_review.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
</div>

<!-- Sidebar: Repeater Info -->
<div>
  <div class="card">
    <div class="card-header"><i class="fa fa-tower-broadcast"></i> Repeater Info</div>
    <div style="padding:12px;font-size:.82rem">
      <table style="width:100%;border-collapse:collapse">
        <?php foreach ([
          ['Callsign', $rep['callsign']],
          ['Output', number_format((float)$rep['output_freq'],4) . ' MHz'],
          ['Status', $rep['status']],
          ['Trustee', $rep['trustee']],
          ['FCC Name', $rep['fcc_name'] ?: '—'],
          ['FCC Status', $rep['fcc_status'] ?: '—'],
          ['FCC Expiry', $rep['fcc_expiry'] ?: '—'],
          ['City', $rep['city'] . ', ' . $rep['county']],
          ['District', $rep['district']],
          ['Contact', $rep['contact_name'] ?: '—'],
          ['Email', $rep['contact_email'] ?: $rep['fcc_email'] ?: '—'],
        ] as [$l,$v]): ?>
        <tr>
          <td style="padding:3px 0;color:var(--muted);font-weight:600;width:80px"><?=$l?></td>
          <td style="padding:3px 0"><?=h($v)?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:12px">
    <div class="card-header"><i class="fa fa-clock-rotate-left"></i> Previous Actions</div>
    <div style="padding:12px;font-size:.82rem">
      <?php
      $actions = $db->prepare("SELECT a.action_type, a.created_at, a.notes, a.notice_method, a.email_sent_to, u.callsign as done_by FROM coordination_actions a LEFT JOIN users u ON u.id=a.performed_by WHERE a.repeater_id=? ORDER BY a.created_at DESC LIMIT 10");
      $actions->execute([$rep_id]);
      $actions = $actions->fetchAll();
      if ($actions): foreach ($actions as $a): ?>
      <div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--border)">
        <strong><?=h($a['action_type'])?></strong>
        <?php if ($a['notice_method'] === 'EMAIL'): ?>
          <span style="background:#dbeafe;color:#1d4ed8;font-size:.7rem;padding:1px 5px;border-radius:3px;margin-left:4px">
            <i class="fa fa-envelope"></i> EMAIL
          </span>
        <?php elseif ($a['notice_method'] === 'MAIL'): ?>
          <span style="background:#f0fdf4;color:#15803d;font-size:.7rem;padding:1px 5px;border-radius:3px;margin-left:4px">
            <i class="fa fa-file-pdf"></i> PDF
          </span>
        <?php endif; ?>
        <br>
        <small style="color:var(--muted)"><?=date('M j Y g:i A', strtotime($a['created_at']))?></small>
        <?php if ($a['done_by']): ?>
          <small style="color:var(--muted)"> — <?=h($a['done_by'])?></small>
        <?php endif; ?>
        <?php if ($a['email_sent_to']): ?>
          <br><small style="color:#1d4ed8"><i class="fa fa-envelope"></i> <?=h($a['email_sent_to'])?></small>
        <?php endif; ?>
        <?php if ($a['notes']): ?>
          <br><small><?=h(substr($a['notes'],0,100))?></small>
        <?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <p style="color:var(--muted)">No previous actions recorded.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
