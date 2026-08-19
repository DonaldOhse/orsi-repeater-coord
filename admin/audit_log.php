<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$filter_user   = $_GET['user']   ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_table  = $_GET['table']  ?? '';

$where  = ['1=1'];
$params = [];

if ($filter_user) {
    $where[]  = 'u.callsign LIKE ?';
    $params[] = '%' . $filter_user . '%';
}
if ($filter_action) {
    $where[]  = 'a.action = ?';
    $params[] = $filter_action;
}
if ($filter_table) {
    $where[]  = 'a.table_name = ?';
    $params[] = $filter_table;
}

$where_sql = implode(' AND ', $where);

$total_stmt = $db->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE $where_sql");
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();
$pages = ceil($total / $per_page);

$stmt = $db->prepare("SELECT a.*, u.callsign, u.first_name, u.last_name,
    CASE a.table_name
        WHEN 'repeaters' THEN (SELECT callsign FROM repeaters WHERE id=a.record_id LIMIT 1)
        ELSE NULL
    END as record_label
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    WHERE $where_sql
    ORDER BY a.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$tables  = $db->query("SELECT DISTINCT table_name FROM audit_log ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

$action_colors = [
    'INSERT' => 'badge-operational',
    'UPDATE' => 'badge-construction',
    'DELETE' => 'badge-dead',
    'LOGIN'  => 'badge-proposed',
    'LOGOUT' => 'badge-unknown',
];

$page_title = 'Audit Log';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-clock-rotate-left"></i> Audit Log <span style="font-size:.8rem;color:var(--muted);font-weight:400"><?= number_format($total) ?> entries</span></div>

<!-- Filters -->
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end">
  <div class="form-group" style="margin:0">
    <label>User</label>
    <input type="text" name="user" value="<?= h($filter_user) ?>" placeholder="Callsign..." style="width:140px">
  </div>
  <div class="form-group" style="margin:0">
    <label>Action</label>
    <select name="action">
      <option value="">All Actions</option>
      <?php foreach ($actions as $a): ?>
      <option value="<?= h($a) ?>" <?= $filter_action===$a?'selected':'' ?>><?= h($a) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label>Table</label>
    <select name="table">
      <option value="">All Tables</option>
      <?php foreach ($tables as $t): ?>
      <option value="<?= h($t) ?>" <?= $filter_table===$t?'selected':'' ?>><?= h($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-filter"></i> Filter</button>
  <a href="<?= BASE_PATH ?>/admin/audit_log.php" class="btn btn-secondary btn-sm">Clear</a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Table</th>
          <th>Record</th>
          <th>IP</th>
          <th>Changes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $log): ?>
      <tr>
        <td style="white-space:nowrap;font-size:.8rem;color:var(--muted)"><?= substr($log['created_at'],0,16) ?></td>
        <td>
          <?php if ($log['callsign']): ?>
          <strong><?= h($log['callsign']) ?></strong>
          <?php if ($log['first_name']): ?>
          <div style="font-size:.75rem;color:var(--muted)"><?= h($log['first_name'].' '.$log['last_name']) ?></div>
          <?php endif; ?>
          <?php else: ?>
          <span class="text-muted">System</span>
          <?php endif; ?>
        </td>
        <td><span class="badge <?= $action_colors[$log['action']] ?? 'badge-unknown' ?>"><?= h($log['action']) ?></span></td>
        <td style="font-size:.82rem"><?= h($log['table_name']) ?></td>
        <td style="font-size:.82rem">
          <?php if ($log['record_label']): ?>
          <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $log['record_id'] ?>"><?= h($log['record_label']) ?></a>
          <?php elseif ($log['record_id']): ?>
          #<?= $log['record_id'] ?>
          <?php else: ?>-
          <?php endif; ?>
        </td>
        <td style="font-size:.75rem;color:var(--muted)"><?= h($log['ip_address'] ?? '-') ?></td>
        <td>
          <?php
          $old = json_decode($log['old_data'] ?? 'null', true);
          $new = json_decode($log['new_data'] ?? 'null', true);
          if ($old && $new):
            $changed = [];
            foreach ($new as $k => $v) {
              if (isset($old[$k]) && $old[$k] != $v) $changed[] = $k;
            }
            if ($changed):
          ?>
          <details style="font-size:.75rem">
            <summary style="cursor:pointer;color:var(--primary)"><?= count($changed) ?> field<?= count($changed)!=1?'s':'' ?> changed</summary>
            <table style="margin-top:6px;border-collapse:collapse;width:100%">
              <?php foreach ($changed as $col): ?>
              <tr>
                <td style="padding:2px 6px;color:var(--muted);font-weight:600"><?= h($col) ?></td>
                <td style="padding:2px 6px;color:#dc2626;text-decoration:line-through"><?= h($old[$col] ?? '') ?></td>
                <td style="padding:2px 6px;color:#16a34a"><?= h($new[$col] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
          </details>
          <?php elseif ($log['action'] === 'INSERT' && $new): ?>
          <span style="font-size:.75rem;color:var(--muted)">New record</span>
          <?php elseif ($log['action'] === 'DELETE'): ?>
          <span style="font-size:.75rem;color:#dc2626">Deleted</span>
          <?php endif; ?>
          <?php elseif ($log['new_data']): ?>
          <span style="font-size:.75rem;color:var(--muted)">-</span>
          <?php else: ?>-
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?>
      <tr><td colspan="7" class="text-center text-muted" style="padding:30px">No log entries found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap">
    <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <a href="?page=<?= $i ?>&user=<?= urlencode($filter_user) ?>&action=<?= urlencode($filter_action) ?>&table=<?= urlencode($filter_table) ?>"
       class="btn btn-sm <?= $i===$page?'btn-primary':'btn-secondary' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($pages > 20): ?>
    <span class="text-muted" style="padding:4px 8px">... <?= $pages ?> pages total</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
