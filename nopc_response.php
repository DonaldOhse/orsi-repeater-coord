<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$token  = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');

if (!$token || !in_array($action, ['approve','decline'])) {
    header('Location: ' . BASE_PATH . '/index.php'); exit;
}

// Look up notification
$stmt = $db->prepare("SELECT n.*, r.applicant_callsign, r.applicant_name, r.req_band, r.suggested_freq, r.preferred_freq, r.city, r.county, r.latitude, r.longitude FROM nopc_notifications n JOIN coordination_requests r ON r.id = n.request_id WHERE n.token = ?");
$stmt->execute([$token]);
$notif = $stmt->fetch();

if (!$notif) {
    $page_title = 'Invalid NOPC Link';
    include __DIR__ . '/includes/header.php';
    echo '<div style="max-width:600px;margin:40px auto;text-align:center"><div style="font-size:3rem;color:var(--danger)"><i class="fa fa-circle-xmark"></i></div><h2>Invalid or Expired Link</h2><p>This NOPC response link is invalid or has already been used.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($notif['status'] !== 'PENDING') {
    $page_title = 'NOPC Already Responded';
    include __DIR__ . '/includes/header.php';
    echo '<div style="max-width:600px;margin:40px auto;text-align:center"><div style="font-size:3rem;color:var(--warning)"><i class="fa fa-circle-check"></i></div><h2>Response Already Recorded</h2><p>This NOPC has already been responded to with status: <strong>' . h($notif['status']) . '</strong></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim($_POST['response_notes'] ?? '');
    $status = $action === 'approve' ? 'APPROVED' : 'DECLINED';

    $db->prepare("UPDATE nopc_notifications SET status=?, response_at=NOW(), response_notes=? WHERE token=?")
       ->execute([$status, $notes, $token]);

    // Notify OK coordinator
    $coord_email = get_coordinator_email($notif['district'] ?? 'OKC');
    $all_emails  = get_all_coordinator_emails('OKC');

    $freq = $notif['suggested_freq'] ?: $notif['preferred_freq'] ?: 'TBD';
    $subject = "NOPC Response: {$notif['state']} {$status} - {$notif['applicant_callsign']} {$freq} MHz";
    $body  = "The {$notif['state']} frequency coordinator has responded to your NOPC.\n\n";
    $body .= "Status:    {$status}\n";
    $body .= "Repeater:  {$notif['applicant_callsign']} - {$freq} MHz\n";
    $body .= "Location:  {$notif['city']}, {$notif['county']} County, Oklahoma\n";
    $body .= "Responded: " . date('Y-m-d H:i') . "\n";
    if ($notes) $body .= "\nTheir notes:\n{$notes}\n";
    $body .= "\nView the request:\nhttps://w5dro.com/repeater_coord/admin/requests.php?id={$notif['request_id']}\n\n73,\nORSI Coordination System\n";

    $headers = "".MAIL_FROM."\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    foreach ($all_emails as $email) {
        orsi_mail($email, $subject, $body, $headers);
    }

    $success = true;
}

$bands = ['10m'=>'10m (29 MHz)','6m'=>'6m (52 MHz)','2m-lo'=>'2m Low','2m-mid'=>'2m Mid','2m-hi'=>'2m High','1.25m'=>'1.25m','70cm'=>'70cm','33cm'=>'33cm','23cm'=>'23cm'];
$page_title = 'NOPC Response - ' . $notif['applicant_callsign'];
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:640px;margin:0 auto">
<div class="page-title"><i class="fa fa-envelope"></i> Notice of Proposed Coordination - Response</div>

<?php if ($success): ?>
<div style="text-align:center;padding:30px 0">
  <div style="font-size:3rem;color:<?= $action==='approve'?'var(--success)':'var(--danger)' ?>;margin-bottom:16px">
    <i class="fa fa-<?= $action==='approve'?'circle-check':'circle-xmark' ?>"></i>
  </div>
  <h2 style="color:var(--primary);margin-bottom:12px">
    Response Recorded - <?= $action==='approve'?'Approved':'Declined' ?>
  </h2>
  <p>Thank you. The Oklahoma Repeater Society has been notified of your response.</p>
  <p style="margin-top:8px;color:var(--muted)">You may close this window.</p>
</div>

<?php else: ?>

<div class="alert alert-<?= $action==='approve'?'success':'danger' ?>">
  <i class="fa fa-<?= $action==='approve'?'circle-check':'circle-xmark' ?>"></i>
  You are about to <strong><?= strtoupper($action) ?></strong> this coordination request from Oklahoma.
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-tower-broadcast"></i> Proposed Repeater Details</div>
  <div class="card-body" style="padding:0">
    <table class="detail-table">
      <tr><th>Applicant</th><td><?= h($notif['applicant_name']) ?> (<?= h($notif['applicant_callsign']) ?>)</td></tr>
      <tr><th>Band</th><td><?= h($bands[$notif['req_band']] ?? $notif['req_band']) ?></td></tr>
      <tr><th>Proposed Frequency</th><td><span class="freq"><?= h($notif['suggested_freq'] ?: $notif['preferred_freq']) ?> MHz</span></td></tr>
      <tr><th>Location</th><td><?= h($notif['city']) ?>, <?= h($notif['county']) ?> County, Oklahoma</td></tr>
      <?php if ($notif['latitude']): ?>
      <tr><th>GPS</th><td><?= h($notif['latitude']) ?>, <?= h($notif['longitude']) ?></td></tr>
      <?php endif; ?>
      <tr><th>NOPC Sent</th><td><?= substr($notif['sent_at'],0,10) ?></td></tr>
      <tr><th>Response Deadline</th><td><?= substr($notif['expires_at'],0,10) ?></td></tr>
    </table>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-comment"></i> Your Response</div>
  <div class="card-body">
    <form method="post">
      <div class="form-group" style="margin-bottom:16px">
        <label>Comments / Notes <span style="font-size:.75rem;color:var(--muted)">(optional)</span></label>
        <textarea name="response_notes" rows="4" style="width:100%;resize:vertical"
          placeholder="<?= $action==='approve' ? 'Any conditions or notes about your approval...' : 'Please explain why you are declining this coordination request...' ?>"></textarea>
      </div>
      <button type="submit" class="btn btn-<?= $action==='approve'?'success':'danger' ?>" style="font-size:1rem;padding:10px 24px">
        <i class="fa fa-<?= $action==='approve'?'check':'times' ?>"></i>
        Confirm <?= ucfirst($action) ?>
      </button>
      <a href="<?= BASE_PATH ?>/nopc_response.php?token=<?= urlencode($token) ?>&action=<?= $action==='approve'?'decline':'approve' ?>" 
         class="btn btn-secondary" style="margin-left:8px">
        Actually, <?= $action==='approve'?'Decline':'Approve' ?> Instead
      </a>
    </form>
  </div>
</div>

<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
