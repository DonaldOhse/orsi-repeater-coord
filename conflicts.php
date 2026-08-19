<?php
require_once __DIR__ . '/includes/config.php';
require_role('coordinator');
$page_title = 'Coordination Conflicts';
$db = get_db();

$user = current_user();

$run_scan = isset($_POST['run_scan']) && is_logged_in() && in_array($user['role'] ?? '', ['admin','coordinator']);

if ($run_scan) {
    // Load resolved conflicts BEFORE deleting
    $resolved_stmt = $db->query("SELECT repeater_a_id, repeater_b_id, conflict_type, resolution_note FROM coordination_conflicts WHERE resolved = 1");
    $resolved_pairs = [];
    $resolved_notes = [];
    foreach ($resolved_stmt->fetchAll() as $rp) {
        $key = min($rp['repeater_a_id'],$rp['repeater_b_id']) . '_' . max($rp['repeater_a_id'],$rp['repeater_b_id']) . '_' . $rp['conflict_type'];
        $resolved_pairs[$key] = true;
        $resolved_notes[$key] = $rp['resolution_note'];
    }

    $db->exec("DELETE FROM coordination_conflicts");

    $rules = $db->query("SELECT * FROM coordination_rules ORDER BY band_low_mhz")->fetchAll();
    $all = $db->query("SELECT id, output_freq, input_freq, latitude, longitude, callsign, status FROM repeaters WHERE status NOT IN ('DEAD','DECOORDINATED')")->fetchAll();

    $conflicts_found = 0;

    $insert = $db->prepare("INSERT INTO coordination_conflicts (repeater_a_id, repeater_b_id, conflict_type, distance_miles, freq_diff_khz) VALUES (?,?,?,?,?)");

    for ($i = 0; $i < count($all); $i++) {
        $a = $all[$i];
        for ($j = $i + 1; $j < count($all); $j++) {
            $b = $all[$j];
            $freq_diff_khz = abs((float)$a['output_freq'] - (float)$b['output_freq']) * 1000;

            if ($freq_diff_khz > 200) continue;

            $rule = null;
            foreach ($rules as $rl) {
                if ((float)$a['output_freq'] >= (float)$rl['band_low_mhz'] && (float)$a['output_freq'] <= (float)$rl['band_high_mhz']) {
                    $rule = $rl;
                    break;
                }
            }
            if (!$rule) continue;

            $step_khz = (float)$rule['channel_step_khz'];
            $co_min   = (float)$rule['co_channel_min_miles'];
            $adj_min  = (float)$rule['adj_channel_min_miles'];

            $dist = null;
            if ($a['latitude'] && $a['longitude'] && $b['latitude'] && $b['longitude']) {
                $dist = haversine((float)$a['latitude'], (float)$a['longitude'], (float)$b['latitude'], (float)$b['longitude']);
            }

            $is_co  = $freq_diff_khz < ($step_khz / 2);
            $is_adj = !$is_co && $freq_diff_khz <= $step_khz;

            if ($is_co) {
                $pair_key = min($a['id'],$b['id']) . '_' . max($a['id'],$b['id']) . '_CO_CHANNEL';
                if (isset($resolved_pairs[$pair_key])) continue; // skip resolved
                if ($dist !== null) {
                    if ($dist < $co_min) {
                        $insert->execute([$a['id'], $b['id'], 'CO_CHANNEL', round($dist,2), round($freq_diff_khz,3)]);
                        $conflicts_found++;
                    }
                } else {
                    $insert->execute([$a['id'], $b['id'], 'CO_CHANNEL', null, round($freq_diff_khz,3)]);
                    $conflicts_found++;
                }
            } elseif ($is_adj) {
                $pair_key = min($a['id'],$b['id']) . '_' . max($a['id'],$b['id']) . '_ADJACENT_CHANNEL';
                if (isset($resolved_pairs[$pair_key])) continue; // skip resolved
                if ($dist !== null) {
                    if ($dist < $adj_min) {
                        $insert->execute([$a['id'], $b['id'], 'ADJACENT_CHANNEL', round($dist,2), round($freq_diff_khz,3)]);
                        $conflicts_found++;
                    }
                } else {
                    $insert->execute([$a['id'], $b['id'], 'ADJACENT_CHANNEL', null, round($freq_diff_khz,3)]);
                    $conflicts_found++;
                }
            }
        }
    }


    // Re-insert previously resolved conflicts so they stay resolved
    $reinsert = $db->prepare("INSERT INTO coordination_conflicts (repeater_a_id, repeater_b_id, conflict_type, resolved, resolution_note, resolved_at) VALUES (?,?,?,1,?,NOW())");
    foreach ($resolved_pairs as $key => $val) {
        $parts = explode('_', $key);
        if (count($parts) >= 3) {
            $aid = $parts[0];
            $bid = $parts[1];
            $type = implode('_', array_slice($parts, 2));
            $note = $resolved_notes[$key] ?? '';
            $reinsert->execute([$aid, $bid, $type, $note]);
        }
    }

    audit('CONFLICT_SCAN', 'coordination_conflicts', 0, null, ['conflicts_found'=>$conflicts_found]);
    flash('success', "Scan complete. Found $conflicts_found potential conflicts.");
    header('Location: ' . BASE_PATH . '/conflicts.php');
    exit;
}

