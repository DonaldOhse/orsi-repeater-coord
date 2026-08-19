<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db = get_db();

$page_title = 'FCC License Check';
include __DIR__ . '/../includes/header.php';

// Stats summary
$stats = $db->query("
    SELECT
        SUM(CASE WHEN f.license_status='A' AND f.expiry_date >= CURDATE() THEN 1 ELSE 0 END) as valid,
        SUM(CASE WHEN f.expiry_date < CURDATE() AND f.license_status='A' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN f.license_status IN ('C','T') THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN f.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 365 DAY) AND f.license_status='A' THEN 1 ELSE 0 END) as expiring,
        SUM(CASE WHEN f.callsign IS NULL THEN 1 ELSE 0 END) as not_found
    FROM (SELECT DISTINCT trustee FROM repeaters WHERE archived_at IS NULL AND trustee != '' AND status NOT IN ('DEAD','DECOORDINATED')) AS t
    LEFT JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(t.trustee))
")->fetch();

// Callsign changes
$callsign_changes = $db->query("
    SELECT DISTINCT r.trustee as old_call, f.callsign as new_call, f.licensee_name,
           f.expiry_date, f.license_status,
           GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
           r.district
    FROM repeaters r
    JOIN fcc_licenses f ON f.previous_callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL AND f.license_status = 'A'
    GROUP BY r.trustee, f.callsign, f.licensee_name, f.expiry_date, f.license_status, r.district
    ORDER BY r.district, r.trustee
")->fetchAll();

// Expired/cancelled trustees
$problem_trustees = $db->query("
    SELECT UPPER(TRIM(r.trustee)) as trustee, f.licensee_name, f.license_status,
           f.expiry_date, DATEDIFF(CURDATE(), f.expiry_date) as days_expired,
           GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
           r.district
    FROM repeaters r
    JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED')
    AND (f.expiry_date < CURDATE() OR f.license_status IN ('C','T'))
    GROUP BY UPPER(TRIM(r.trustee)), f.licensee_name, f.license_status, f.expiry_date, r.district
    ORDER BY days_expired DESC
")->fetchAll();

// Expiring soon
$expiring = $db->query("
    SELECT UPPER(TRIM(r.trustee)) as trustee, f.licensee_name, f.license_status,
           f.expiry_date, DATEDIFF(f.expiry_date, CURDATE()) as days_left,
           GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
           r.district
    FROM repeaters r
    JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED')
    AND f.license_status = 'A'
    AND f.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 365 DAY)
    GROUP BY UPPER(TRIM(r.trustee)), f.licensee_name, f.license_status, f.expiry_date, r.district
    ORDER BY days_left ASC
")->fetchAll();

// Not found in FCC DB
$not_found = $db->query("
    SELECT UPPER(TRIM(r.trustee)) as trustee,
           GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
           r.district
    FROM repeaters r
    LEFT JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED')
    AND r.trustee != ''
    AND f.callsign IS NULL
    GROUP BY UPPER(TRIM(r.trustee)), r.district
    ORDER BY r.district
")->fetchAll();
?>

<div class="page-title"><i class="fa fa-id-card"></i> FCC License Check</div>
<p style="color:var(--muted);font-size:.85rem;margin-bottom:16px">
  FCC database last updated: <?php
    $lu = $db->query("SELECT MAX(updated_at) FROM fcc_licenses")->fetchColumn();
    echo $lu ? date('M j, Y g:i A', strtotime($lu)) : 'Unknown';
  ?>. Updates automatically every Monday at 2am.
</p>

<!-- Summary Stats -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
  <?php
  $cards = [
    ['✅', 'Valid', $stats['valid'], 'f0fdf4', '86efac', '15803d'],
    ['⚠️', 'Expiring < 1yr', $stats['expiring'], 'fffbeb', 'fcd34d', '92400e'],
    ['❌', 'Expired', $stats['expired'], 'fef2f2', 'fca5a5', 'dc2626'],
    ['🚫', 'Cancelled', $stats['cancelled'], 'fef2f2', 'fca5a5', 'dc2626'],
    ['🔄', 'Callsign Changed', count($callsign_changes), 'eff6ff', '93c5fd', '1d4ed8'],
    ['❓', 'Not in FCC DB', $stats['not_found'], 'f1f5f9', 'cbd5e1', '64748b'],
  ];
  foreach ($cards as [$icon, $label, $count, $bg, $border, $color]): ?>
  <div style="background:#<?= $bg ?>;border:1px solid #<?= $border ?>;border-radius:8px;padding:12px 20px;text-align:center;min-width:120px">
    <div style="font-size:1.6rem;font-weight:bold;color:#<?= $color ?>"><?= $count ?></div>
    <div style="font-size:.8rem;color:var(--muted)"><?= $icon ?> <?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Callsign Changes -->
<?php if (!empty($callsign_changes)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#eff6ff;color:#1d4ed8">
    <i class="fa fa-rotate"></i> Callsign Changes — Trustee Records Need Updating (<?= count($callsign_changes) ?>)
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Old Callsign</th><th>New Callsign</th><th>Name</th>
        <th>New Expiry</th><th>District</th><th>Repeaters</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($callsign_changes as $c): ?>
      <tr style="background:#eff6ff">
        <td><strong style="color:#dc2626"><?= h($c['old_call']) ?></strong></td>
        <td><strong style="color:#15803d"><?= h($c['new_call']) ?></strong></td>
        <td><?= h($c['licensee_name']) ?></td>
        <td><?= h($c['expiry_date']) ?></td>
        <td><?= h($c['district']) ?></td>
        <td style="font-size:.8rem"><?= h($c['repeaters']) ?></td>
        <td>
          <form method="post" action="<?= BASE_PATH ?>/admin/fcc_check.php" style="display:inline">
            <input type="hidden" name="action" value="update_trustee">
            <input type="hidden" name="old_call" value="<?= h($c['old_call']) ?>">
            <input type="hidden" name="new_call" value="<?= h($c['new_call']) ?>">
            <button type="submit" class="btn btn-primary btn-sm"
              onclick="return confirm('Update all repeaters with trustee <?= h($c['old_call']) ?> to <?= h($c['new_call']) ?>?')">
              <i class="fa fa-check"></i> Update Trustee
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Expired/Cancelled -->
<?php if (!empty($problem_trustees)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#fef2f2;color:#dc2626">
    <i class="fa fa-triangle-exclamation"></i> Expired / Cancelled Licenses (<?= count($problem_trustees) ?>)
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Trustee</th><th>Name</th><th>Status</th>
        <th>Expired</th><th>Days Ago</th><th>District</th><th>Repeaters</th>
      </tr></thead>
      <tbody>
      <?php foreach ($problem_trustees as $p): ?>
      <tr style="background:#fef2f2">
        <td><strong><?= h($p['trustee']) ?></strong></td>
        <td><?= h($p['licensee_name']) ?></td>
        <td><span style="color:#dc2626;font-weight:bold"><?= $p['license_status'] === 'C' ? 'CANCELLED' : 'EXPIRED' ?></span></td>
        <td><?= h($p['expiry_date']) ?></td>
        <td style="color:#dc2626;font-weight:bold"><?= number_format($p['days_expired']) ?> days</td>
        <td><?= h($p['district']) ?></td>
        <td style="font-size:.8rem"><?= h($p['repeaters']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Expiring Soon -->
<?php if (!empty($expiring)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#fffbeb;color:#92400e">
    <i class="fa fa-clock"></i> Expiring Within 1 Year (<?= count($expiring) ?>)
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Trustee</th><th>Name</th><th>Expires</th>
        <th>Days Left</th><th>District</th><th>Repeaters</th>
      </tr></thead>
      <tbody>
      <?php foreach ($expiring as $e): ?>
      <tr style="background:#fffbeb">
        <td><strong><?= h($e['trustee']) ?></strong></td>
        <td><?= h($e['licensee_name']) ?></td>
        <td><?= h($e['expiry_date']) ?></td>
        <td style="color:#92400e;font-weight:bold"><?= $e['days_left'] ?> days</td>
        <td><?= h($e['district']) ?></td>
        <td style="font-size:.8rem"><?= h($e['repeaters']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Not Found -->
<?php if (!empty($not_found)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#f1f5f9;color:#64748b">
    <i class="fa fa-question-circle"></i> Not Found in FCC Database (<?= count($not_found) ?>)
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Trustee Callsign</th><th>District</th><th>Repeaters</th></tr></thead>
      <tbody>
      <?php foreach ($not_found as $n): ?>
      <tr>
        <td><strong><?= h($n['trustee']) ?></strong></td>
        <td><?= h($n['district']) ?></td>
        <td style="font-size:.8rem"><?= h($n['repeaters']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
// Handle update trustee action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_trustee') {
    $old = strtoupper(trim($_POST['old_call'] ?? ''));
    $new = strtoupper(trim($_POST['new_call'] ?? ''));
    if ($old && $new) {
        $count = $db->prepare("UPDATE repeaters SET trustee=? WHERE UPPER(TRIM(trustee))=? AND archived_at IS NULL");
        $count->execute([$new, $old]);
        audit('UPDATE', 'repeaters', 0, null, ['trustee_bulk_update' => "$old -> $new", 'rows' => $count->rowCount()]);
        flash('success', "Updated {$count->rowCount()} repeater(s) trustee from {$old} to {$new}");
    }
    header("Location: " . BASE_PATH . "/admin/fcc_check.php");
    exit;
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
