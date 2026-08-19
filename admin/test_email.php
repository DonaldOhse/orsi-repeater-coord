<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
$db   = get_db();
$user = current_user();

$result   = null;
$test_log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_type = $_POST['test_type'] ?? '';
    $to_email  = trim($_POST['to_email'] ?? '');

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $result = ['type'=>'danger', 'msg'=>'Invalid email address.'];
    } else {
        $headers  = "".MAIL_FROM."\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "Reply-To: noreply@w5dro.com\r\n";

        switch ($test_type) {

            case 'basic':
                $subject = 'ORSI Email Test - Basic';
                $body    = "This is a basic email test from the ORSI coordination system.\n\n";
                $body   .= "Sent by: {$user['callsign']} ({$user['first_name']} {$user['last_name']})\n";
                $body   .= "Server:  " . gethostname() . "\n";
                $body   .= "Time:    " . date('Y-m-d H:i:s') . "\n\n";
                $body   .= "If you received this, email delivery is working correctly.\n\n73,\nORSI System\n";
                $sent = orsi_mail($to_email, $subject, $body, $headers);
                $result = $sent
                    ? ['type'=>'success', 'msg'=>"Basic test email sent to {$to_email}. Check your inbox."]
                    : ['type'=>'danger',  'msg'=>"mail() returned false. Check Postfix logs."];
                break;

            case 'coordinator':
                // Test routing to each district coordinator
                $districts = ['NE','NW','OKC','SE','SW','TUL'];
                $sent_count = 0;
                foreach ($districts as $dist) {
                    $coord_email = get_coordinator_email($dist);
                    if ($coord_email) {
                        $test_log[] = "District {$dist}: {$coord_email}";
                    } else {
                        $test_log[] = "District {$dist}: NO COORDINATOR ASSIGNED";
                    }
                }
                // Send one test to the specified address
                $subject = 'ORSI Email Test - Coordinator Routing';
                $body    = "ORSI Coordinator Email Routing Test\n\n";
                $body   .= "This test shows which coordinator would receive emails per district:\n\n";
                foreach ($test_log as $line) { $body .= "  {$line}\n"; }
                $body   .= "\nSent by: {$user['callsign']}\n";
                $body   .= "Time:    " . date('Y-m-d H:i:s') . "\n\n73,\nORSI System\n";
                $sent = orsi_mail($to_email, $subject, $body, $headers);
                $result = $sent
                    ? ['type'=>'success', 'msg'=>"Coordinator routing test sent to {$to_email}."]
                    : ['type'=>'danger',  'msg'=>"mail() returned false."];
                break;

            case 'renewal':
                $tpl = get_template($db, 'renewal');
                $rendered = render_template($tpl, [
                    '{callsign}'     => 'W5TEST',
                    '{contact_name}' => 'Test Trustee',
                    '{repeaters}'    => "W5TEST - 146.9400 MHz - Oklahoma City\nW5TEST - 443.1000 MHz - Oklahoma City",
                    '{renewal_link}' => 'https://w5dro.com/repeater_coord/renewal.php?token=TEST_TOKEN_NOT_REAL',
                    '{expiry_date}'  => date('F j, Y', strtotime('+30 days')),
                    '{org_name}'     => get_setting('org_name', ORG_NAME),
                    '{org_url}'      => get_setting('org_url', ORG_URL),
                ]);
                $subject = '[TEST] ' . $rendered['subject'];
                $body    = "THIS IS A TEST - NOT A REAL RENEWAL NOTICE\n\n" . $rendered['body'];
                $sent = orsi_mail($to_email, $subject, $body, $headers);
                $result = $sent
                    ? ['type'=>'success', 'msg'=>"Sample renewal email sent to {$to_email} using DB template."]
                    : ['type'=>'danger',  'msg'=>"mail() returned false."];
                break;

            case 'nopc':
                $tpl = get_template($db, 'nopc_initial');
                $rendered = render_template($tpl, [
                    '{callsign}'      => 'W5TEST',
                    '{trustee}'       => 'Test User',
                    '{freq}'          => '147.2400',
                    '{input_freq}'    => '147.8400',
                    '{offset}'        => '+0.600',
                    '{band}'          => '2m High (147 MHz)',
                    '{city}'          => 'Lawton',
                    '{county}'        => 'Comanche',
                    '{latitude}'      => '34.6036',
                    '{longitude}'     => '-98.3959',
                    '{haat}'          => '500',
                    '{antenna_height_agl}' => '120',
                    '{tx_power_watts}'=> '50',
                    '{erp_watts}'     => '100',
                    '{tone}'          => 'CTCSS 100.0 Hz',
                    '{antenna_pattern}' => 'Omnidirectional',
                    '{coverage_estimate}' => '~45 miles (line of sight)',
                    '{approve_link}'  => 'https://w5dro.com/repeater_coord/nopc_response.php?token=TEST&action=approve',
                    '{decline_link}'  => 'https://w5dro.com/repeater_coord/nopc_response.php?token=TEST&action=decline',
                    '{org_name}'      => get_setting('org_name', ORG_NAME),
                    '{org_url}'       => get_setting('org_url', ORG_URL),
                ]);
                $subject = '[TEST] ' . $rendered['subject'];
                $body    = "THIS IS A TEST - NOT A REAL NOPC\n\n" . $rendered['body'];
                $sent = orsi_mail($to_email, $subject, $body, $headers);
                $result = $sent
                    ? ['type'=>'success', 'msg'=>"Sample NOPC email sent to {$to_email} using DB template."]
                    : ['type'=>'danger',  'msg'=>"mail() returned false."];
                break;

            case 'update_request':
                $tpl = get_template($db, 'update_request');
                $rendered = render_template($tpl, [
                    '{callsign}'     => 'W5TEST',
                    '{freq}'         => '146.9400',
                    '{city}'         => 'Oklahoma City',
                    '{county}'       => 'Oklahoma',
                    '{district}'     => 'OKC',
                    '{submitter}'    => 'Test User (W5TEST)',
                    '{relationship}' => 'Trustee',
                    '{changes}'      => "  - Status: 'OPERATIONAL' -> 'DOWN TEMPORARILY'\n  - Notes: '' -> 'Repeater is down for repairs'",
                    '{review_link}'  => 'https://w5dro.com/repeater_coord/admin/update_requests.php',
                    '{org_name}'     => get_setting('org_name', ORG_NAME),
                    '{org_url}'      => get_setting('org_url', ORG_URL),
                ]);
                $subject = '[TEST] ' . $rendered['subject'];
                $body    = "THIS IS A TEST - NOT A REAL UPDATE REQUEST\n\n" . $rendered['body'];
                $sent = orsi_mail($to_email, $subject, $body, $headers);
                $result = $sent
                    ? ['type'=>'success', 'msg'=>"Sample update request email sent to {$to_email} using DB template."]
                    : ['type'=>'danger',  'msg'=>"mail() returned false."];
                break;
        }
    }
}

