<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();
$user = current_user();

// ── Handle apply/reject ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid    = (int)($_POST['upd_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = trim($_POST['coordinator_notes'] ?? '');

    $s = $db->prepare("SELECT * FROM update_requests WHERE id=?");
    $s->execute([$uid]);
    $upd = $s->fetch();

    if ($upd && in_array($action, ['apply','reject'])) {
        if ($action === 'apply') {
            $changes = json_decode($upd['changes'], true);
            // Only apply field changes if it's a structured update (not a mobile app description)
            if ($changes && !isset($changes['description'])) {
                $set_parts = []; $vals = [];
                foreach ($changes as $col => $change) {
                    $set_parts[] = "$col = ?";
                    $vals[]      = $change['new'] === '' ? '' : $change['new'];
                }
                $vals[] = $upd['repeater_id'];
                $db->prepare("UPDATE repeaters SET " . implode(', ', $set_parts) . " WHERE id=?")->execute($vals);
                audit('UPDATE', 'repeaters', $upd['repeater_id'], null, $changes);
            }
            $db->prepare("UPDATE update_requests SET status='APPLIED', coordinator_notes=?, reviewed_at=NOW() WHERE id=?")->execute([$note, $uid]);

            // Email submitter
            $rep = $db->prepare("SELECT callsign, output_freq FROM repeaters WHERE id=?");
            $rep->execute([$upd['repeater_id']]);
            $r = $rep->fetch();
            $msg  = "Your update request for {$r['callsign']} ({$r['output_freq']} MHz) has been APPLIED to the database.\n\n";
            if ($note) $msg .= "Coordinator notes: {$note}\n\n";
            $msg .= "Thank you for helping keep the ORSI database accurate.\n73,\nOklahoma Repeater Society\n";
            orsi_mail($upd["submitter_email"], "Update Request #{$uid} Applied - ORSI", $msg, MAIL_FROM);

            flash('success', "Update #{$uid} applied to repeater record.");
        } else {
            $db->prepare("UPDATE update_requests SET status='REJECTED', coordinator_notes=?, reviewed_at=NOW() WHERE id=?")->execute([$note, $uid]);

            $rep = $db->prepare("SELECT callsign, output_freq FROM repeaters WHERE id=?");
            $rep->execute([$upd['repeater_id']]);
            $r = $rep->fetch();
            $msg  = "Your update request for {$r['callsign']} ({$r['output_freq']} MHz) was not applied.\n\n";
            if ($note) $msg .= "Reason: {$note}\n\n";
            $msg .= "73,\nOklahoma Repeater Society\n";
            orsi_mail($upd["submitter_email"], "Update Request #{$uid} - ORSI", $msg, MAIL_FROM);

            flash('warning', "Update #{$uid} rejected.");
        }
        header('Location: ' . BASE_PATH . '/admin/update_requests.php');
        exit;
    }
}

// ── Single view ───────────────────────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$view_upd = null;
$view_rep = null;
if ($view_id) {
    $s = $db->prepare("SELECT * FROM update_requests WHERE id=?");
    $s->execute([$view_id]);
    $view_upd = $s->fetch();
    if ($view_upd) {
        $r = $db->prepare("SELECT * FROM repeaters WHERE id=?");
        $r->execute([$view_upd['repeater_id']]);
        $view_rep = $r->fetch();
    }
}

// ── List ──────────────────────────────────────────────────────
$filter = $_GET['status'] ?? 'PENDING';
$where  = $filter ? "WHERE ur.status=?" : "WHERE 1=1";
$params = $filter ? [$filter] : [];
$reqs   = $db->prepare("SELECT ur.*, r.callsign, r.output_freq, r.district FROM update_requests ur JOIN repeaters r ON r.id=ur.repeater_id $where ORDER BY ur.submitted_at DESC");
$reqs->execute($params);
$updates = $reqs->fetchAll();
$pending = (int)$db->query("SELECT COUNT(*) FROM update_requests WHERE status='PENDING'")->fetchColumn();

