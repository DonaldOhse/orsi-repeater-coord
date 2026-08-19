<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

// Fetch repeater
$stmt = $db->prepare("SELECT * FROM repeaters WHERE id = ? AND private = 0");
$stmt->execute([$id]);
$rep = $stmt->fetch();
if (!$rep) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

$errors   = [];
$success  = false;
$submitted_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Spam check
    if (!empty($_POST['website'])) { die('Bot detected.'); }

    $name   = trim($_POST['submitter_name']  ?? '');
    $call   = strtoupper(trim($_POST['submitter_call']  ?? ''));
    $email  = trim($_POST['submitter_email'] ?? '');
    $phone  = trim($_POST['submitter_phone'] ?? '');
    $rel    = trim($_POST['relationship']    ?? '');

    if (!$name)  $errors[] = 'Your name is required.';
    if (!$call)  $errors[] = 'Your callsign is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'A valid email address is required.';

    // Collect changed fields
    $fields = [
        'callsign'      => 'Repeater Callsign',
        'status'        => 'System Status',
        'trustee'       => 'Trustee',
        'contact_name'  => 'Contact Name',
        'contact_email' => 'Contact Email',
        'contact_phone' => 'Contact Phone',
        'sponsor'       => 'Sponsor/Club',
        'county'        => 'County',
        'city'          => 'City',
        'pl_tone'       => 'PL Tone',
        'tone_type'     => 'Tone Type',
        'mixed_mode'    => 'Mixed Mode',
        'mixed_mode_types' => 'Mixed Mode Types',
        'dcs_code'      => 'DCS Code',
        'dmr_color_code'=> 'DMR Color Code',
        'open_system'   => 'Open System',
        'autopatch'     => 'Auto-Patch',
        'skywarn'       => 'SKYWARN',
        'linked'        => 'Linked',
        'backup_power'  => 'Backup Power',
        'allstar'       => 'AllStar',
        'allstar_node'  => 'AllStar Node',
        'echolink'      => 'EchoLink',
        'echolink_node' => 'EchoLink Node',
        'internet_link' => 'Internet Link',
        'url'           => 'Website URL',
        'latitude'      => 'Latitude',
        'longitude'     => 'Longitude',
        'antenna_height_agl' => 'Antenna Height AGL',
        'haat'          => 'HAAT',
        'tx_power_watts'=> 'TX Power',
        'notes'         => 'Notes',
    ];

    $changes      = [];
    $change_lines = [];

    foreach ($fields as $col => $label) {
        $new_val = trim($_POST[$col] ?? '');
        $old_val = (string)($rep[$col] ?? '');

        // Normalize booleans
        if (in_array($col, ['open_system','autopatch','skywarn','linked','backup_power','allstar','echolink','mixed_mode'])) {
            $new_val = isset($_POST[$col]) ? '1' : '0';
        } elseif ($col === 'mixed_mode_types') {
            $new_val = !empty($_POST['mixed_mode_types']) ? implode(',', (array)$_POST['mixed_mode_types']) : '';
        }

        if ($new_val !== $old_val && !($new_val === '' && $old_val === null)) {
            $changes[$col] = ['old' => $old_val, 'new' => $new_val];
            $change_lines[] = "• {$label}: '{$old_val}' → '{$new_val}'";
        }
    }

    if (!$rel) $errors[] = 'Please select your relationship to this repeater.';
    // Require GPS if currently city-level AND submitter is trustee/officer
    // Skip if they are reporting off-air or requesting de-coordination
    $new_status = trim($_POST['status'] ?? '');
    $status_exemptions = ['DEAD','DECOORDINATED','DOWN TEMPORARILY','UNKNOWN'];
    $gps_exempt = in_array($new_status, $status_exemptions);
    if (($rep['location_source'] ?? '') === 'CITY'
        && in_array($rel, ['Trustee','Club Officer'])
        && !$gps_exempt) {
        $new_lat = trim($_POST['latitude'] ?? '');
        $new_lon = trim($_POST['longitude'] ?? '');
        $old_lat = (string)($rep['latitude'] ?? '');
        $old_lon = (string)($rep['longitude'] ?? '');
        if ($new_lat === $old_lat && $new_lon === $old_lon) {
            $errors[] = 'This repeater uses approximate city-center coordinates. As the Trustee or Club Officer, please provide exact GPS coordinates for the antenna location.';
        }
    }
    if (empty($changes)) $errors[] = 'No changes detected. Please modify at least one field.';

    // If submitter claims to be Trustee, verify callsign matches database record
    if ($rel === 'Trustee' && $call) {
        $db_trustee = strtoupper(trim($rep['trustee'] ?? ''));
        $submitter_cs = strtoupper($call);
        if ($db_trustee && $submitter_cs !== $db_trustee) {
            // Check FCC database - maybe trustee got a new callsign
            $fcc_check = $db->prepare("SELECT callsign, previous_callsign, licensee_name FROM fcc_licenses WHERE callsign=? AND license_status='A' LIMIT 1");
            $fcc_check->execute([$submitter_cs]);
            $fcc_row = $fcc_check->fetch();
            $fcc_prev = $fcc_row ? strtoupper($fcc_row['previous_callsign'] ?? '') : '';
            if ($fcc_prev !== $db_trustee) {
                // Not the trustee — allow submission but flag for coordinator review
                $changes['_trustee_mismatch'] = [
                    'old' => $db_trustee,
                    'new' => $submitter_cs,
                    'flag' => 'MISMATCH'
                ];
                $change_lines[] = "⚠ WARNING: Submitter callsign ({$submitter_cs}) does not match trustee on record ({$db_trustee}). Coordinator review required.";
            } else {
                // Callsign changed — add note to changes
                $changes['trustee'] = ['old' => $db_trustee, 'new' => $submitter_cs];
                $change_lines[] = "• Trustee callsign change detected: '{$db_trustee}' → '{$submitter_cs}' (verified via FCC database)";
            }
        }
    }

    if (!$errors) {
        $summary = implode("\n", $change_lines);
        $db->prepare("INSERT INTO update_requests (repeater_id, submitter_name, submitter_call, submitter_email, submitter_phone, relationship, changes, change_summary) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$id, $name, $call, $email, $phone, $rel, json_encode($changes), $summary]);

        $req_id = $db->lastInsertId();

        // Email coordinator
        $dist = $rep['district'] ?? 'OKC';
        $coord_emails = get_all_coordinator_emails($dist);

        $subject = "Repeater Update Request #{$req_id} - {$rep['callsign']} {$rep['output_freq']} MHz";
        $body  = "An update request has been submitted for a coordinated repeater.\n\n";
        $body .= "Repeater: {$rep['callsign']} - {$rep['output_freq']} MHz\n";
        $body .= "Location: {$rep['city']}, {$rep['county']}\n";
        $body .= "District: {$dist}\n\n";
        $body .= "Submitted by: {$name} ({$call})\n";
        $body .= "Email: {$email}\n";
        $body .= "Phone: " . ($phone ?: 'N/A') . "\n";
        $body .= "Relationship: " . ($rel ?: 'N/A') . "\n\n";
        $body .= "Proposed Changes:\n{$summary}\n\n";
        $body .= "Review and apply this update:\n";
        $body .= "https://w5dro.com/repeater_coord/admin/update_requests.php?id={$req_id}\n";

        foreach ($coord_emails as $coord_email) {
            orsi_mail($coord_email, $subject, $body, "".MAIL_FROM."\r\nReply-To: {$email}");
        }
        if (empty($coord_emails)) error_log('ORSI: No coordinator emails for district ' . $dist . ', update request #' . $req_id);

        // Confirm to submitter
        $confirm  = "Thank you for submitting an update for {$rep['callsign']} ({$rep['output_freq']} MHz).\n\n";
        $confirm .= "Your proposed changes have been forwarded to the District {$dist} coordinator for review.\n\n";
        $confirm .= "Changes submitted:\n{$summary}\n\n";
        $confirm .= "You will be notified when your update has been reviewed.\n\n";
        $confirm .= "73,\nOklahoma Repeater Society\n";
        orsi_mail($email, "Update Request #{$req_id} Received - ORSI", $confirm, MAIL_FROM);

        $submitted_email = $email;
        $success = true;
    }
}