// Get coordinator assignments for display
$coordinators = $db->query("SELECT callsign, first_name, last_name, email, district, role FROM users WHERE active=1 ORDER BY role, district")->fetchAll();
$nopc_contacts = $db->query("SELECT state, state_abbr, contact_name, email, active FROM nopc_contacts ORDER BY state")->fetchAll();

// Check postfix status
$postfix_running = trim(shell_exec('systemctl is-active postfix 2>/dev/null')) === 'active';
$mail_log = trim(shell_exec('sudo tail -5 /var/log/mail.log 2>/dev/null') ?: '(no access to mail log)');

$page_title = 'Email System Test';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-envelope"></i> Email System Test</div>

<!-- System Status -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-server"></i> Mail System Status</div>
  <div class="card-body">
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="width:12px;height:12px;border-radius:50%;background:<?= $postfix_running?'#16a34a':'#dc2626' ?>;display:inline-block"></span>
        <strong>Postfix: <?= $postfix_running?'Running':'NOT Running' ?></strong>
      </div>
      <div style="font-size:.82rem;color:var(--muted)">
        Sending as: <code>noreply@w5dro.com</code> &bull;
        Server: <code><?= gethostname() ?></code>
      </div>
    </div>
    <div style="background:#1e293b;color:#e2e8f0;padding:10px 14px;border-radius:var(--radius);font-size:.75rem;font-family:monospace;white-space:pre-wrap;max-height:120px;overflow-y:auto"><?= h($mail_log) ?></div>
    <div style="font-size:.72rem;color:var(--muted);margin-top:4px">Last 5 lines of /var/log/mail.log</div>
  </div>