if (isset($_POST['resolve_id']) && is_logged_in() && in_array($user['role'] ?? '', ['admin','coordinator'])) {
    $rid  = (int)$_POST['resolve_id'];
    $note = trim($_POST['resolution_note'] ?? '');
    $db->prepare("UPDATE coordination_conflicts SET resolved=1, resolution_note=?, resolved_at=NOW() WHERE id=?")->execute([$note, $rid]);

    // Write resolution note to internal_notes on both repeaters
    if ($note) {
        $conflict = $db->prepare("SELECT * FROM coordination_conflicts WHERE id=?");
        $conflict->execute([$rid]);
        $cc = $conflict->fetch();
        if ($cc) {
            $stamp = date('Y-m-d');
            $entry = "[$stamp] Conflict resolved: {$cc['conflict_type']} on " . number_format((float)$cc['freq_diff_khz'],3) . " kHz diff, " . number_format((float)$cc['distance_miles'],1) . " mi apart. Note: $note";
            // Append to internal_notes for both repeaters
            $db->prepare("UPDATE repeaters SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = '' THEN ? ELSE CONCAT(internal_notes, '
', ?) END WHERE id=?")->execute([$entry, $entry, $cc['repeater_a_id']]);
            $db->prepare("UPDATE repeaters SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = '' THEN ? ELSE CONCAT(internal_notes, '
', ?) END WHERE id=?")->execute([$entry, $entry, $cc['repeater_b_id']]);
        }
    }

    flash('success', 'Conflict marked as resolved and noted on both repeaters.');
    header('Location: ' . BASE_PATH . '/conflicts.php');
    exit;
}

$show_resolved = !empty($_GET['show_resolved']);
$filter_type   = $_GET['ctype'] ?? '';
$where = ['1=1'];
$params = [];
if (!$show_resolved) { $where[] = 'cc.resolved = 0'; }
if ($filter_type)    { $where[] = 'cc.conflict_type = ?'; $params[] = $filter_type; }
$sql_where = implode(' AND ', $where);

$total_open = (int)$db->query("SELECT COUNT(*) FROM coordination_conflicts WHERE resolved=0")->fetchColumn();
$co_open    = (int)$db->query("SELECT COUNT(*) FROM coordination_conflicts WHERE resolved=0 AND conflict_type='CO_CHANNEL'")->fetchColumn();
$adj_open   = (int)$db->query("SELECT COUNT(*) FROM coordination_conflicts WHERE resolved=0 AND conflict_type='ADJACENT_CHANNEL'")->fetchColumn();

$stmt = $db->prepare("
    SELECT cc.*,
           ra.callsign AS call_a, ra.output_freq AS freq_a, ra.city AS city_a, ra.county AS county_a, ra.status AS status_a,
           rb.callsign AS call_b, rb.output_freq AS freq_b, rb.city AS city_b, rb.county AS county_b, rb.status AS status_b
    FROM coordination_conflicts cc
    JOIN repeaters ra ON ra.id = cc.repeater_a_id
    JOIN repeaters rb ON rb.id = cc.repeater_b_id
    WHERE $sql_where
    ORDER BY cc.conflict_type, cc.detected_at DESC
    LIMIT 500
");
$stmt->execute($params);
$conflicts = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-title"><i class="fa fa-triangle-exclamation"></i> Coordination Conflicts</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);max-width:600px">
  <div class="stat-card"><div class="stat-icon"><i class="fa fa-circle-exclamation"></i></div><div class="stat-value" style="color:var(--danger)"><?= $total_open ?></div><div class="stat-label">Open Conflicts</div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fa fa-signal"></i></div><div class="stat-value" style="color:var(--danger)"><?= $co_open ?></div><div class="stat-label">Co-Channel</div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fa fa-arrows-left-right"></i></div><div class="stat-value" style="color:var(--warning)"><?= $adj_open ?></div><div class="stat-label">Adjacent Channel</div></div>
</div>

<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
  <?php if (is_logged_in() && in_array($user['role']??'',['admin','coordinator'])): ?>
  <form method="post" style="display:inline">
    <button type="submit" name="run_scan" value="1" class="btn btn-danger"
      onclick="return confirm('This will clear all unresolved conflicts and re-scan. Continue?')">
      <i class="fa fa-radar"></i> Run Conflict Scan
    </button>
  </form>
  <?php endif; ?>

  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select name="ctype" style="padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius)">
      <option value="">All Types</option>
      <option value="CO_CHANNEL" <?= $filter_type==='CO_CHANNEL'?'selected':'' ?>>Co-Channel Only</option>
      <option value="ADJACENT_CHANNEL" <?= $filter_type==='ADJACENT_CHANNEL'?'selected':'' ?>>Adjacent Only</option>
    </select>
    <label style="display:flex;align-items:center;gap:4px">
      <input type="checkbox" name="show_resolved" value="1" <?= $show_resolved?'checked':'' ?>> Show Resolved
    </label>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-filter"></i> Filter</button>
  </form>
</div>

<div class="alert alert-info" style="font-size:.85rem">
  <i class="fa fa-circle-info"></i>
  Conflicts are calculated based on your <a href="<?= BASE_PATH ?>/admin/rules.php">Coordination Rules</a>.
  Repeaters <strong>without GPS coordinates</strong> are flagged on frequency alone.
  Repeaters <strong>with GPS coordinates</strong> are checked against minimum distance rules.
</div>

<?php if (!$conflicts): ?>
<div class="card"><div class="card-body text-center text-muted" style="padding:40px">
  <i class="fa fa-circle-check" style="font-size:3rem;color:var(--success)"></i>
  <p style="margin-top:12px;font-size:1.1rem">No conflicts found<?= $show_resolved?'':' (unresolved)' ?>.</p>
  <?php if (!$show_resolved): ?><p><a href="?show_resolved=1">Show resolved conflicts</a></p><?php endif; ?>
</div></div>

<?php else: ?>
<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> <?= count($conflicts) ?> Conflict<?= count($conflicts)!=1?'s':'' ?></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Type</th>
        <th colspan="3" style="text-align:center;border-right:2px solid #0f2540">Repeater A</th>
        <th style="text-align:center;background:#0f2540">vs</th>
        <th colspan="3" style="text-align:center">Repeater B</th>
        <th>Freq Δ</th>
        <th>Distance</th>
        <?php if (is_logged_in() && in_array($user['role']??'',['admin','coordinator'])): ?><th>Action</th><?php endif; ?>
      </tr>
      <tr style="background:#243f5c;font-size:.7rem">
        <th></th>
        <th>Callsign</th><th>Frequency</th><th style="border-right:2px solid #0f2540">Location</th>
        <th></th>
        <th>Callsign</th><th>Frequency</th><th>Location</th>
        <th></th><th></th>
        <?php if (is_logged_in() && in_array($user['role']??'',['admin','coordinator'])): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($conflicts as $c): ?>
      <tr style="<?= $c['resolved'] ? 'opacity:.5;background:#f8f9fa' : '' ?>">
        <td style="white-space:nowrap">
          <?php if ($c['conflict_type']==='CO_CHANNEL'): ?>
            <span class="badge badge-dead"><i class="fa fa-signal"></i> Co-Channel</span>
          <?php else: ?>
            <span class="badge badge-proposed"><i class="fa fa-arrows-left-right"></i> Adjacent</span>
          <?php endif; ?>
          <?php if ($c['resolved']): ?><br><small class="text-muted">Resolved</small><?php endif; ?>
        </td>
        <td style="border-left:3px solid #dc2626">
          <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $c['repeater_a_id'] ?>" class="callsign-link" style="font-size:1rem"><?= h($c['call_a']) ?></a><br>
          <small class="text-muted"><?= h($c['status_a']) ?></small>
        </td>
        <td><span class="freq"><?= number_format((float)$c['freq_a'],4) ?></span></td>
        <td style="font-size:.82rem;border-right:2px solid #dee2e6">
          <?= h($c['city_a']) ?><br>
          <span class="text-muted"><?= h($c['county_a']) ?> Co.</span>
        </td>
        <td style="text-align:center;font-size:1.2rem;color:#dc2626;font-weight:700">⚡</td>
        <td style="border-left:3px solid #2563a8">
          <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $c['repeater_b_id'] ?>" class="callsign-link" style="font-size:1rem"><?= h($c['call_b']) ?></a><br>
          <small class="text-muted"><?= h($c['status_b']) ?></small>
        </td>
        <td><span class="freq"><?= number_format((float)$c['freq_b'],4) ?></span></td>
        <td style="font-size:.82rem">
          <?= h($c['city_b']) ?><br>
          <span class="text-muted"><?= h($c['county_b']) ?> Co.</span>
        </td>
        <td style="text-align:center">
          <span class="<?= $c['conflict_type']==='CO_CHANNEL' ? 'conflict-co' : 'conflict-adj' ?> " style="font-size:.85rem">
            <?= $c['freq_diff_khz'] !== null ? number_format((float)$c['freq_diff_khz'],3).' kHz' : '-' ?>
          </span>
        </td>
        <td style="text-align:center;white-space:nowrap">
          <?php if ($c['distance_miles'] !== null): ?>
            <strong><?= number_format((float)$c['distance_miles'],1) ?></strong> mi
          <?php else: ?>
            <span class="text-muted">-</span>
          <?php endif; ?>
        </td>
        <?php if (is_logged_in() && in_array($user['role']??'',['admin','coordinator'])): ?>
        <td>
          <?php if (!$c['resolved']): ?>
          <button class="btn btn-sm btn-success" onclick="resolveConflict(<?= $c['id'] ?>)"><i class="fa fa-check"></i> Resolve</button>
          <?php else: ?>
          <span class="text-muted" style="font-size:.78rem" title="<?= h($c['resolution_note']) ?>"><?= h(substr($c['resolution_note']??'',0,25)) ?></span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div id="resolveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius);padding:28px;max-width:460px;width:90%">
    <h3 style="margin-bottom:16px;color:var(--primary)"><i class="fa fa-check-circle"></i> Resolve Conflict</h3>
    <form method="post">
      <input type="hidden" name="resolve_id" id="resolve_id">
      <div class="form-group" style="margin-bottom:14px">
        <label>Resolution Note</label>
        <textarea name="resolution_note" rows="3" placeholder="Describe how this conflict was resolved..." style="width:100%;padding:8px;border:1px solid var(--border);border-radius:var(--radius)"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Mark Resolved</button>
        <button type="button" onclick="document.getElementById('resolveModal').style.display='none'" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function resolveConflict(id) {
  document.getElementById('resolve_id').value = id;
  document.getElementById('resolveModal').style.display = 'flex';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