$statuses = ['OPERATIONAL','PROPOSED','CONSTRUCTION','DOWN TEMPORARILY','DEAD','DECOORDINATED','UNCOORDINATED','UNKNOWN'];
$ctcss_tones = ['67.0','69.3','71.9','74.4','77.0','79.7','82.5','85.4','88.5','91.5','94.8','97.4','100.0','103.5','107.2','110.9','114.8','118.8','123.0','127.3','131.8','136.5','141.3','146.2','151.4','156.7','162.2','167.9','173.8','179.9','186.2','192.8','203.5','210.7','218.1','225.7','233.6','241.8','250.3','254.1'];

$page_title = 'Submit Repeater Update - ' . $rep['callsign'];
include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $id ?>" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to Repeater</a>
  <div class="page-title" style="margin:0;border:none;padding:0">
    <i class="fa fa-pen-to-square"></i> Submit Update for <?= h($rep['callsign']) ?> - <span class="freq"><?= number_format((float)$rep['output_freq'],4) ?> MHz</span>
  </div>
</div>

<?php if ($success): ?>
<div style="max-width:600px;margin:20px auto;text-align:center">
  <div style="font-size:3rem;color:var(--success);margin-bottom:16px"><i class="fa fa-circle-check"></i></div>
  <h2 style="color:var(--primary);margin-bottom:12px">Update Submitted!</h2>
  <p style="margin-bottom:16px">Your proposed changes for <strong><?= h($rep['callsign']) ?></strong> have been forwarded to the district coordinator for review.</p>
  <div class="alert alert-info" style="text-align:left">
    <i class="fa fa-envelope"></i> A confirmation has been sent to <strong><?= h($submitted_email) ?></strong>.
  </div>
  <div style="margin-top:20px;display:flex;gap:10px;justify-content:center">
    <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa fa-eye"></i> View Repeater</a>
    <a href="<?= BASE_PATH ?>/index.php" class="btn btn-secondary"><i class="fa fa-list"></i> Repeater Database</a>
  </div>
