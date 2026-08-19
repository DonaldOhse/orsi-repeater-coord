<?php
require_once __DIR__ . '/config.php';
$user = current_user();
$flashes = get_flashes();
$page_title = $page_title ?? SITE_NAME;
$b = BASE_PATH;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_PATH ?>/favicon.png">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($page_title) ?> - <?= SITE_SHORT ?></title>
<link rel="stylesheet" href="<?= $b ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<header class="site-header">
  <div class="header-top">
    <span>Oklahoma Repeater Society, Inc. - Frequency Coordination for the State of Oklahoma</span>
    <span><a href="https://oklahomarepeatersociety.org" target="_blank">ORSI Website</a></span>
  </div>
  <div class="header-inner">
    <a href="<?= $b ?>/index.php" class="brand">
      <div class="brand-logo">ORSI</div>
      <div class="brand-text">
        <div class="name">OK Repeater Coordination</div>
        <div class="sub">Oklahoma Repeater Society, Inc.</div>
      </div>
    </a>
    <nav class="main-nav">
      <a href="<?= $b ?>/index.php"><i class="fa fa-list"></i> Repeaters</a>
      <a href="<?= $b ?>/map.php"><i class="fa fa-map-marker-alt"></i> Map</a>
      <?php if (!is_logged_in()): ?><a href="<?= $b ?>/contact.php"><i class="fa fa-envelope"></i> Contact</a><?php endif; ?>

      <?php if (is_logged_in() && in_array($user['role'], ['admin','coordinator'])): ?>
      <?php
      $_pending = (int)get_db()->query("SELECT COUNT(*) FROM coordination_requests WHERE status='PENDING'")->fetchColumn();
      $_upd_pending = (int)get_db()->query("SELECT COUNT(*) FROM update_requests WHERE status='PENDING'")->fetchColumn();
      $_cant_hear = (int)get_db()->query("SELECT COUNT(DISTINCT r.id) FROM repeaters r JOIN repeater_cant_hear ch ON ch.repeater_id=r.id WHERE ch.reported_at > DATE_SUB(NOW(), INTERVAL 120 DAY) GROUP BY r.id HAVING COUNT(DISTINCT ch.callsign) >= (SELECT COALESCE(setting_value,3) FROM system_settings WHERE setting_key='cant_hear_threshold')")->fetchColumn();
      ?>
      <a href="<?= $b ?>/admin/requests.php" style="position:relative">
        <i class="fa fa-inbox"></i> Requests
        <?php if ($_pending): ?><span style="position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;border-radius:50%;width:16px;height:16px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700"><?= $_pending ?></span><?php endif; ?>
      </a>
      <a href="<?= $b ?>/admin/update_requests.php" style="position:relative">
        <i class="fa fa-pen-to-square"></i> Updates
        <?php if ($_upd_pending): ?><span style="position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;border-radius:50%;width:16px;height:16px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700"><?= $_upd_pending ?></span><?php endif; ?>
      </a>
      <?php $_open_tickets = (int)get_db()->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('OPEN','IN_PROGRESS')")->fetchColumn(); ?>
      <a href="<?= $b ?>/admin/tickets.php" style="position:relative">
        <i class="fa fa-ticket"></i> Tickets
        <?php if ($_open_tickets): ?><span style="position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;border-radius:50%;width:16px;height:16px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700"><?= $_open_tickets ?></span><?php endif; ?>
      </a>
      <a href="<?= $b ?>/admin/chat.php"><i class="fa fa-comments"></i> Chat</a>
      <div class="nav-dropdown">
        <a href="#" class="nav-dropdown-toggle"><i class="fa fa-tools"></i> Tools <i class="fa fa-caret-down" style="font-size:.7rem"></i></a>
        <div class="nav-dropdown-menu">
          <a href="<?= $b ?>/conflicts.php"><i class="fa fa-triangle-exclamation"></i> Conflicts</a>
          <a href="<?= $b ?>/admin/freq_check.php"><i class="fa fa-tower-broadcast"></i> Freq Check</a>
          <a href="<?= $b ?>/admin/cant_hear_review.php" style="position:relative">
            <i class="fa fa-ear-deaf"></i> Cant Hear Review
            <?php if ($_cant_hear): ?><span style="position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;border-radius:50%;width:16px;height:16px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700"><?= $_cant_hear ?></span><?php endif; ?>
          </a>
          <a href="<?= $b ?>/admin/rules.php"><i class="fa fa-sliders"></i> Rules</a>
          <a href="<?= $b ?>/admin/import.php"><i class="fa fa-file-import"></i> Import</a>
          <?php if ($user['role'] === 'admin'): ?>
          <a href="<?= $b ?>/admin/send_renewals.php?dry_run=1"><i class="fa fa-rotate"></i> Renewals</a>
          <a href="<?= $b ?>/admin/nudge_stale.php"><i class="fa fa-bell"></i> Nudge Stale</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if (is_logged_in() && $user['role'] === 'admin'): ?>
      <div class="nav-dropdown">
        <a href="#" class="nav-dropdown-toggle"><i class="fa fa-gear"></i> Admin <i class="fa fa-caret-down" style="font-size:.7rem"></i></a>
        <div class="nav-dropdown-menu">
          <a href="<?= $b ?>/admin/users.php"><i class="fa fa-users"></i> Users</a>
          <a href="<?= $b ?>/admin/archive.php"><i class="fa fa-box-archive"></i> Archive</a>
          <a href="<?= $b ?>/admin/audit_log.php"><i class="fa fa-clock-rotate-left"></i> Audit Log</a>
          <a href="<?= $b ?>/admin/email_templates.php"><i class="fa fa-envelope-open-text"></i> Email Templates</a>
          <a href="<?= $b ?>/admin/test_email.php"><i class="fa fa-envelope"></i> Test Email</a>
          <a href="<?= $b ?>/admin/system_check.php"><i class="fa fa-stethoscope"></i> System Check</a>
          <a href="<?= $b ?>/admin/fcc_check.php"><i class="fa fa-id-card"></i> FCC License Check</a>
          <a href="<?= $b ?>/admin/license_review.php"><i class="fa fa-gavel"></i> License Review</a>
          <a href="<?= $b ?>/admin/fcc_outreach.php"><i class="fa fa-envelope-open-text"></i> Trustee Outreach</a>
          <a href="<?= $b ?>/admin/settings.php"><i class="fa fa-gear"></i> Settings</a>
        </div>
      </div>
      <?php endif; ?>
    </nav>
    <div class="header-user">
      <?php if (is_logged_in()): ?>
        <span><i class="fa fa-user"></i> <?= h($user['callsign'] ?: $user['username']) ?> (<?= h($user['role']) ?>)</span>
        <a href="<?= $b ?>/change_password.php" class="btn btn-outline btn-sm" style="margin-right:4px"><i class="fa fa-key"></i></a>
        <a href="<?= $b ?>/logout.php" class="btn btn-outline btn-sm">Log Out</a>
      <?php else: ?>
        <a href="<?= $b ?>/login.php" class="btn btn-outline btn-sm">Log In</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="main-content">
<?php if ($flashes): foreach ($flashes as $f): ?>
<div class="alert alert-<?= h($f['type']) ?>">
  <i class="fa fa-<?= $f['type']==='success'?'check-circle':($f['type']==='danger'?'exclamation-circle':'info-circle') ?>"></i>
  <?= h($f['msg']) ?>
</div>
<?php endforeach; endif; ?>
