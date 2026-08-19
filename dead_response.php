<?php
require_once __DIR__ . '/includes/config.php';
$db     = get_db();
$token  = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');

if (!$token || !in_array($action, ['restore','remove'])) {
    die('<h2>Invalid or missing token.</h2>');
}

$rep = $db->prepare("SELECT * FROM repeaters WHERE dead_notice_token=? AND dead_notice_token_exp > NOW()");
$rep->execute([$token]);
$rep = $rep->fetch();

if (!$rep) { ?>
<!DOCTYPE html><html><head><title>ORSI - Link Expired</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css"></head>
<body style="padding:40px;max-width:600px;margin:0 auto">
<div class="alert alert-danger"><i class="fa fa-times-circle"></i> This link has expired or is invalid. Please contact your district coordinator.</div>
</body></html>
<?php exit; }

// Process response
$db->prepare("UPDATE repeaters SET dead_notice_response=?, dead_notice_token=NULL WHERE id=?")->execute([$action, $rep['id']]);

if ($action === 'restore') {
    $db->prepare("UPDATE repeaters SET status='DOWN TEMPORARILY', last_update=CURDATE() WHERE id=?")->execute([$rep['id']]);
    audit('DEAD_NOTICE_RESTORE', 'repeaters', $rep['id'], ['status'=>'DEAD'], ['status'=>'DOWN TEMPORARILY']);
    $msg = "Thank you! <strong>{$rep['callsign']}</strong> has been updated to <strong>DOWN TEMPORARILY</strong>. Please contact your coordinator to update the status once back on air.";
    $color = '#16a34a';
    $icon = 'fa-check-circle';
    // Notify coordinator
    $coord_email = get_coordinator_email($rep['district'] ?? 'OKC');
    if ($coord_email) {
        orsi_mail($coord_email, "Repeater Restore Request: {$rep['callsign']}", 
            "Trustee {$rep['trustee']} has indicated they plan to restore {$rep['callsign']} ({$rep['output_freq']} MHz) in {$rep['city']}.\n\nPlease follow up with the trustee.\n\n73,\nORSI System");
    }
} else {
    $db->prepare("UPDATE repeaters SET archived_at=NOW(), archived_by=0, archived_reason='Trustee requested removal via dead notice' WHERE id=?")->execute([$rep['id']]);
    audit('DEAD_NOTICE_REMOVE', 'repeaters', $rep['id'], ['status'=>'DEAD'], ['archived'=>true]);
    $msg = "Thank you for letting us know. <strong>{$rep['callsign']}</strong> has been removed from the ORSI database.";
    $color = '#dc2626';
    $icon = 'fa-trash';
}
?>
<!DOCTYPE html>
<html><head>
<title>ORSI - Repeater Status Response</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="padding:40px;max-width:600px;margin:0 auto">
<div style="text-align:center;margin-bottom:24px">
  <img src="<?= BASE_PATH ?>/favicon.png" style="height:50px">
  <h2 style="color:#1a3a5c;margin-top:8px">Oklahoma Repeater Society</h2>
</div>
<div class="alert" style="border-left-color:<?= $color ?>;background:<?= $action==='restore'?'#f0fdf4':'#fef2f2' ?>">
  <i class="fa <?= $icon ?>" style="color:<?= $color ?>"></i>
  <?= $msg ?>
</div>
<div class="card" style="padding:16px;margin-top:16px">
  <p style="color:var(--muted);font-size:.9rem">If you have questions, please contact your district coordinator or visit:</p>
  <a href="<?= BASE_PATH ?>/" class="btn btn-primary" style="margin-top:8px">
    <i class="fa fa-database"></i> ORSI Repeater Directory
  </a>
</div>
</body></html>
