<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();
$success = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $call    = strtoupper(trim($_POST['callsign'] ?? ''));
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $cat     = $_POST['category'] ?? 'GENERAL';
    $dist    = $_POST['district'] ?? null;
    $msg     = trim($_POST['message'] ?? '');
    $rep_id  = !empty($_POST['repeater_id']) ? (int)$_POST['repeater_id'] : null;

    if (!$name)    $errors[] = 'Name is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!$subject) $errors[] = 'Subject is required.';
    if (!$msg)     $errors[] = 'Message is required.';
    if (strlen($msg) < 10) $errors[] = 'Message is too short.';

    // Simple spam check
    if (isset($_POST['website']) && $_POST['website'] !== '') $errors[] = 'Spam detected.';

    if (empty($errors)) {
        // Generate ticket number
        $ticket_num = 'ORSI-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);

        // Auto-assign district from callsign prefix if not specified
        if (!$dist && $call) {
            // Try to find district from repeater with this callsign
            $q = $db->prepare("SELECT district FROM repeaters WHERE callsign=? AND archived_at IS NULL LIMIT 1");
            $q->execute([$call]);
            $row = $q->fetch();
            if ($row) $dist = $row['district'];
        }

        // Insert ticket
        $db->prepare("INSERT INTO support_tickets 
            (ticket_num, subject, category, district, submitter_name, submitter_call,
             submitter_email, submitter_phone, repeater_id)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$ticket_num, $subject, $cat, $dist ?: null, $name, $call ?: null,
                      $email, $phone ?: null, $rep_id]);
        $ticket_id = (int)$db->lastInsertId();

        // Insert first message
        $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?,NULL,?)")
           ->execute([$ticket_id, $msg]);

        // Email confirmation to submitter
        $confirm_body = "Dear {$name},\n\n"
            . "Thank you for contacting the Oklahoma Repeater Society, Inc.\n\n"
            . "Your inquiry has been received and assigned ticket number: {$ticket_num}\n\n"
            . "SUBJECT: {$subject}\n"
            . "CATEGORY: {$cat}\n"
            . ($dist ? "DISTRICT: {$dist}\n" : "")
            . "\nYOUR MESSAGE:\n{$msg}\n\n"
            . "A coordinator will respond to your inquiry. You will receive an email when we reply.\n\n"
            . "To follow up, please reference ticket number: {$ticket_num}\n"
            . "Or email us at: " . MAIL_REPLY_TO . "\n\n"
            . "73,\nOklahoma Repeater Society, Inc.\n" . ORG_URL;
        orsi_ticket_mail($email, "ORSI Ticket {$ticket_num}: {$subject}", $confirm_body);

        // Email notification to district coordinator AND all admins
        $notify_emails = [];
        $coord_email = get_coordinator_email($dist ?: 'OKC');
        if ($coord_email) $notify_emails[] = $coord_email;

        // Add all admins
        $admins = $db->query("SELECT email FROM users WHERE role='admin' AND active=1 AND email != ''")->fetchAll();
        foreach ($admins as $admin) {
            if (!in_array($admin['email'], $notify_emails)) {
                $notify_emails[] = $admin['email'];
            }
        }

        $coord_body = "New support ticket received:\n\n"
            . "Ticket: {$ticket_num}\n"
            . "From: {$name}" . ($call ? " ({$call})" : "") . "\n"
            . "Email: {$email}\n"
            . "Phone: " . ($phone ?: 'Not provided') . "\n"
            . "Subject: {$subject}\n"
            . "Category: {$cat}\n"
            . ($dist ? "District: {$dist}\n" : "District: Unknown\n")
            . "\nMessage:\n{$msg}\n\n"
            . "Manage tickets at: " . ORG_URL . "/admin/tickets.php";

        foreach ($notify_emails as $notify_email) {
            orsi_mail($notify_email, "New Ticket {$ticket_num}: {$subject}", $coord_body);
        }

        $success = true;
        $success_ticket = $ticket_num;
    }
}

