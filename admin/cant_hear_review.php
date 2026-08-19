<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$db = get_db();

$threshold = (int)get_setting('cant_hear_threshold', '3');
$days = (int)get_setting('confirm_days', '120');

// Handle status update action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $rep_id = (int)$_POST['repeater_id'];
    $action = $_POST['action'];
    
    if ($action === 'mark_unknown') {
        $old_status = $db->query("SELECT status FROM repeaters WHERE id=$rep_id")->fetchColumn();
        $db->prepare("UPDATE repeaters SET status='UNKNOWN', last_update=CURDATE() WHERE id=?")->execute([$rep_id]);
        $db->prepare("DELETE FROM repeater_cant_hear WHERE repeater_id=?")->execute([$rep_id]);
        audit('CANT_HEAR_STATUS', 'repeaters', $rep_id, ['status'=>$old_status], ['status'=>'UNKNOWN', 'reason'=>'Cant hear reports']);
        flash('success', 'Repeater marked UNKNOWN and reports cleared');
    } elseif ($action === 'mark_operational') {
        $db->prepare("UPDATE repeaters SET status='OPERATIONAL', last_update=CURDATE() WHERE id=?")->execute([$rep_id]);
        $db->prepare("DELETE FROM repeater_cant_hear WHERE repeater_id=?")->execute([$rep_id]);
        audit('CANT_HEAR_CLEARED', 'repeaters', $rep_id, [], ['status'=>'OPERATIONAL', 'reason'=>'Coordinator verified operational']);
        flash('success', 'Repeater confirmed OPERATIONAL and reports cleared');
    } elseif ($action === 'dismiss') {
        $db->prepare("DELETE FROM repeater_cant_hear WHERE repeater_id=?")->execute([$rep_id]);
        flash('success', 'Reports dismissed');
    }
    header('Location: ' . BASE_PATH . '/admin/cant_hear_review.php');
    exit;
}

// Get all repeaters with cant hear reports at or above threshold
$flagged = $db->query("
    SELECT r.id, r.callsign, r.output_freq, r.city, r.status, r.district,
           r.contact_name, r.contact_email, r.trustee,
           COUNT(DISTINCT ch.callsign) as report_count,
           MAX(ch.reported_at) as latest_report,
           GROUP_CONCAT(DISTINCT ch.callsign ORDER BY ch.reported_at DESC SEPARATOR ', ') as reporters
    FROM repeaters r
    JOIN repeater_cant_hear ch ON ch.repeater_id = r.id
    WHERE ch.reported_at > DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY r.id
    HAVING report_count >= 1
    ORDER BY report_count DESC, latest_report DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title">
    <h1><i class="fa fa-volume-xmark"></i> Cannot Hear Reports</h1>
    <p class="text-muted">Repeaters reported as unheard — threshold: <?= $threshold ?> reports in <?= $days ?> days</p>
</div>

<?php if (empty($flagged)): ?>
<div class="card" style="padding:24px;text-align:center">
    <i class="fa fa-check-circle" style="font-size:2rem;color:#22c55e"></i>
    <p style="color:#aaa;margin-top:8px">No cannot hear reports at this time.</p>
</div>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>Callsign</th>
                <th>Freq</th>
                <th>City</th>
                <th>Status</th>
                <th>Reports</th>
                <th>Reported By</th>
                <th>Latest</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($flagged as $r): ?>
        <tr>
            <td>
                <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $r['id'] ?>" target="_blank">
                    <strong><?= h($r['callsign']) ?></strong>
                </a>
                <?php if ($r['report_count'] >= $threshold): ?>
                <span class="badge badge-danger" style="margin-left:4px">Action Needed</span>
                <?php endif; ?>
            </td>
            <td><?= number_format((float)$r['output_freq'],4) ?></td>
            <td><?= h($r['city']) ?></td>
            <td><span class="badge badge-<?= $r['status']==='OPERATIONAL'?'success':($r['status']==='UNKNOWN'?'secondary':'warning') ?>"><?= $r['status'] ?></span></td>
            <td>
                <span style="color:<?= $r['report_count'] >= $threshold ? '#ef4444' : '#f59e0b' ?>;font-weight:bold;font-size:1.1rem">
                    <?= $r['report_count'] ?>
                </span>
                <span style="color:#aaa">/ <?= $threshold ?></span>
            </td>
            <td><small style="color:#aaa"><?= h($r['reporters']) ?></small></td>
            <td><small style="color:#aaa"><?= substr($r['latest_report'],0,10) ?></small></td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="repeater_id" value="<?= $r['id'] ?>">
                        <button name="action" value="mark_unknown" class="btn btn-sm btn-danger"
                            onclick="return confirm('Mark <?= h($r['callsign']) ?> as UNKNOWN?')">
                            Mark Unknown
                        </button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="repeater_id" value="<?= $r['id'] ?>">
                        <button name="action" value="mark_operational" class="btn btn-sm btn-success"
                            onclick="return confirm('Confirm <?= h($r['callsign']) ?> is OPERATIONAL?')">
                            Confirm OK
                        </button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="repeater_id" value="<?= $r['id'] ?>">
                        <button name="action" value="dismiss" class="btn btn-sm btn-secondary"
                            onclick="return confirm('Dismiss all reports for <?= h($r['callsign']) ?>?')">
                            Dismiss
                        </button>
                    </form>
                    <a href="<?= BASE_PATH ?>/admin/edit_repeater.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
