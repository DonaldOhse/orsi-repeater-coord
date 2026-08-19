<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();

// ── Save / Update Rule ────────────────────────────────────────────
// ── NOPC Contact handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_nopc') {
        $nid   = (int)($_POST['nid']        ?? 0);
        $state = trim($_POST['state']        ?? '');
        $abbr  = strtoupper(trim($_POST['state_abbr']  ?? ''));
        $org   = trim($_POST['org_name']     ?? '');
        $name  = trim($_POST['contact_name'] ?? '');
        $email = trim($_POST['email']        ?? '');
        $phone = trim($_POST['phone']        ?? '');
        $notes = trim($_POST['notes']        ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        if ($nid) {
            $db->prepare("UPDATE nopc_contacts SET state=?,state_abbr=?,org_name=?,contact_name=?,email=?,phone=?,notes=?,active=? WHERE id=?")
               ->execute([$state,$abbr,$org,$name,$email,$phone,$notes,$active,$nid]);
            flash('success', 'NOPC contact updated.');
        } else {
            $db->prepare("INSERT INTO nopc_contacts (state,state_abbr,org_name,contact_name,email,phone,notes,active) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$state,$abbr,$org,$name,$email,$phone,$notes,$active]);
            flash('success', 'NOPC contact added.');
        }
        header('Location: ' . BASE_PATH . '/admin/rules.php#nopc');
        exit;
    }
    if ($action === 'delete_nopc') {
        $db->prepare("DELETE FROM nopc_contacts WHERE id=?")->execute([(int)$_POST['nid']]);
        flash('success', 'NOPC contact deleted.');
        header('Location: ' . BASE_PATH . '/admin/rules.php#nopc');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rule'])) {
    $rid = (int)($_POST['rule_id'] ?? 0);
    $f = [
        'rule_name'             => trim($_POST['rule_name'] ?? ''),
        'band_low_mhz'          => (float)($_POST['band_low_mhz'] ?? 0),
        'band_high_mhz'         => (float)($_POST['band_high_mhz'] ?? 0),
        'channel_step_khz'      => (float)($_POST['channel_step_khz'] ?? 15),
        'channel_width_khz'     => (float)($_POST['channel_width_khz'] ?? 16),
        'co_channel_min_miles'  => (float)($_POST['co_channel_min_miles'] ?? 75),
        'adj_channel_min_miles' => (float)($_POST['adj_channel_min_miles'] ?? 50),
        'notes'                 => trim($_POST['notes'] ?? ''),
    ];
    if ($rid) {
        $db->prepare("UPDATE coordination_rules SET rule_name=?,band_low_mhz=?,band_high_mhz=?,channel_step_khz=?,channel_width_khz=?,co_channel_min_miles=?,adj_channel_min_miles=?,notes=? WHERE id=?")
           ->execute([...(array_values($f)), $rid]);
        flash('success','Rule updated.');
    } else {
        $db->prepare("INSERT INTO coordination_rules (rule_name,band_low_mhz,band_high_mhz,channel_step_khz,channel_width_khz,co_channel_min_miles,adj_channel_min_miles,notes) VALUES (?,?,?,?,?,?,?,?)")
           ->execute(array_values($f));
        flash('success','Rule added.');
    }
    header('Location: ' . BASE_PATH . '/admin/rules.php'); exit;
}

// ── Delete Rule ───────────────────────────────────────────────────
if (isset($_GET['delete']) && $user['role'] === 'admin') {
    $db->prepare("DELETE FROM coordination_rules WHERE id=?")->execute([(int)$_GET['delete']]);
    flash('success','Rule deleted.');
    header('Location: ' . BASE_PATH . '/admin/rules.php'); exit;
}

// ── Load rule for editing ─────────────────────────────────────────
$edit_rule = [];
if (!empty($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM coordination_rules WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $edit_rule = $s->fetch() ?: [];
}

$rules = $db->query("SELECT * FROM coordination_rules ORDER BY band_low_mhz")->fetchAll();
$page_title = 'Coordination Rules';
include __DIR__ . '/../includes/header.php';

function rv(array $r, string $k, mixed $d=''): string {
    return htmlspecialchars((string)($r[$k] ?? $d), ENT_QUOTES, 'UTF-8');
}
?>

<div class="page-title"><i class="fa fa-sliders"></i> Coordination Rules</div>

<div class="alert alert-info" style="font-size:.85rem">
  <i class="fa fa-circle-info"></i>
  These rules define channel spacing, bandwidth, and minimum separation distances for co-channel and adjacent-channel repeaters.
  They are used during the <a href="<?= BASE_PATH ?>/conflicts.php">conflict scan</a>. Rules are matched by output frequency band.
</div>

<!-- Add/Edit Form -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><i class="fa fa-<?= $edit_rule?'pen':'plus' ?>"></i> <?= $edit_rule ? 'Edit Rule' : 'Add New Rule' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="rule_id" value="<?= rv($edit_rule,'id','0') ?>">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1">
          <label>Rule Name *</label>
          <input type="text" name="rule_name" value="<?= rv($edit_rule,'rule_name') ?>" required placeholder="e.g. 2m FM Repeaters">
        </div>
        <div class="form-group">
          <label>Band Low (MHz) *</label>
          <input type="number" name="band_low_mhz" value="<?= rv($edit_rule,'band_low_mhz') ?>" step="0.001" required placeholder="144.0">
        </div>
        <div class="form-group">
          <label>Band High (MHz) *</label>
          <input type="number" name="band_high_mhz" value="<?= rv($edit_rule,'band_high_mhz') ?>" step="0.001" required placeholder="148.0">
        </div>
        <div class="form-group">
          <label>Channel Step (kHz) *
            <span title="Minimum allowed spacing between channels" style="cursor:help;color:var(--muted)">ⓘ</span>
          </label>
          <input type="number" name="channel_step_khz" value="<?= rv($edit_rule,'channel_step_khz','15') ?>" step="0.001" required placeholder="15">
        </div>
        <div class="form-group">
          <label>Channel Width / BW (kHz) *
            <span title="Maximum occupied bandwidth per channel" style="cursor:help;color:var(--muted)">ⓘ</span>
          </label>
          <input type="number" name="channel_width_khz" value="<?= rv($edit_rule,'channel_width_khz','16') ?>" step="0.001" required placeholder="16">
        </div>
        <div class="form-group">
          <label>Co-Channel Min Distance (miles) *
            <span title="Minimum miles between two repeaters on the same channel" style="cursor:help;color:var(--muted)">ⓘ</span>
          </label>
          <input type="number" name="co_channel_min_miles" value="<?= rv($edit_rule,'co_channel_min_miles','75') ?>" step="0.1" required placeholder="75">
        </div>
        <div class="form-group">
          <label>Adjacent-Channel Min Distance (miles) *
            <span title="Minimum miles between repeaters on adjacent channels" style="cursor:help;color:var(--muted)">ⓘ</span>
          </label>
          <input type="number" name="adj_channel_min_miles" value="<?= rv($edit_rule,'adj_channel_min_miles','50') ?>" step="0.1" required placeholder="50">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Notes</label>
          <input type="text" name="notes" value="<?= rv($edit_rule,'notes') ?>" placeholder="Optional description…">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" name="save_rule" value="1" class="btn btn-success">
          <i class="fa fa-save"></i> <?= $edit_rule ? 'Update Rule' : 'Add Rule' ?>
        </button>
        <?php if ($edit_rule): ?>
        <a href="<?= BASE_PATH ?>/admin/rules.php" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Rules Table -->
<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> Current Rules (<?= count($rules) ?>)</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Rule Name</th><th>Band (MHz)</th><th>Step (kHz)</th><th>Width (kHz)</th>
        <th>Co-Ch Min (mi)</th><th>Adj-Ch Min (mi)</th><th>Notes</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if (!$rules): ?>
        <tr><td colspan="8" class="text-center text-muted" style="padding:20px">No rules defined.</td></tr>
      <?php else: foreach ($rules as $rl): ?>
      <tr>
        <td><strong><?= h($rl['rule_name']) ?></strong></td>
        <td><?= h($rl['band_low_mhz']) ?> – <?= h($rl['band_high_mhz']) ?></td>
        <td><?= h($rl['channel_step_khz']) ?></td>
        <td><?= h($rl['channel_width_khz']) ?></td>
        <td><strong style="color:var(--danger)"><?= h($rl['co_channel_min_miles']) ?></strong></td>
        <td><strong style="color:var(--warning)"><?= h($rl['adj_channel_min_miles']) ?></strong></td>
        <td class="text-muted" style="font-size:.8rem"><?= h($rl['notes']) ?></td>
        <td>
          <a href="<?= BASE_PATH ?>/admin/rules.php?edit=<?= $rl['id'] ?>" class="btn btn-sm btn-warning"><i class="fa fa-pen"></i></a>
          <?php if ($user['role']==='admin'): ?>
          <a href="<?= BASE_PATH ?>/admin/rules.php?delete=<?= $rl['id'] ?>" class="btn btn-sm btn-danger"
             data-confirm="Delete rule '<?= h($rl['rule_name']) ?>'?"><i class="fa fa-trash"></i></a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-warning" style="font-size:.85rem;margin-top:16px">
  <i class="fa fa-triangle-exclamation"></i>
  <strong>After changing rules</strong>, go to <a href="<?= BASE_PATH ?>/conflicts.php">Conflicts</a> and run a fresh scan to update conflict detection results.
</div>

<?php
$nopc_contacts = $db->query("SELECT * FROM nopc_contacts ORDER BY state")->fetchAll();
$edit_nopc = null;
if (isset($_GET['edit_nopc'])) {
    $s = $db->prepare("SELECT * FROM nopc_contacts WHERE id=?");
    $s->execute([(int)$_GET['edit_nopc']]);
    $edit_nopc = $s->fetch();
}
?>
<div class="page-title" id="nopc" style="margin-top:30px">
  <i class="fa fa-envelope"></i> NOPC - Neighboring State Coordinator Contacts
</div>
<div class="alert alert-info">
  <i class="fa fa-circle-info"></i>
  <strong>NOPC (Notice of Proposed Coordination)</strong> - When a coordination request is
  within 100 miles of a neighboring state border, you will be prompted to send an NOPC email.
  Update the contacts below to ensure emails reach the right person.
</div>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <i class="fa fa-<?= $edit_nopc ? 'pen' : 'plus' ?>"></i>
    <?= $edit_nopc ? 'Edit' : 'Add' ?> NOPC Contact
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="action" value="save_nopc">
      <input type="hidden" name="nid" value="<?= $edit_nopc['id'] ?? 0 ?>">
      <div class="form-grid">
        <div class="form-group">
          <label>State *</label>
          <input type="text" name="state" value="<?= h($edit_nopc['state'] ?? '') ?>" maxlength="20" placeholder="Texas" required>
        </div>
        <div class="form-group">
          <label>Abbreviation *</label>
          <input type="text" name="state_abbr" value="<?= h($edit_nopc['state_abbr'] ?? '') ?>" maxlength="2" placeholder="TX" style="text-transform:uppercase;max-width:80px" required>
        </div>
        <div class="form-group">
          <label>Organization</label>
          <input type="text" name="org_name" value="<?= h($edit_nopc['org_name'] ?? '') ?>" maxlength="100" placeholder="Texas VHF-FM Society">
        </div>
        <div class="form-group">
          <label>Contact Name</label>
          <input type="text" name="contact_name" value="<?= h($edit_nopc['contact_name'] ?? '') ?>" maxlength="100">
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="email" value="<?= h($edit_nopc['email'] ?? '') ?>" maxlength="150" required>
        </div>
        <div class="form-group">
          <label>Phone</label>
          <input type="tel" name="phone" value="<?= h($edit_nopc['phone'] ?? '') ?>" maxlength="20">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Notes</label>
          <input type="text" name="notes" value="<?= h($edit_nopc['notes'] ?? '') ?>" maxlength="255">
        </div>
        <div class="form-group">
          <label class="form-check">
            <input type="checkbox" name="active" value="1" <?= ($edit_nopc['active'] ?? 1) ? 'checked' : '' ?>>
            Active
          </label>
        </div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Save Contact</button>
        <?php if ($edit_nopc): ?>
        <a href="<?= BASE_PATH ?>/admin/rules.php#nopc" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><i class="fa fa-list"></i> Neighboring State Coordinators (<?= count($nopc_contacts) ?>)</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>State</th><th>Organization</th><th>Contact</th><th>Email</th><th>Phone</th><th>Active</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($nopc_contacts as $nc): ?>
      <tr>
        <td><strong><?= h($nc['state']) ?></strong> <span class="district-badge"><?= h($nc['state_abbr']) ?></span></td>
        <td><?= h($nc['org_name'] ?: '-') ?></td>
        <td><?= h($nc['contact_name'] ?: '-') ?></td>
        <td><a href="mailto:<?= h($nc['email']) ?>"><?= h($nc['email']) ?></a></td>
        <td><?= h($nc['phone'] ?: '-') ?></td>
        <td><?= $nc['active'] ? '<span class="badge badge-operational">Active</span>' : '<span class="badge badge-dead">Inactive</span>' ?></td>
        <td style="display:flex;gap:6px">
          <a href="?edit_nopc=<?= $nc['id'] ?>#nopc" class="btn btn-sm btn-warning"><i class="fa fa-pen"></i></a>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this contact?')">
            <input type="hidden" name="action" value="delete_nopc">
            <input type="hidden" name="nid" value="<?= $nc['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
