<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db = get_db();

// Handle restore
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id && $action === 'restore') {
        $rep = $db->prepare("SELECT callsign FROM repeaters WHERE id=? AND archived_at IS NOT NULL");
        $rep->execute([$id]);
        $r = $rep->fetch();
        if ($r) {
            $db->prepare("UPDATE repeaters SET archived_at=NULL, archived_by=NULL, archived_reason=NULL WHERE id=?")->execute([$id]);
            audit('RESTORE', 'repeaters', $id, ['archived'=>true], ['archived'=>false]);
            flash('success', "Repeater {$r['callsign']} has been restored to the active database.");
        }
    } elseif ($id && $action === 'purge') {
        $rep = $db->prepare("SELECT callsign FROM repeaters WHERE id=? AND archived_at IS NOT NULL");
        $rep->execute([$id]);
        $r = $rep->fetch();
        if ($r) {
            $db->prepare("DELETE FROM repeaters WHERE id=? AND archived_at IS NOT NULL")->execute([$id]);
            audit('PURGE', 'repeaters', $id, ['callsign'=>$r['callsign']], []);
            flash('success', "Repeater {$r['callsign']} has been permanently deleted.");
        }
    }
    header('Location: ' . BASE_PATH . '/admin/archive.php');
    exit;
}

// Get archived repeaters
$archived = $db->query("
    SELECT r.*, u.username as archived_by_name
    FROM repeaters r
    LEFT JOIN users u ON u.id = r.archived_by
    WHERE r.archived_at IS NOT NULL
    ORDER BY r.archived_at DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title">
  <i class="fa fa-box-archive"></i> Repeater Archive
  <span style="font-size:.9rem;font-weight:400;color:var(--muted);margin-left:8px"><?= count($archived) ?> archived repeaters</span>
</div>

<?php if (empty($archived)): ?>
<div class="card" style="padding:32px;text-align:center">
  <i class="fa fa-box-archive" style="font-size:2.5rem;color:var(--muted)"></i>
  <p style="color:var(--muted);margin-top:12px">No archived repeaters.</p>
</div>
<?php else: ?>
<div class="alert alert-info">
  <i class="fa fa-info-circle"></i>
  Archived repeaters are hidden from the public database, maps, and API. Use <strong>Restore</strong> to bring them back or <strong>Purge</strong> to permanently delete.
</div>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>Callsign</th>
        <th>Frequency</th>
        <th>City</th>
        <th>Status</th>
        <th>Trustee</th>
        <th>Archived</th>
        <th>Reason</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($archived as $r): ?>
    <tr>
      <td><strong><?= h($r['callsign']) ?></strong></td>
      <td><?= number_format((float)$r['output_freq'],4) ?></td>
      <td><?= h($r['city']) ?></td>
      <td><span class="badge badge-secondary"><?= h($r['status']) ?></span></td>
      <td><?= h($r['trustee']) ?></td>
      <td>
        <span style="font-size:.8rem"><?= substr($r['archived_at'],0,10) ?></span>
        <?php if ($r['archived_by_name']): ?>
          <br><small style="color:var(--muted)"><?= h($r['archived_by_name']) ?></small>
        <?php endif; ?>
      </td>
      <td><small style="color:var(--muted)"><?= h($r['archived_reason']) ?></small></td>
      <td>
        <div style="display:flex;gap:6px">
          <form method="post" style="display:inline">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button name="action" value="restore" class="btn btn-sm btn-success"
              onclick="return confirm('Restore <?= h($r['callsign']) ?> to the active database?')">
              <i class="fa fa-rotate-left"></i> Restore
            </button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button name="action" value="purge" class="btn btn-sm btn-danger"
              onclick="return confirm('PERMANENTLY DELETE <?= h($r['callsign']) ?>? This cannot be undone!')">
              <i class="fa fa-trash"></i> Purge
            </button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
