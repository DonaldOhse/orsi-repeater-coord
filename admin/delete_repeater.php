<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$id     = (int)($_GET['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if (!$id) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

$db  = get_db();
$rep = $db->prepare("SELECT * FROM repeaters WHERE id=? AND archived_at IS NULL");
$rep->execute([$id]);
$rep = $rep->fetch();

if (!$rep) { flash('danger', 'Repeater not found or already archived.'); header('Location: ' . BASE_PATH . '/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$reason) { flash('danger', 'Please provide a reason for archiving.'); header('Location: ' . BASE_PATH . '/admin/delete_repeater.php?id=' . $id); exit; }

    $db->prepare("UPDATE repeaters SET archived_at=NOW(), archived_by=?, archived_reason=? WHERE id=?")
       ->execute([$_SESSION['user_id'], $reason, $id]);

    audit('ARCHIVE', 'repeaters', $id, ['status'=>$rep['status']], ['archived_reason'=>$reason]);
    flash('success', "Repeater {$rep['callsign']} has been archived. It can be restored from the Archive page.");
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title">
  <i class="fa fa-archive"></i> Archive Repeater
</div>

<div class="card" style="max-width:600px;margin:0 auto">
  <div class="card-header" style="background:#fef2f2;color:#b91c1c">
    <i class="fa fa-triangle-exclamation"></i> Archive <?= h($rep['callsign']) ?>?
  </div>
  <div style="padding:20px">
    <div class="alert alert-warning">
      <i class="fa fa-info-circle"></i>
      This repeater will be <strong>archived</strong> and hidden from the public database. It can be restored later from the Archive page.
    </div>

    <table class="table" style="margin-bottom:16px">
      <tr><td style="color:var(--muted)">Callsign</td><td><strong><?= h($rep['callsign']) ?></strong></td></tr>
      <tr><td style="color:var(--muted)">Frequency</td><td><?= number_format((float)$rep['output_freq'],4) ?> MHz</td></tr>
      <tr><td style="color:var(--muted)">City</td><td><?= h($rep['city']) ?></td></tr>
      <tr><td style="color:var(--muted)">Status</td><td><?= h($rep['status']) ?></td></tr>
      <tr><td style="color:var(--muted)">Trustee</td><td><?= h($rep['trustee']) ?></td></tr>
    </table>

    <form method="post">
      <div class="form-group">
        <label>Reason for Archiving *</label>
        <select name="reason" required>
          <option value="">— Select reason —</option>
          <option value="Trustee requested removal">Trustee requested removal</option>
          <option value="Repeater permanently off air">Repeater permanently off air</option>
          <option value="Duplicate entry">Duplicate entry</option>
          <option value="Coordination withdrawn">Coordination withdrawn</option>
          <option value="Frequency reused">Frequency reused</option>
          <option value="Other">Other</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <a href="<?= BASE_PATH ?>/admin/edit_repeater.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-danger">
          <i class="fa fa-archive"></i> Archive Repeater
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
