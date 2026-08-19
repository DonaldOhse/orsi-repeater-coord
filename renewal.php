<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$token  = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');

if (!$token) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

// Look up repeater by token
$stmt = $db->prepare("SELECT * FROM repeaters WHERE renewal_token = ? AND renewal_token_exp >= CURDATE()");
$stmt->execute([$token]);
$rep = $stmt->fetch();

if (!$rep) {
    $page_title = 'Renewal Link Expired';
    include __DIR__ . '/includes/header.php';
    ?>
    <div style="max-width:600px;margin:40px auto;text-align:center">
      <div style="font-size:3rem;color:var(--danger);margin-bottom:16px"><i class="fa fa-circle-xmark"></i></div>
      <h2 style="color:var(--primary)">Renewal Link Expired</h2>
      <p style="margin-top:12px">This renewal link has expired or is invalid.</p>
      <p style="margin-top:8px">Please contact your district coordinator to request a new renewal link.</p>
      <a href="<?= BASE_PATH ?>/index.php" class="btn btn-primary" style="margin-top:20px">Repeater Database</a>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Handle update submission
$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'confirm') {
    if ($action === 'confirm' || isset($_POST['confirm'])) {
        // Simple confirmation - no changes
        $db->prepare("UPDATE repeaters SET
            last_renewal_sent = CURDATE(),
            renewal_token = NULL,
            renewal_token_exp = NULL,
            last_update = CURDATE()
            WHERE id = ?")->execute([$rep['id']]);

        // Notify coordinator
        $coord_email = get_coordinator_email($rep['district'] ?? 'OKC');
        if ($coord_email) {
            orsi_mail($coord_email,
                "Renewal Confirmed: {$rep['callsign']} {$rep['output_freq']} MHz",
                "The trustee for {$rep['callsign']} ({$rep['output_freq']} MHz) has confirmed their listing is current.\n\nRepeater: https://w5dro.com/repeater_coord/repeater.php?id={$rep['id']}\n\n73,\nORSI System",
                MAIL_FROM
            );
        }
        $success = true;
    }

    if (isset($_POST['update'])) {
        // Trustee wants to update info - redirect to update request form
        header('Location: ' . BASE_PATH . '/update_request.php?id=' . $rep['id']);
        exit;
    }
}

$page_title = 'Annual Renewal - ' . $rep['callsign'];
include __DIR__ . '/includes/header.php';

// Calculate years since last coordination
$coord_date = $rep['date_coordinated'] ?? $rep['last_update'] ?? null;
$years_old  = $coord_date ? floor((time() - strtotime($coord_date)) / (365.25 * 86400)) : null;
?>

<div style="max-width:640px;margin:0 auto">

<?php if ($success): ?>
  <div style="text-align:center;padding:40px 0">
    <div style="font-size:3rem;color:var(--success);margin-bottom:16px"><i class="fa fa-circle-check"></i></div>
    <h2 style="color:var(--primary);margin-bottom:12px">Thank You!</h2>
    <p>Your listing for <strong><?= h($rep['callsign']) ?> - <?= number_format((float)$rep['output_freq'],4) ?> MHz</strong> has been renewed.</p>
    <p style="margin-top:8px;color:var(--muted)">You will receive another renewal notice in approximately one year.</p>
    <div style="margin-top:24px;display:flex;gap:10px;justify-content:center">
      <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $rep['id'] ?>" class="btn btn-primary"><i class="fa fa-eye"></i> View Listing</a>
      <a href="<?= BASE_PATH ?>/update_request.php?id=<?= $rep['id'] ?>" class="btn btn-secondary"><i class="fa fa-pen"></i> Submit Updates</a>
    </div>
  </div>

<?php else: ?>

  <div class="page-title"><i class="fa fa-rotate"></i> Annual Renewal</div>

  <div class="alert alert-info">
    <i class="fa fa-circle-info"></i>
    Please review your repeater listing below and confirm it is current, or submit any needed updates.
  </div>

  <!-- Repeater summary -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-tower-broadcast"></i> <?= h($rep['callsign']) ?> - <?= number_format((float)$rep['output_freq'],4) ?> MHz</div>
    <div class="card-body" style="padding:0">
      <table class="detail-table">
        <tr><th>Callsign</th><td><strong><?= h($rep['callsign']) ?></strong></td></tr>
        <tr><th>Output Freq</th><td><span class="freq"><?= number_format((float)$rep['output_freq'],4) ?> MHz</span></td></tr>
        <tr><th>Input Freq</th><td><span class="freq"><?= number_format((float)$rep['input_freq'],4) ?> MHz</span></td></tr>
        <tr><th>Status</th><td><?= h($rep['status']) ?></td></tr>
        <tr><th>Trustee</th><td><?= h($rep['trustee']) ?></td></tr>
        <tr><th>Sponsor</th><td><?= h($rep['sponsor'] ?: '-') ?></td></tr>
        <tr><th>Location</th><td><?= h($rep['city']) ?>, <?= h($rep['county']) ?> County</td></tr>
        <tr><th>Access</th><td><?php
            if ($rep['tone_type'] === 'CTCSS' && $rep['pl_tone']) echo 'CTCSS ' . number_format((float)$rep['pl_tone'],1) . ' Hz';
            elseif ($rep['tone_type'] === 'DCS' && $rep['dcs_code']) echo 'DCS D' . $rep['dcs_code'];
            elseif ($rep['tone_type'] === 'DMR') echo 'DMR CC' . ($rep['dmr_color_code'] ?? '') . ' TS' . ($rep['dmr_time_slot'] ?? '');
            elseif ($rep['tone_type'] === 'TSQ' && $rep['tsq_tone']) echo 'TSQ ' . number_format((float)$rep['tsq_tone'],1) . ' Hz';
            else echo 'Carrier Squelch';
        ?></td></tr>
        <?php if ($rep['mixed_mode']): ?>
        <tr><th>Mixed Mode</th><td><?= h(implode(', ', array_filter(explode(',', $rep['mixed_mode_types'] ?? '')))) ?></td></tr>
        <?php endif; ?>
        <?php if ($rep['fusion_room']): ?>
        <tr><th>Fusion/C4FM</th><td>Yes<?= $rep['fusion_room'] ? ' / Room: ' . h($rep['fusion_room']) : '' ?></td></tr>
        <?php endif; ?>
        <?php if ($rep['allstar']): ?>
        <tr><th>AllStar</th><td>Node <?= h($rep['allstar_node']) ?></td></tr>
        <?php endif; ?>
        <?php if ($rep['echolink']): ?>
        <tr><th>EchoLink</th><td>Node <?= h($rep['echolink_node']) ?></td></tr>
        <?php endif; ?>
        <tr><th>District</th><td><?= h($rep['district']) ?></td></tr>
        <?php if ($years_old !== null): ?>
        <tr><th>Coordinated</th><td><?= h($rep['date_coordinated'] ?? $rep['last_update']) ?> (<?= $years_old ?> year<?= $years_old!=1?'s':'' ?> ago)</td></tr>
        <?php endif; ?>
        <?php if ($rep['notes']): ?>
        <tr><th>Notes</th><td><?= h($rep['notes']) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <?php if ($years_old >= 4): ?>
  <div class="alert alert-warning">
    <i class="fa fa-triangle-exclamation"></i>
    <strong>Important:</strong> This repeater has been coordinated for <?= $years_old ?> years.
    If not renewed, it will be moved to <strong>Unknown</strong> status after 5 years.
  </div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-question-circle"></i> Is this information current?</div>
    <div class="card-body">
      <p style="margin-bottom:16px">Please review the listing above carefully. Is everything correct and is this repeater still active?</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <form method="post" style="display:inline">
          <input type="hidden" name="confirm" value="1">
          <button type="submit" class="btn btn-success" style="font-size:1rem;padding:10px 20px">
            <i class="fa fa-check"></i> Yes - Everything is correct, renew my listing
          </button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="update" value="1">
          <button type="submit" class="btn btn-warning" style="font-size:1rem;padding:10px 20px">
            <i class="fa fa-pen"></i> Some info needs updating
          </button>
        </form>
      </div>
      <p style="margin-top:12px;font-size:.82rem;color:var(--muted)">
        If this repeater is no longer active, please contact your
        <a href="https://oklahomarepeatersociety.org/contact">district coordinator</a>
        to have it removed from the database.
      </p>
    </div>
  </div>

<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