</div>

<?php else: ?>

<div class="alert alert-info">
  <i class="fa fa-circle-info"></i>
  Use this form to submit corrections or updates for <strong><?= h($rep['callsign']) ?></strong>.
  A coordinator will review your changes before they are applied to the database.
  Fields are pre-filled with current data - only change what needs updating.
</div>
<?php if (($rep['location_source'] ?? '') === 'CITY'): ?>
<div class="alert alert-warning">
  <i class="fa fa-triangle-exclamation"></i>
  <strong>GPS Coordinates Needed!</strong>
  This repeater is currently using approximate city-center coordinates which reduces
  coordination accuracy by up to 15%. Please provide exact GPS coordinates
  (latitude and longitude) for the antenna location to improve coordination accuracy.
  You can find exact coordinates using
  <a href="https://www.google.com/maps" target="_blank">Google Maps</a> —
  right-click on the antenna location and select the coordinates.
</div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= h($e) ?></div>
<?php endforeach; ?>

<form method="post">
<input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

<!-- Who are you -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-user"></i> Your Information</div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Your Name *</label>
        <input type="text" name="submitter_name" value="<?= h($_POST['submitter_name'] ?? '') ?>" required maxlength="100">
      </div>
      <div class="form-group">
        <label>Your Callsign *</label>
        <input type="text" name="submitter_call" value="<?= h($_POST['submitter_call'] ?? '') ?>" required maxlength="20" style="text-transform:uppercase">
      </div>
      <div class="form-group">
        <label>Your Email *</label>
        <input type="email" name="submitter_email" value="<?= h($_POST['submitter_email'] ?? '') ?>" required maxlength="150">
      </div>
      <div class="form-group">
        <label>Your Phone</label>
        <input type="tel" name="submitter_phone" value="<?= h($_POST['submitter_phone'] ?? '') ?>" maxlength="20" placeholder="405-555-1234">
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Your Relationship to this Repeater</label>
        <select name="relationship" required>
          <option value="">— Please select —</option>
          <option value="">- Select -</option>
          <option value="Trustee" <?= ($_POST['relationship']??'')==='Trustee'?'selected':'' ?>>I am the Trustee</option>
          <option value="Club Officer" <?= ($_POST['relationship']??'')==='Club Officer'?'selected':'' ?>>I am a Club Officer/Sponsor</option>
          <option value="Regular User" <?= ($_POST['relationship']??'')==='Regular User'?'selected':'' ?>>I am a Regular User</option>
          <option value="Other" <?= ($_POST['relationship']??'')==='Other'?'selected':'' ?>>Other</option>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- Current repeater data - editable -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-tower-broadcast"></i> Repeater Information <small style="font-weight:400;color:var(--muted)">(pre-filled with current data - change only what needs updating)</small></div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>System Status</label>
        <select name="status">
          <?php foreach ($statuses as $s): ?>
          <option value="<?= h($s) ?>" <?= $rep['status']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Repeater Callsign</label>
        <input type="text" name="callsign" value="<?= h($rep['callsign']) ?>" maxlength="15" style="text-transform:uppercase"
          placeholder="e.g. W5DRO">
        <small style="color:var(--muted)">Only update if the repeater callsign has changed</small>
      </div>
      <div class="form-group">
        <label>Trustee Callsign</label>
        <input type="text" name="trustee" value="<?= h($rep['trustee']) ?>" maxlength="20" style="text-transform:uppercase">
      </div>
      <div class="form-group" id="contact-fields-note" style="grid-column:1/-1"><div class="alert alert-info"><i class="fa fa-info-circle"></i> If you are the Trustee or Club Officer, the fields below will update the repeater's contact information on file.</div></div>
      <div class="form-group">
        <label>Contact Name</label>
        <input type="text" name="contact_name" value="<?= h($rep['contact_name'] ?? '') ?>" maxlength="100" placeholder="Full name of trustee/contact">
      </div>
      <div class="form-group">
        <label>Contact Email</label>
        <input type="email" name="contact_email" value="<?= h($rep['contact_email'] ?? '') ?>" maxlength="150" placeholder="email@example.com">
      </div>
      <div class="form-group">
        <label>Contact Phone</label>
        <input type="tel" name="contact_phone" value="<?= h($rep['contact_phone'] ?? '') ?>" maxlength="20" placeholder="405-555-1234">
      </div>
      <div class="form-group">
        <label>Sponsor / Club</label>
        <input type="text" name="sponsor" value="<?= h($rep['sponsor']) ?>" maxlength="100">
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="city" value="<?= h($rep['city']) ?>" maxlength="60">
      </div>
      <div class="form-group">
        <label>County</label>
        <input type="text" name="county" value="<?= h($rep['county']) ?>" maxlength="50" style="text-transform:uppercase">
      </div>
      <div class="form-group">
        <label>Tone Type</label>
        <select name="tone_type" onchange="showToneFields(this.value)">
          <option value="CARRIER" <?= ($rep['tone_type']??'')==='CARRIER'?'selected':'' ?>>Carrier Squelch</option>
          <option value="CTCSS"   <?= ($rep['tone_type']??'')==='CTCSS'  ?'selected':'' ?>>CTCSS / PL Tone</option>
          <option value="TSQ"     <?= ($rep['tone_type']??'')==='TSQ'    ?'selected':'' ?>>TSQ</option>
          <option value="DCS"     <?= ($rep['tone_type']??'')==='DCS'    ?'selected':'' ?>>DCS</option>
          <option value="DMR"     <?= ($rep['tone_type']??'')==='DMR'    ?'selected':'' ?>>DMR</option>
          <option value="FUSION"  <?= ($rep['tone_type']??'')==='FUSION' ?'selected':'' ?>>Fusion / C4FM</option>
          <option value="P-25"    <?= ($rep['tone_type']??'')==='P-25'   ?'selected':'' ?>>P-25</option>
          <option value="D-STAR"  <?= ($rep['tone_type']??'')==='D-STAR' ?'selected':'' ?>>D-STAR</option>
        </select>
      </div>
      <div class="form-group">
        <label>PL Tone (Hz)</label>
        <select name="pl_tone">
          <option value="">None</option>
          <?php foreach ($ctcss_tones as $t): ?>
          <option value="<?= $t ?>" <?= (string)($rep['pl_tone']??'')===$t?'selected':'' ?>><?= $t ?> Hz</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="dcs_field" style="display:none">
        <label>DCS Code</label>
        <input type="text" name="dcs_code" value="<?= h($rep['dcs_code'] ?? '') ?>"
          maxlength="3" placeholder="e.g. 023">
      </div>
      <div class="form-group" id="dmr_cc_field" style="display:none">
        <label>DMR Color Code</label>
        <input type="number" name="dmr_color_code" value="<?= h($rep['dmr_color_code'] ?? '') ?>"
          min="0" max="15" placeholder="0-15">
      </div>
      <div class="form-group">
        <label>Internet Link</label>
        <input type="text" name="internet_link" value="<?= h($rep['internet_link']) ?>" maxlength="50" placeholder="IRLP, EchoLink, WiresX…">
      </div>
      <div class="form-group">
        <label>Website URL</label>
        <input type="url" name="url" value="<?= h($rep['url']) ?>" maxlength="255">
      </div>
      <div class="form-group">
        <label>AllStar Node</label>
        <input type="text" name="allstar_node" value="<?= h($rep['allstar_node'] ?? '') ?>" maxlength="10">
      </div>
      <div class="form-group">
        <label>EchoLink Node</label>
        <input type="text" name="echolink_node" value="<?= h($rep['echolink_node'] ?? '') ?>" maxlength="10">
      </div>
      <div class="form-group">
        <label>Antenna Height AGL (ft)</label>
        <input type="number" name="antenna_height_agl" value="<?= h($rep['antenna_height_agl'] ?? '') ?>" step="0.1">
      </div>
      <div class="form-group">
        <label>HAAT (ft)</label>
        <input type="number" name="haat" value="<?= h($rep['haat'] ?? '') ?>" step="0.1">
      </div>
      <div class="form-group">
        <label>TX Power (watts)</label>
        <input type="number" name="tx_power_watts" value="<?= h($rep['tx_power_watts'] ?? '') ?>" step="0.1">
      </div>
      <div class="form-group">
        <label>Latitude</label>
        <input type="number" name="latitude" value="<?= h($rep['latitude'] ?? '') ?>" step="0.000001">
      </div>
      <div class="form-group">
        <label>Longitude</label>
        <input type="number" name="longitude" value="<?= h($rep['longitude'] ?? '') ?>" step="0.000001">
      </div>
    </div>

    <div style="margin-top:16px;display:flex;flex-wrap:wrap;gap:20px">
      <label class="form-check"><input type="checkbox" name="open_system"   value="1" <?= $rep['open_system']  ?'checked':'' ?>> Open System</label>
      <label class="form-check"><input type="checkbox" name="autopatch"     value="1" <?= $rep['autopatch']    ?'checked':'' ?>> Auto-Patch</label>
      <label class="form-check"><input type="checkbox" name="skywarn"       value="1" <?= $rep['skywarn']      ?'checked':'' ?>> SKYWARN</label>
      <label class="form-check"><input type="checkbox" name="linked"        value="1" <?= $rep['linked']       ?'checked':'' ?>> Linked</label>
      <label class="form-check"><input type="checkbox" name="backup_power"  value="1" <?= $rep['backup_power'] ?'checked':'' ?>> Backup Power</label>
      <label class="form-check"><input type="checkbox" name="allstar"       value="1" <?= $rep['allstar']      ?'checked':'' ?>> AllStar</label>
      <label class="form-check"><input type="checkbox" name="echolink"      value="1" <?= $rep['echolink']     ?'checked':'' ?>> EchoLink</label>
      <label class="form-check"><input type="checkbox" name="mixed_mode" value="1" <?= !empty($rep['mixed_mode']) ? 'checked' : '' ?> onchange="document.getElementById('mixed_mode_types_field').style.display=this.checked?'':'none'"> Mixed Mode</label>
    </div>
    <div id="mixed_mode_types_field" style="<?= !empty($rep['mixed_mode']) ? '' : 'display:none' ?>margin-top:12px;padding:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px">
      <label style="font-size:.85rem;font-weight:600;margin-bottom:8px;display:block">Supported Modes (check all that apply)</label>
      <div style="display:flex;flex-wrap:wrap;gap:12px">
        <?php foreach (['FM','DMR','D-STAR','FUSION','P-25','NXDN'] as $mode): ?>
        <label class="form-check">
          <input type="checkbox" name="mixed_mode_types[]" value="<?= $mode ?>"
            <?= str_contains($rep['mixed_mode_types'] ?? '', $mode) ? 'checked' : '' ?>>
          <?= $mode ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Notes -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-sticky-note"></i> Public Notes</div>
  <div class="card-body">
    <div class="form-group">
      <textarea name="notes" rows="3" style="width:100%;resize:vertical"><?= h($rep['notes'] ?? '') ?></textarea>
    </div>
  </div>
</div>

<div class="form-actions">
  <button type="submit" class="btn btn-success" style="font-size:1rem;padding:10px 24px">
    <i class="fa fa-paper-plane"></i> Submit Update Request
  </button>
  <span class="text-muted" style="font-size:.82rem;align-self:center">Changes will be reviewed by a coordinator before being applied.</span>
</div>
</form>
<script>
function showToneFields(val) {
  document.getElementById('dcs_field').style.display = val === 'DCS' ? '' : 'none';
  document.getElementById('dmr_cc_field').style.display = val === 'DMR' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
  showToneFields(document.querySelector('select[name="tone_type"]').value);
});
</script>

<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
