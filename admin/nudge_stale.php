<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$db = get_db();

function qrz_lookup(string $callsign): ?array {
    $login_url = "https://xmldata.qrz.com/xml/current/?username=" . urlencode(QRZ_USERNAME) .
                  "&password=" . urlencode(QRZ_PASSWORD) .
                  "&agent=ORSI-Coord-1.0";
    $xml = @simplexml_load_file($login_url);
    if (!$xml) return null;
    $session = $xml->Session ?? null;
    if (!$session || empty($session->Key)) return null;
    $key = (string)$session->Key;
    $lookup_url = "https://xmldata.qrz.com/xml/current/?s={$key}&callsign=" . urlencode($callsign);
    $xml2 = @simplexml_load_file($lookup_url);
    if (!$xml2) return null;
    $c = $xml2->Callsign ?? null;
    if (!$c) return null;
    return [
        'name'  => trim((string)$c->fname . ' ' . (string)$c->name),
        'email' => (string)$c->email,
    ];
}

// Load all repeaters with QRZ lookup
$repeaters = $db->query("
    SELECT id, callsign, trustee, contact_name, contact_email, status, output_freq, city
    FROM repeaters 
    WHERE archived_at IS NULL AND status IN ('PROPOSED','DEAD','UNKNOWN')
    ORDER BY status, callsign
")->fetchAll();

// Enrich with QRZ data
$enriched = [];
foreach ($repeaters as $rep) {
    $email  = $rep['contact_email'] ?? '';
    $name   = $rep['contact_name'] ?? '';
    $source = 'database';

    if (empty($email) && !empty($rep['trustee'])) {
        $qrz = qrz_lookup($rep['trustee']);
        if ($qrz && !empty($qrz['email'])) {
            $email  = $qrz['email'];
            $name   = $qrz['name'];
            $source = 'QRZ trustee';
        }
    }
    if (empty($email) && !empty($rep['callsign'])) {
        $qrz = qrz_lookup($rep['callsign']);
        if ($qrz && !empty($qrz['email'])) {
            $email  = $qrz['email'];
            $name   = $qrz['name'];
            $source = 'QRZ callsign';
        }
    }
    if (empty($email)) $source = 'No email found';

    $enriched[] = array_merge($rep, [
        'resolved_email'  => $email,
        'resolved_name'   => $name,
        'resolved_source' => $source,
    ]);
}

// Handle send
$results = [];
$sent = 0; $failed = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['selected'])) {
    $selected_ids = array_map('intval', $_POST['selected']);
    $dry_run = ($_POST['action'] ?? '') === 'dry_run';

    foreach ($enriched as $rep) {
        if (!in_array($rep['id'], $selected_ids)) continue;
        if (empty($rep['resolved_email'])) continue;

        $body = "Dear {$rep['resolved_name']},

This is a notice from the Oklahoma Repeater Society, Inc. (ORSI) regarding your repeater coordination.

Repeater:  {$rep['callsign']}
Frequency: " . number_format((float)$rep['output_freq'], 4) . " MHz
Location:  {$rep['city']}
Status:    {$rep['status']}

Your repeater is currently listed as {$rep['status']} in our coordination database. We are reaching out to request a status update.

Please visit our coordination system to update your repeater information or confirm its current status:

https://w5dro.com/repeater_coord/

If you have questions, please reply to this email or contact us at donald@w5dro.com

If this repeater is no longer in service, please let us know so we can update our records.

73,
ORSI Coordination Team
Oklahoma Repeater Society, Inc.
https://oklahomarepeatersociety.org/";

        $subject = "Your Repeater {$rep['callsign']} - Status Update Needed - ORSI";

        $ok = $dry_run ? true : orsi_mail($rep['resolved_email'], $subject, $body);
        if ($ok) {
            $sent++;
            if (!$dry_run) audit('NUDGE_EMAIL', 'repeaters', $rep['id'], [], ['email'=>$rep['resolved_email'], 'status'=>$rep['status']]);
        } else {
            $failed++;
        }
        $results[$rep['id']] = ['ok' => $ok, 'dry_run' => $dry_run];
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title">
    <h1><i class="fa fa-bell"></i> Nudge Stale Repeaters</h1>
    <p class="text-muted">Send status update requests to PROPOSED, DEAD and UNKNOWN repeater trustees</p>
</div>

<?php if (!empty($results)): ?>
<div class="alert alert-<?= $failed ? 'warning' : 'success' ?>" style="margin-bottom:16px">
    <?= $results[array_key_first($results)]['dry_run'] ? '<strong>Dry Run:</strong> ' : '' ?>
    <?= $sent ?> email(s) <?= $results[array_key_first($results)]['dry_run'] ? 'would be sent' : 'sent' ?>.
    <?= $failed ? "<strong>{$failed} failed.</strong>" : '' ?>
</div>
<?php endif; ?>

<form method="post">
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3>Stale Repeaters (<?= count($enriched) ?> total)</h3>
        <div style="display:flex;gap:8px">
            <button type="button" onclick="toggleAll(true)" class="btn btn-sm btn-secondary">Select All</button>
            <button type="button" onclick="toggleAll(false)" class="btn btn-sm btn-secondary">None</button>
            <button type="button" onclick="toggleHasEmail()" class="btn btn-sm btn-secondary">Has Email Only</button>
        </div>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th style="width:40px"><input type="checkbox" id="chk_all" onchange="toggleAll(this.checked)"></th>
                <th>Callsign</th>
                <th>Freq</th>
                <th>Status</th>
                <th>Trustee</th>
                <th>Email</th>
                <th>Source</th>
                <?php if (!empty($results)): ?><th>Result</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($enriched as $rep): ?>
            <tr class="<?= empty($rep['resolved_email']) ? 'no-email' : 'has-email' ?>">
                <td>
                    <input type="checkbox" name="selected[]" value="<?= $rep['id'] ?>"
                        class="row-chk" <?= empty($rep['resolved_email']) ? 'disabled' : '' ?>
                        <?= isset($results[$rep['id']]) ? 'checked' : '' ?>>
                </td>
                <td><a href="/repeater_coord/repeater.php?id=<?= $rep['id'] ?>"><?= h($rep['callsign']) ?></a></td>
                <td><?= number_format((float)$rep['output_freq'],4) ?></td>
                <td><span class="badge badge-<?= $rep['status']==='PROPOSED'?'warning':($rep['status']==='UNKNOWN'?'secondary':'danger') ?>"><?= $rep['status'] ?></span></td>
                <td><?= h($rep['trustee']) ?></td>
                <td><?= h($rep['resolved_email']) ?: '<span class="text-muted">—</span>' ?></td>
                <td><small class="text-muted"><?= h($rep['resolved_source']) ?></small></td>
                <?php if (!empty($results)): ?>
                <td>
                    <?php if (isset($results[$rep['id']])): ?>
                        <?php if ($results[$rep['id']]['dry_run']): ?>
                            <span style="color:#888"><i class="fa fa-eye"></i> Preview</span>
                        <?php elseif ($results[$rep['id']]['ok']): ?>
                            <span style="color:green"><i class="fa fa-check"></i> Sent</span>
                        <?php else: ?>
                            <span style="color:red"><i class="fa fa-times"></i> Failed</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="padding:16px;border-top:1px solid var(--border);display:flex;gap:12px">
        <button type="submit" name="action" value="dry_run" class="btn btn-secondary">
            <i class="fa fa-eye"></i> Dry Run
        </button>
        <button type="submit" name="action" value="send" class="btn btn-warning"
            onclick="return confirm('Send nudge emails to selected repeater trustees?')">
            <i class="fa fa-paper-plane"></i> Send Selected
        </button>
    </div>
</div>
</form>

<script>
function toggleAll(on) {
    document.querySelectorAll('.row-chk:not([disabled])').forEach(c => c.checked = on);
    document.getElementById('chk_all').checked = on;
}
function toggleHasEmail() {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
    document.querySelectorAll('tr.has-email .row-chk:not([disabled])').forEach(c => c.checked = true);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
