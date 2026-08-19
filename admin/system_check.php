<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
$db = get_db();

$checks = [];

function check($name, $category, callable $fn) {
    global $checks;
    try {
        $result = $fn();
        $checks[] = ['name'=>$name, 'category'=>$category, 'status'=>$result['status'], 'msg'=>$result['msg']];
    } catch (Exception $e) {
        $checks[] = ['name'=>$name, 'category'=>$category, 'status'=>'fail', 'msg'=>$e->getMessage()];
    }
}

// ── DATABASE ──────────────────────────────────────────────────
check('Database connection', 'Database', function() use ($db) {
    $db->query("SELECT 1");
    return ['status'=>'pass', 'msg'=>'Connected successfully'];
});

check('Repeaters table', 'Database', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM repeaters")->fetchColumn();
    return ['status'=>'pass', 'msg'=>"{$count} repeaters"];
});

check('Email templates', 'Database', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM email_templates")->fetchColumn();
    $keys = $db->query("SELECT template_key FROM email_templates ORDER BY template_key")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['renewal','nopc_initial','nopc_reminder','nopc_expired','proposed_nudge','request_approved','request_denied','request_info_needed'];
    $missing = array_diff($required, $keys);
    if ($missing) return ['status'=>'warn', 'msg'=>"Missing templates: " . implode(', ', $missing)];
    return ['status'=>'pass', 'msg'=>"{$count} templates found"];
});

check('Coordination rules', 'Database', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM coordination_rules")->fetchColumn();
    if ($count == 0) return ['status'=>'warn', 'msg'=>'No coordination rules defined'];
    return ['status'=>'pass', 'msg'=>"{$count} rules defined"];
});

check('System settings', 'Database', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
    $email_enabled = get_setting('email_enabled', '1');
    $test_mode = get_setting('email_test_mode', '0');
    $msg = "{$count} settings. Email: " . ($email_enabled ? 'enabled' : 'DISABLED');
    if ($test_mode) $msg .= ' | TEST MODE ON';
    return ['status'=>$test_mode?'warn':'pass', 'msg'=>$msg];
});

check('Users/Coordinators', 'Database', function() use ($db) {
    $admins = $db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
    $coords = $db->query("SELECT COUNT(*) FROM users WHERE role='coordinator' AND active=1")->fetchColumn();
    $unassigned = $db->query("SELECT COUNT(*) FROM users WHERE active=1 AND (district IS NULL OR district='') AND role='coordinator'")->fetchColumn();
    $msg = "{$admins} admins, {$coords} coordinators";
    if ($unassigned) $msg .= " | {$unassigned} unassigned";
    return ['status'=>$unassigned?'warn':'pass', 'msg'=>$msg];
});

check('NOPC contacts', 'Database', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM nopc_contacts WHERE active=1")->fetchColumn();
    if ($count == 0) return ['status'=>'warn', 'msg'=>'No active NOPC contacts defined'];
    return ['status'=>'pass', 'msg'=>"{$count} active NOPC contacts"];
});

// ── EMAIL ──────────────────────────────────────────────────────
check('Postfix running', 'Email', function() {
    $out = shell_exec('systemctl is-active postfix 2>/dev/null');
    $out = trim($out);
    if ($out === 'active') return ['status'=>'pass', 'msg'=>'Postfix is running'];
    return ['status'=>'fail', 'msg'=>"Postfix status: {$out}"];
});

check('Mail queue', 'Email', function() {
    $out = shell_exec('mailq 2>/dev/null | tail -1');
    $out = trim($out);
    if (strpos($out, 'empty') !== false || strpos($out, '0 Kbytes') !== false) {
        return ['status'=>'pass', 'msg'=>'Mail queue is empty'];
    }
    return ['status'=>'warn', 'msg'=>"Queue: {$out}"];
});

check('Gmail relay configured', 'Email', function() {
    $relay = trim(shell_exec('postconf relayhost 2>/dev/null'));
    if (strpos($relay, 'smtp-relay.gmail.com') !== false) {
        return ['status'=>'pass', 'msg'=>$relay];
    }
    return ['status'=>'warn', 'msg'=>"Relay: {$relay}"];
});

check('DKIM signing', 'Email', function() {
    $out = trim(shell_exec('systemctl is-active opendkim 2>/dev/null'));
    if ($out === 'active') return ['status'=>'pass', 'msg'=>'OpenDKIM is running'];
    return ['status'=>'warn', 'msg'=>"OpenDKIM status: {$out}"];
});

check('SPF record', 'Email', function() {
    $out = shell_exec('dig TXT w5dro.com +short 2>/dev/null');
    if (strpos($out, 'spf1') !== false) {
        return ['status'=>'pass', 'msg'=>'SPF record found'];
    }
    return ['status'=>'warn', 'msg'=>'SPF record not found'];
});