</div>

<!-- Send Test Email -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-paper-plane"></i> Send Test Email</div>
  <div class="card-body">
    <?php if ($result): ?>
    <div class="alert alert-<?= $result['type'] ?>" style="margin-bottom:16px">
      <i class="fa fa-<?= $result['type']==='success'?'circle-check':'circle-xmark' ?>"></i>
      <?= h($result['msg']) ?>
    </div>
    <?php if ($test_log): ?>
    <div style="background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius);padding:12px;font-family:monospace;font-size:.8rem;margin-bottom:16px">
      <?php foreach ($test_log as $line): ?>
      <div><?= h($line) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1">
          <label>Send test email to</label>
          <input type="email" name="to_email" value="<?= h($_POST['to_email'] ?? $user['email']) ?>" required maxlength="150" placeholder="your@email.com">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Test Type</label>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
            <?php foreach ([
                'basic'          => ['Basic Delivery Test',         'fa-envelope',      'btn-primary'],
                'coordinator'    => ['Coordinator Routing Test',     'fa-users',         'btn-secondary'],
                'renewal'        => ['Sample Renewal Notice',        'fa-rotate',        'btn-secondary'],
                'nopc'           => ['Sample NOPC Email',            'fa-flag',          'btn-warning'],
                'update_request' => ['Sample Update Request Email',  'fa-pen-to-square', 'btn-secondary'],
            ] as $type => [$label, $icon, $cls]): ?>
            <button type="submit" name="test_type" value="<?= $type ?>" class="btn <?= $cls ?>">
              <i class="fa <?= $icon ?>"></i> <?= $label ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Coordinator Email Assignments -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-users"></i> Coordinator Email Assignments</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Callsign</th><th>Name</th><th>Role</th><th>District</th><th>Email</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($coordinators as $c): ?>
      <tr>
        <td><strong><?= h($c['callsign']) ?></strong></td>
        <td><?= h(trim($c['first_name'].' '.$c['last_name'])) ?: '-' ?></td>
        <td><?= h($c['role']) ?></td>
        <td><?= $c['district'] ? '<span class="district-badge">'.h($c['district']).'</span>' : '<span class="text-muted">Not assigned</span>' ?></td>
        <td><?= $c['email'] ? '<a href="mailto:'.h($c['email']).'">'.h($c['email']).'</a>' : '<span class="text-muted badge badge-dead">No email</span>' ?></td>
        <td>
          <?php if (!$c['email']): ?>
          <span class="badge badge-dead">⚠ No Email</span>
          <?php elseif (!$c['district'] && $c['role']==='coordinator'): ?>
          <span class="badge badge-unknown">⚠ No District</span>
          <?php else: ?>
          <span class="badge badge-operational">OK</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:10px 16px;border-top:1px solid var(--border)">
    <a href="<?= BASE_PATH ?>/admin/users.php" class="btn btn-sm btn-warning"><i class="fa fa-pen"></i> Edit User Assignments</a>
  </div>
</div>

<!-- NOPC Contacts -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-flag"></i> NOPC Contact Emails</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>State</th><th>Contact</th><th>Email</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($nopc_contacts as $nc): ?>
      <tr>
        <td><strong><?= h($nc['state']) ?></strong> <span class="district-badge"><?= h($nc['state_abbr']) ?></span></td>
        <td><?= h($nc['contact_name'] ?: '-') ?></td>
        <td>
          <?php if ($nc['email'] && strpos($nc['email'], 'example.com') !== false): ?>
          <span class="badge badge-dead">⚠ Placeholder</span> <?= h($nc['email']) ?>
          <?php else: ?>
          <a href="mailto:<?= h($nc['email']) ?>"><?= h($nc['email']) ?></a>
          <?php endif; ?>
        </td>
        <td>
          <?= !$nc['active'] ? '<span class="badge badge-dead">Inactive</span>' : (strpos($nc['email'],'example.com')!==false ? '<span class="badge badge-unknown">⚠ Needs Update</span>' : '<span class="badge badge-operational">OK</span>') ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:10px 16px;border-top:1px solid var(--border)">
    <a href="<?= BASE_PATH ?>/admin/rules.php#nopc" class="btn btn-sm btn-warning"><i class="fa fa-pen"></i> Edit NOPC Contacts</a>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