$page_title = 'Repeater Update Requests';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div class="page-title" style="margin:0;border:none;padding:0">
    <i class="fa fa-pen-to-square"></i> Repeater Update Requests
    <?php if ($pending): ?>
    <span class="badge" style="background:#d97706;color:#fff;font-size:.8rem;margin-left:6px"><?= $pending ?> pending</span>
    <?php endif; ?>
  </div>
</div>

<div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach ([''=>'All','PENDING'=>'Pending','APPLIED'=>'Applied','REJECTED'=>'Rejected'] as $s=>$label): ?>
  <a href="?status=<?= urlencode($s) ?>" class="btn btn-sm <?= $filter===$s?'btn-primary':'btn-secondary' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if ($view_upd && $view_rep): ?>
<div class="card" style="margin-bottom:20px;border-top:4px solid #d97706">
  <div class="card-header">
    <i class="fa fa-pen"></i> Update Request #<?= $view_upd['id'] ?> -
    <?= h($view_rep['callsign']) ?> <?= number_format((float)$view_rep['output_freq'],4) ?> MHz
    <span class="badge badge-<?= $view_upd['status']==='PENDING'?'proposed':($view_upd['status']==='APPLIED'?'operational':'dead') ?>" style="margin-left:8px"><?= h($view_upd['status']) ?></span>
  </div>
  <div class="card-body" style="padding:0">
    <!-- Submitter info -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:#f8fafc">
      <strong><?= h($view_upd['submitter_name']) ?></strong> (<?= h($view_upd['submitter_call']) ?>)
      &bull; <a href="mailto:<?= h($view_upd['submitter_email']) ?>"><?= h($view_upd['submitter_email']) ?></a>
      <?= $view_upd['submitter_phone'] ? '&bull; '.h($view_upd['submitter_phone']) : '' ?>
      <?= $view_upd['relationship'] ? '&bull; <em>'.h($view_upd['relationship']).'</em>' : '' ?>
      &bull; <span class="text-muted"><?= substr($view_upd['submitted_at'],0,10) ?></span>
    </div>
    <!-- Changes table -->
    <table class="data-table">
      <thead><tr><th>Field</th><th>Current Value</th><th>Proposed Value</th></tr></thead>
      <tbody>
      <?php
      $field_labels = [
        'status'=>'Status','trustee'=>'Trustee','sponsor'=>'Sponsor','county'=>'County','city'=>'City',
        'pl_tone'=>'PL Tone','tone_type'=>'Tone Type','open_system'=>'Open System',
        'autopatch'=>'Auto-Patch','skywarn'=>'SKYWARN','linked'=>'Linked',
        'backup_power'=>'Backup Power','allstar'=>'AllStar','allstar_node'=>'AllStar Node',
        'echolink'=>'EchoLink','echolink_node'=>'EchoLink Node','internet_link'=>'Internet Link',
        'url'=>'Website','latitude'=>'Latitude','longitude'=>'Longitude',
        'antenna_height_agl'=>'AGL (ft)','haat'=>'HAAT (ft)','tx_power_watts'=>'TX Power',
        'notes'=>'Notes',
      ];
      $changes = json_decode($view_upd['changes'], true) ?? [];
      if (isset($changes['description'])): ?>
      <div style="background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin-bottom:8px">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:6px;text-transform:uppercase;font-weight:600"><i class="fa fa-mobile"></i> Submitted via Mobile App</div>
        <div style="white-space:pre-wrap"><?= h($changes['description']) ?></div>
      </div>
      <?php else:
      // Show trustee mismatch warning if flagged
      if (isset($changes['_trustee_mismatch'])): ?>
      <tr><td colspan="3">
        <div class="alert alert-warning" style="margin:8px 0">
          <i class="fa fa-triangle-exclamation"></i>
          <strong>⚠ Trustee Mismatch:</strong>
          Submitter claimed to be Trustee but their callsign
          <strong><?= h($changes['_trustee_mismatch']['new']) ?></strong>
          does not match the trustee on record
          <strong><?= h($changes['_trustee_mismatch']['old']) ?></strong>.
          Verify identity before applying changes.
        </div>
      </td></tr>
      <?php endif; ?>
      <?php foreach ($changes as $col => $change):
        if ($col === '_trustee_mismatch') continue; // skip internal flag
        $label = $field_labels[$col] ?? $col;
        $old = $change['old'];
        $new = $change['new'];
        $is_bool = in_array($col, ['open_system','autopatch','skywarn','linked','backup_power','allstar','echolink']);
      ?>
      <tr>
        <td><strong><?= h($label) ?></strong></td>
        <td style="color:var(--danger)"><?= $is_bool ? ($old?'Yes':'No') : h($old ?: '-') ?></td>
        <td style="color:var(--success)"><strong><?= $is_bool ? ($new?'Yes':'No') : h($new ?: '-') ?></strong></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($view_upd['status'] === 'PENDING'): ?>
  <div style="padding:16px;border-top:2px solid var(--border);background:#f8fafc">
    <form method="post">
      <input type="hidden" name="upd_id" value="<?= $view_upd['id'] ?>">
      <div class="form-group" style="margin-bottom:12px">
        <label>Coordinator Notes (emailed to submitter)</label>
        <textarea name="coordinator_notes" rows="2" style="width:100%;resize:vertical" placeholder="Optional notes…"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" name="action" value="apply" class="btn btn-success"
          onclick="return confirm('Apply these changes to the repeater record?')">
          <i class="fa fa-check"></i> Apply Changes
        </button>
        <button type="submit" name="action" value="reject" class="btn btn-danger"
          onclick="return confirm('Reject this update request?')">
          <i class="fa fa-times"></i> Reject
        </button>
        <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $view_upd['repeater_id'] ?>" class="btn btn-secondary" target="_blank">
          <i class="fa fa-eye"></i> View Repeater
        </a>
        <a href="<?= BASE_PATH ?>/admin/edit_repeater.php?id=<?= $view_upd['repeater_id'] ?>" class="btn btn-warning" target="_blank">
          <i class="fa fa-pen"></i> Edit Directly
        </a>
        <a href="<?= BASE_PATH ?>/admin/update_requests.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back</a>
      </div>
    </form>
  </div>
  <?php else: ?>
  <div style="padding:12px 16px;border-top:1px solid var(--border)">
    <?php if ($view_upd['coordinator_notes']): ?>
    <p><strong>Coordinator notes:</strong> <?= h($view_upd['coordinator_notes']) ?></p>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/admin/update_requests.php" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> <?= count($updates) ?> Update Request<?= count($updates)!=1?'s':'' ?></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>#</th><th>Status</th><th>Repeater</th><th>Submitted By</th>
        <th>District</th><th>Changes</th><th>Submitted</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php if (!$updates): ?>
      <tr><td colspan="8" class="text-center text-muted" style="padding:30px">No update requests found.</td></tr>
      <?php else: foreach ($updates as $u): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td><span class="badge badge-<?= $u['status']==='PENDING'?'proposed':($u['status']==='APPLIED'?'operational':'dead') ?>"><?= h($u['status']) ?></span></td>
        <td><a href="<?= BASE_PATH ?>/repeater.php?id=<?= $u['repeater_id'] ?>" class="callsign-link"><?= h($u['callsign']) ?></a> <span class="freq"><?= number_format((float)$u['output_freq'],4) ?></span></td>
        <td><?= h($u['submitter_name']) ?> (<?= h($u['submitter_call']) ?>)</td>
        <td><?= h($u['district']) ?></td>
        <?php $ch = json_decode($u['changes'],true) ?? []; ?>
        <td><?= isset($ch['description']) ? '<span class="badge badge-construction"><i class="fa fa-mobile"></i> Mobile</span>' : count($ch).' field'.(count($ch)!=1?'s':'') ?></td>
        <td style="font-size:.78rem;white-space:nowrap"><?= substr($u['submitted_at'],0,10) ?></td>
        <td><a href="?id=<?= $u['id'] ?>&status=<?= urlencode($filter) ?>" class="btn btn-sm btn-primary"><i class="fa fa-eye"></i> Review</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