// Get repeater list for dropdown
$repeaters = $db->query("SELECT id, callsign, city, output_freq FROM repeaters 
    WHERE archived_at IS NULL ORDER BY callsign")->fetchAll();

$page_title = 'Contact / Submit Inquiry';
include __DIR__ . '/includes/header.php';
?>

<div class="page-title"><i class="fa fa-envelope"></i> Contact / Submit Inquiry</div>

<?php if ($success): ?>
<div class="card" style="max-width:600px;margin:0 auto">
  <div style="padding:30px;text-align:center">
    <i class="fa fa-check-circle" style="font-size:3rem;color:var(--success)"></i>
    <h2 style="color:var(--success);margin:16px 0 8px">Inquiry Submitted!</h2>
    <p>Your ticket number is: <strong style="font-size:1.2rem"><?=h($success_ticket)?></strong></p>
    <p style="color:var(--muted)">A confirmation has been sent to your email address.<br>
    A coordinator will respond to your inquiry shortly.</p>
    <a href="<?=BASE_PATH?>/contact.php" class="btn btn-primary" style="margin-top:16px">Submit Another</a>
    <a href="<?=BASE_PATH?>/" class="btn btn-secondary" style="margin-top:16px">Back to Database</a>
  </div>
</div>

<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
<div>
<div class="card">
  <div class="card-header"><i class="fa fa-pen-to-square"></i> Submit an Inquiry</div>
  <div style="padding:20px">

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <i class="fa fa-exclamation-circle"></i>
      <?= implode('<br>', array_map('h', $errors)) ?>
    </div>
    <?php endif; ?>

    <form method="post">
      <input type="text" name="website" style="display:none" tabindex="-1">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label>Your Name <span style="color:red">*</span></label>
          <input type="text" name="name" value="<?=h($_POST['name']??'')?>" required>
        </div>
        <div class="form-group">
          <label>Callsign (if licensed)</label>
          <input type="text" name="callsign" value="<?=h($_POST['callsign']??'')?>"
            placeholder="e.g. W5DRO" style="text-transform:uppercase">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label>Email Address <span style="color:red">*</span></label>
          <input type="email" name="email" value="<?=h($_POST['email']??'')?>" required>
        </div>
        <div class="form-group">
          <label>Phone (optional)</label>
          <input type="tel" name="phone" value="<?=h($_POST['phone']??'')?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label>Category</label>
          <select name="category">
            <?php foreach (['GENERAL'=>'General Inquiry','COORDINATION'=>'Coordination Question',
              'RENEWAL'=>'Renewal Issue','TECHNICAL'=>'Technical Question',
              'COMPLAINT'=>'Complaint','OTHER'=>'Other'] as $v=>$l): ?>
            <option value="<?=$v?>" <?=($_POST['category']??'')===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>District (if known)</label>
          <select name="district">
            <option value="">— Any / Unknown —</option>
            <?php foreach (['OKC'=>'OKC — Central Oklahoma','TUL'=>'TUL — Tulsa Area',
              'NW'=>'NW — Northwest','NE'=>'NE — Northeast',
              'SW'=>'SW — Southwest','SE'=>'SE — Southeast'] as $v=>$l): ?>
            <option value="<?=$v?>" <?=($_POST['district']??'')===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>Related Repeater (optional)</label>
        <select name="repeater_id">
          <option value="">— Not specific to a repeater —</option>
          <?php foreach ($repeaters as $r): ?>
          <option value="<?=$r['id']?>" <?=($_POST['repeater_id']??'')==$r['id']?'selected':''?>>
            <?=h($r['callsign'])?> — <?=number_format((float)$r['output_freq'],4)?> MHz — <?=h($r['city'])?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Subject <span style="color:red">*</span></label>
        <input type="text" name="subject" value="<?=h($_POST['subject']??'')?>" required
          placeholder="Brief description of your inquiry">
      </div>

      <div class="form-group">
        <label>Message <span style="color:red">*</span></label>
        <textarea name="message" rows="6" required
          placeholder="Please provide as much detail as possible..."><?=h($_POST['message']??'')?></textarea>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="fa fa-paper-plane"></i> Submit Inquiry
      </button>
    </form>
  </div>
</div>
</div>

<!-- Sidebar -->
<div>
  <div class="card">
    <div class="card-header"><i class="fa fa-circle-info"></i> Before You Submit</div>
    <div style="padding:16px;font-size:.875rem">
      <p><strong>Coordination Requests</strong><br>
      For new repeater coordination, use the
      <a href="<?=BASE_PATH?>/request.php">Coordination Request Form</a>.</p>
      <div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:6px;padding:10px 12px;margin:8px 0">
        <strong style="color:#92400e"><i class="fa fa-triangle-exclamation"></i> Repeater Updates</strong><br>
        <span style="color:#78350f">If you need to update an existing repeater's information (trustee, status, tone, coordinates, etc.) please use the
        <a href="<?=BASE_PATH?>/index.php" style="color:#b45309;font-weight:bold">repeater listing</a>,
        find your repeater, and click <strong>"Submit Information Update"</strong> on the repeater detail page.
        Do not use this contact form for repeater updates.</span>
      </div>
      <p><strong>Response Time</strong><br>
      We aim to respond within 3-5 business days.</p>
      <p><strong>Emergency</strong><br>
      For emergency communications issues, contact your district coordinator directly.</p>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="card-header"><i class="fa fa-users"></i> District Coordinators</div>
    <div style="padding:16px;font-size:.875rem">
      <?php foreach (['OKC'=>'Central/OKC','TUL'=>'Tulsa','NW'=>'Northwest',
        'NE'=>'Northeast','SW'=>'Southwest','SE'=>'Southeast'] as $d=>$name): ?>
      <div style="margin-bottom:8px">
        <strong><?=$d?></strong> — <?=$name?><br>
        <small style="color:var(--muted)"><?=h(get_coordinator_email($d) ?: 'coordinator@oklahomarepeatersociety.org')?></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