check('DKIM DNS record', 'Email', function() {
    $out = shell_exec('dig TXT mail._domainkey.w5dro.com +short 2>/dev/null');
    if (strpos($out, 'DKIM1') !== false || strpos($out, 'v=DKIM1') !== false) {
        return ['status'=>'pass', 'msg'=>'DKIM DNS record found'];
    }
    return ['status'=>'warn', 'msg'=>'DKIM DNS record not found or not propagated'];
});

//check('PTR record', 'Email', function() {
//    $out = trim(shell_exec('dig -x 104.151.173.9 +short 2>/dev/null'));
//    if (empty($out)) return ['status'=>'warn', 'msg'=>'No PTR record (contact Centranet Fiber)'];
//    return ['status'=>'pass', 'msg'=>"PTR: {$out}"];
//});

// ── FILE SYSTEM ────────────────────────────────────────────────
check('Web root writable', 'Files', function() {
    $path = '/var/www/w5dro.com/repeater_coord/splat_cache/';
    if (is_writable($path)) return ['status'=>'pass', 'msg'=>'splat_cache/ is writable'];
    return ['status'=>'fail', 'msg'=>'splat_cache/ is not writable'];
});

check('Config file', 'Files', function() {
    $f = '/var/www/w5dro.com/repeater_coord/includes/config.php';
    if (file_exists($f)) return ['status'=>'pass', 'msg'=>'config.php exists'];
    return ['status'=>'fail', 'msg'=>'config.php missing'];
});

check('Renewal log', 'Files', function() {
    $f = '/var/log/orsi_renewals.log'; // Created when cron first runs
    if (file_exists($f)) {
        $size = filesize($f);
        $modified = date('Y-m-d H:i', filemtime($f));
        return ['status'=>'pass', 'msg'=>"Log exists, last modified: {$modified}, size: {$size} bytes"];
    }
    return ['status'=>'warn', 'msg'=>'Renewal log not found (cron may not have run yet)'];
});

// ── CRON ───────────────────────────────────────────────────────
check('Renewal cron job', 'Cron', function() {
    $cron = @file_get_contents('/var/spool/cron/crontabs/root');
    $out = $cron ?: shell_exec('crontab -l 2>/dev/null');
    if (strpos($out, 'send_renewals.php') !== false) {
        return ['status'=>'pass', 'msg'=>'Renewal cron job is configured'];
    }
    return ['status'=>'warn', 'msg'=>'Cannot read cron (run: sudo crontab -l to verify)'];
});

check('Last renewal run', 'Cron', function() use ($db) {
    $last = $db->query("SELECT MAX(last_renewal_sent) FROM repeaters WHERE last_renewal_sent IS NOT NULL")->fetchColumn();
    if ($last) return ['status'=>'pass', 'msg'=>"Last renewal sent: {$last}"];
    return ['status'=>'warn', 'msg'=>'No renewals have been sent yet'];
});

// ── API ────────────────────────────────────────────────────────
check('API endpoint', 'API', function() {
    $url = 'https://w5dro.com/repeater_coord/api/index.php?path=repeaters&limit=1';
    $ctx = stream_context_create(['http'=>['timeout'=>5]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['status'=>'fail', 'msg'=>'API not responding'];
    $data = json_decode($resp, true);
    if (isset($data['data']) || isset($data['repeaters'])) return ['status'=>'pass', 'msg'=>'API responding correctly'];
    return ['status'=>'warn', 'msg'=>'API response unexpected: ' . substr($resp, 0, 100)];
});

check('QRZ API', 'API', function() {
    $url = "https://xmldata.qrz.com/xml/current/?username=" . urlencode(QRZ_USERNAME) .
           "&password=" . urlencode(QRZ_PASSWORD) . "&agent=ORSI-Coord-1.0";
    $ctx = stream_context_create(['http'=>['timeout'=>5]]);
    $xml = @simplexml_load_file($url);
    if (!$xml) return ['status'=>'fail', 'msg'=>'Cannot reach QRZ API'];
    $key = (string)($xml->Session->Key ?? '');
    if ($key) return ['status'=>'pass', 'msg'=>'QRZ API authenticated successfully'];
    $err = (string)($xml->Session->Error ?? 'Unknown error');
    return ['status'=>'fail', 'msg'=>"QRZ error: {$err}"];
});

// ── REPEATER DATA ─────────────────────────────────────────────
check('Repeaters missing coordinates', 'Data', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM repeaters WHERE status='OPERATIONAL' AND (latitude IS NULL OR longitude IS NULL)")->fetchColumn();
    if ($count == 0) return ['status'=>'pass', 'msg'=>'All operational repeaters have coordinates'];
    return ['status'=>'warn', 'msg'=>"{$count} operational repeaters missing coordinates"];
});

check('Repeaters missing trustee email', 'Data', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM repeaters WHERE status='OPERATIONAL' AND (contact_email IS NULL OR contact_email='')")->fetchColumn();
    if ($count == 0) return ['status'=>'pass', 'msg'=>'All operational repeaters have contact emails'];
    return ['status'=>'warn', 'msg'=>"{$count} operational repeaters missing contact email"];
});

