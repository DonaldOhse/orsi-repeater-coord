<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
$db = get_db();

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        if ($key === 'csrf') continue;
        $db->prepare("UPDATE system_settings SET setting_value=?, updated_by=? WHERE setting_key=?")
           ->execute([trim($value), $_SESSION['user_id'], $key]);
    }
    $msg = ['type'=>'success', 'text'=>'Settings saved successfully.'];
}

$settings = [];
foreach ($db->query("SELECT * FROM system_settings ORDER BY setting_key") as $row) {
    $settings[$row['setting_key']] = $row;
}

$page_title = 'System Settings';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-gear"></i> System Settings</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom:16px">
  <i class="fa fa-circle-check"></i> <?= h($msg['text']) ?>
</div>
<?php endif; ?>

<form method="post">
  <!-- Email System -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-envelope"></i> Email System</div>
    <div class="card-body">

      <div class="form-group">
        <label>Email System</label>
        <div style="display:flex;gap:12px;align-items:center">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="radio" name="email_enabled" value="1" <?= ($settings['email_enabled']['setting_value']??'1')==='1'?'checked':'' ?>>
            <span style="color:#16a34a;font-weight:600"><i class="fa fa-circle-check"></i> Enabled</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="radio" name="email_enabled" value="0" <?= ($settings['email_enabled']['setting_value']??'1')==='0'?'checked':'' ?>>
            <span style="color:#dc2626;font-weight:600"><i class="fa fa-circle-xmark"></i> Disabled</span>
          </label>
        </div>
        <div style="font-size:.8rem;color:var(--muted);margin-top:4px">When disabled, NO emails will be sent from any part of the system.</div>
      </div>

      <div class="form-group">
        <label>Test Mode</label>
        <div style="display:flex;gap:12px;align-items:center">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="radio" name="email_test_mode" value="0" <?= ($settings['email_test_mode']['setting_value']??'0')==='0'?'checked':'' ?>>
            <span style="font-weight:600">Off - Send to real recipients</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="radio" name="email_test_mode" value="1" <?= ($settings['email_test_mode']['setting_value']??'0')==='1'?'checked':'' ?>>
            <span style="color:#d97706;font-weight:600"><i class="fa fa-flask"></i> On - Redirect all to test address</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label>Test Email Address</label>
        <input type="email" name="email_test_address" value="<?= h($settings['email_test_address']['setting_value'] ?? '') ?>" style="width:300px">
        <div style="font-size:.8rem;color:var(--muted);margin-top:4px">All emails go here when test mode is on.</div>
      </div>
    </div>
  </div>

  <!-- Timing -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-clock"></i> Timing Settings</div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label>Renewal Interval (days)</label>
          <input type="number" name="renewal_days" value="<?= h($settings['renewal_days']['setting_value'] ?? '365') ?>" min="1" max="730" style="width:100px">
          <div style="font-size:.8rem;color:var(--muted);margin-top:4px">How often to send annual renewal emails.</div>
        </div>
        <div class="form-group">
          <label>Stale PROPOSED Nudge (days)</label>
          <input type="number" name="proposed_nudge_days" value="<?= h($settings['proposed_nudge_days']['setting_value'] ?? '365') ?>" min="1" max="730" style="width:100px">
          <div style="font-size:.8rem;color:var(--muted);margin-top:4px">Days before nudging PROPOSED repeaters.</div>
        </div>
        <div class="form-group">
          <label>NOPC Auto-Proceed (hours)</label>
          <input type="number" name="nopc_hours" value="<?= h($settings['nopc_hours']['setting_value'] ?? '72') ?>" min="24" max="168" style="width:100px">
          <div style="font-size:.8rem;color:var(--muted);margin-top:4px">Hours before NOPC auto-proceeds with no response.</div>
        </div>
        <div class="form-group">
          <label>NOPC Reminder (hours)</label>
          <input type="number" name="nopc_reminder_hours" value="<?= h($settings['nopc_reminder_hours']['setting_value'] ?? '48') ?>" min="12" max="96" style="width:100px">
          <div style="font-size:.8rem;color:var(--muted);margin-top:4px">Hours before sending NOPC reminder.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Organization -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-building"></i> Organization</div>
    <div class="card-body">
      <div class="form-group">
        <label>Organization Name</label>
        <input type="text" name="org_name" value="<?= h($settings['org_name']['setting_value'] ?? '') ?>" style="width:400px">
      </div>
      <div class="form-group">
        <label>Organization URL</label>
        <input type="text" name="org_url" value="<?= h($settings['org_url']['setting_value'] ?? '') ?>" style="width:400px">
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">
    <i class="fa fa-save"></i> Save Settings
  </button>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