check('Pending coordination requests', 'Data', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM coordination_requests WHERE status='PENDING'")->fetchColumn();
    if ($count == 0) return ['status'=>'pass', 'msg'=>'No pending requests'];
    return ['status'=>'warn', 'msg'=>"{$count} pending coordination request(s)"];
});

check('Pending update requests', 'Data', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM update_requests WHERE status='PENDING'")->fetchColumn();
    if ($count == 0) return ['status'=>'pass', 'msg'=>'No pending update requests'];
    return ['status'=>'warn', 'msg'=>"{$count} pending update request(s)"];
});

check('Expired NOPC notifications', 'Data', function() use ($db) {
    $count = $db->query("SELECT COUNT(*) FROM nopc_notifications WHERE status='PENDING' AND expires_at < NOW()")->fetchColumn();
    if ($count == 0) return ['status'=>'pass', 'msg'=>'No expired NOPC notifications'];
    return ['status'=>'warn', 'msg'=>"{$count} expired NOPC notification(s) still pending"];
});

// ── SUMMARY ────────────────────────────────────────────────────
$pass = count(array_filter($checks, fn($c) => $c['status']==='pass'));
$warn = count(array_filter($checks, fn($c) => $c['status']==='warn'));
$fail = count(array_filter($checks, fn($c) => $c['status']==='fail'));

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title">
    <h1><i class="fa fa-stethoscope"></i> System Health Check</h1>
    <p class="text-muted">Comprehensive system diagnostics — run at <?= date('Y-m-d H:i:s') ?></p>
</div>

<div style="display:flex;gap:16px;margin-bottom:20px">
    <div class="card" style="flex:1;padding:16px;text-align:center;border-top:4px solid #22c55e">
        <div style="font-size:2rem;font-weight:bold;color:#22c55e"><?= $pass ?></div>
        <div style="color:#aaa">Passed</div>
    </div>
    <div class="card" style="flex:1;padding:16px;text-align:center;border-top:4px solid #f59e0b">
        <div style="font-size:2rem;font-weight:bold;color:#f59e0b"><?= $warn ?></div>
        <div style="color:#aaa">Warnings</div>
    </div>
    <div class="card" style="flex:1;padding:16px;text-align:center;border-top:4px solid #ef4444">
        <div style="font-size:2rem;font-weight:bold;color:#ef4444"><?= $fail ?></div>
        <div style="color:#aaa">Failed</div>
    </div>
    <div class="card" style="flex:1;padding:16px;text-align:center;border-top:4px solid #3b82f6">
        <div style="font-size:2rem;font-weight:bold;color:#3b82f6"><?= count($checks) ?></div>
        <div style="color:#aaa">Total Checks</div>
    </div>
</div>

<?php
$categories = array_unique(array_column($checks, 'category'));
foreach ($categories as $cat):
    $cat_checks = array_filter($checks, fn($c) => $c['category']==$cat);
?>
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3><?= $cat ?></h3></div>
    <table class="table" style="margin:0">
        <?php foreach ($cat_checks as $c): ?>
        <tr>
            <td style="width:32px">
                <?php if ($c['status']==='pass'): ?>
                    <i class="fa fa-circle-check" style="color:#22c55e"></i>
                <?php elseif ($c['status']==='warn'): ?>
                    <i class="fa fa-triangle-exclamation" style="color:#f59e0b"></i>
                <?php else: ?>
                    <i class="fa fa-circle-xmark" style="color:#ef4444"></i>
                <?php endif; ?>
            </td>
            <td style="width:220px;font-weight:500"><?= h($c['name']) ?></td>
            <td style="color:#aaa"><?= h($c['msg']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endforeach; ?>

<div style="margin-top:16px">
    <a href="<?= BASE_PATH ?>/admin/system_check.php" class="btn btn-primary">
        <i class="fa fa-rotate"></i> Run Again
    </a>
    <a href="<?= BASE_PATH ?>/admin/test_email.php" class="btn btn-secondary" style="margin-left:8px">
        <i class="fa fa-envelope"></i> Email Tests
    </a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
