<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db  = get_db();
$me  = current_user();
$page_title = 'Trustee Outreach';

// ── QRZ Email Lookup ──────────────────────────────────────────
function qrz_lookup(string $callsign): ?string {
    static $key = null;
    if (!$key) {
        $session = @file_get_contents("https://xmldata.qrz.com/xml/current/?username=w5dro&password=DroLeo123");
        if (!$session) return null;
        $sxml = simplexml_load_string($session);
        $key = (string)($sxml->Session->Key ?? '');
        if (!$key) return null;
    }
    $xml = @file_get_contents("https://xmldata.qrz.com/xml/current/?s={$key}&callsign={$callsign}");
    if (!$xml) return null;
    $data = simplexml_load_string($xml);
    $email = (string)($data->Callsign->email ?? '');
    return $email ?: null;
}

// ── Handle Send Email action ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_outreach') {
    $trustee  = strtoupper(trim($_POST['trustee'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $rep_id   = (int)($_POST['repeater_id'] ?? 0) ?: null;
    $source   = $_POST['email_source'] ?? 'MANUAL';

    if ($trustee && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $subject = "Action Required: Update Your Repeater Record with ORSI";
        $body = "Dear {$trustee},\n\n"
            . "The Oklahoma Repeater Society, Inc. (ORSI) maintains the official repeater "
            . "coordination database for the state of Oklahoma. Our records show that your "
            . "repeater may need to be updated.\n\n"
            . "We have recently integrated the FCC license database and noticed your "
            . "FCC license may be expired or your contact information may be out of date.\n\n"
            . "Please take a moment to review and update your repeater record:\n\n"
            . "1. Visit: " . ORG_URL . "/index.php\n"
            . "2. Search for your callsign or repeater\n"
            . "3. Click 'Submit Information Update' on your repeater's detail page\n\n"
            . "Or if your FCC license has expired, please renew it at:\n"
            . "https://wireless2.fcc.gov/\n\n"
            . "If you have any questions, please contact us at " . MAIL_REPLY_TO . "\n"
            . "or visit our contact page at: " . ORG_URL . "/contact.php\n\n"
            . "Thank you for your continued participation in amateur radio in Oklahoma!\n\n"
            . "73,\nOklahoma Repeater Society, Inc.\n"
            . "coordination@oklahomarepeatersociety.org\n"
            . ORG_URL;

        $sent = orsi_mail($email, $subject, $body);

        if ($sent) {
            $db->prepare("INSERT INTO trustee_outreach 
                (trustee_callsign, repeater_id, email_sent_to, email_source, subject, sent_by)
                VALUES (?,?,?,?,?,?)")
               ->execute([$trustee, $rep_id, $email, $source, $subject, $me['id']]);
            flash('success', "Email sent to {$email} for {$trustee}!");
        } else {
            flash('danger', "Failed to send email to {$email}!");
        }
    }
    header("Location: " . BASE_PATH . "/admin/fcc_outreach.php");
    exit;
}

// ── Handle QRZ lookup ─────────────────────────────────────────
$qrz_result = null;
if (isset($_GET['lookup']) && $_GET['lookup']) {
    $cs = strtoupper(trim($_GET['lookup']));
    $qrz_result = ['callsign' => $cs, 'email' => qrz_lookup($cs)];
}

// ── Get expired trustees with outreach status ─────────────────
$expired = $db->query("
    SELECT 
        UPPER(TRIM(r.trustee)) as trustee,
        ANY_VALUE(f.licensee_name) as licensee_name,
        ANY_VALUE(f.license_status) as license_status,
        ANY_VALUE(f.expiry_date) as expiry_date,
        MAX(DATEDIFF(CURDATE(), f.expiry_date)) as days_expired,
        ANY_VALUE(f.email) as fcc_email,
        ANY_VALUE(f.phone) as fcc_phone,
        ANY_VALUE(f.street_address) as street_address,
        ANY_VALUE(f.city) as fcc_city,
        ANY_VALUE(f.state) as fcc_state,
        ANY_VALUE(f.zip_code) as zip_code,
        GROUP_CONCAT(DISTINCT r.callsign ORDER BY r.callsign SEPARATOR ', ') as repeaters,
        GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') as repeater_ids,
        ANY_VALUE(r.district) as district,
        (SELECT COUNT(*) FROM trustee_outreach o WHERE o.trustee_callsign = UPPER(TRIM(ANY_VALUE(r.trustee)))) as emails_sent,
        (SELECT MAX(sent_at) FROM trustee_outreach o WHERE o.trustee_callsign = UPPER(TRIM(ANY_VALUE(r.trustee)))) as last_sent,
        (SELECT MAX(bounced) FROM trustee_outreach o WHERE o.trustee_callsign = UPPER(TRIM(ANY_VALUE(r.trustee)))) as bounced
    FROM repeaters r
    JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED','UNCOORDINATED')
    AND (f.expiry_date < CURDATE() OR f.license_status IN ('C','T'))
    AND NOT EXISTS (
        SELECT 1 FROM fcc_licenses f2
        WHERE f2.previous_callsign = UPPER(TRIM(r.trustee))
        AND f2.license_status = 'A'
    )
    GROUP BY UPPER(TRIM(r.trustee))
    ORDER BY days_expired DESC
")->fetchAll();

// ── Get outreach history ──────────────────────────────────────
$history = $db->query("
    SELECT o.*, u.callsign as sent_by_call
    FROM trustee_outreach o
    LEFT JOIN users u ON u.id = o.sent_by
    ORDER BY o.sent_at DESC
    LIMIT 50
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-envelope-open-text"></i> Trustee Outreach</div>
<p style="color:var(--muted);font-size:.85rem;margin-bottom:16px">
    Send outreach emails to trustees with expired licenses. Tracks sent emails, bounces, and history.
</p>

<!-- QRZ Lookup -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><i class="fa fa-search"></i> QRZ Email Lookup</div>
  <div style="padding:16px;display:flex;gap:10px;align-items:center">
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <input type="text" name="lookup" value="<?=h($_GET['lookup']??'')?>"
        placeholder="e.g. W5DRO" style="padding:8px;border:1px solid var(--border);border-radius:4px;width:150px;text-transform:uppercase">
      <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Look Up on QRZ</button>
    </form>
    <?php if ($qrz_result): ?>
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 16px">
      <strong><?=h($qrz_result['callsign'])?></strong>:
      <?php if ($qrz_result['email']): ?>
        <span style="color:var(--success)"><?=h($qrz_result['email'])?></span>
        <form method="post" style="display:inline;margin-left:10px">
          <input type="hidden" name="action" value="send_outreach">
          <input type="hidden" name="trustee" value="<?=h($qrz_result['callsign'])?>">
          <input type="hidden" name="email" value="<?=h($qrz_result['email'])?>">
          <input type="hidden" name="email_source" value="QRZ">
          <button type="submit" class="btn btn-primary btn-sm"
            onclick="return confirm('Send outreach email to <?=h($qrz_result['email'])?>?')">
            <i class="fa fa-paper-plane"></i> Send Outreach Email
          </button>
        </form>
      <?php else: ?>
        <span style="color:var(--danger)">No email found on QRZ</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Expired Trustees Table -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#fef2f2;color:#dc2626">
    <i class="fa fa-triangle-exclamation"></i> Expired/Cancelled Licenses — Outreach Needed (<?=count($expired)?>)
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Trustee</th><th>Name</th><th>Status</th><th>Expired</th>
      <th>FCC Email</th><th>Repeaters</th><th>District</th>
      <th>Emails Sent</th><th>Last Sent</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($expired as $r): ?>
    <?php $has_email = !empty($r['fcc_email']); ?>
    <tr style="background:<?=$r['bounced']?'#fef2f2':($r['emails_sent']?'#f0fdf4':'#fff')?>">
      <td><strong><?=h($r['trustee'])?></strong></td>
      <td style="font-size:.82rem"><?=h($r['licensee_name'])?></td>
      <td><span style="color:#dc2626;font-weight:bold;font-size:.75rem">
        <?=$r['license_status']==='C'?'CANCELLED':'EXPIRED'?>
      </span></td>
      <td style="font-size:.82rem;color:#dc2626"><?=h($r['expiry_date'])?><br>
        <small><?=number_format($r['days_expired'])?> days ago</small></td>
      <td style="font-size:.82rem">
        <?php if ($has_email): ?>
          <a href="mailto:<?=h($r['fcc_email'])?>"><?=h($r['fcc_email'])?></a>
          <?php if ($r['bounced']): ?>
            <span style="color:#dc2626;font-size:.7rem"><i class="fa fa-triangle-exclamation"></i> BOUNCED</span>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:var(--muted);font-size:.75rem">Not in FCC DB</span>
          <a href="?lookup=<?=h($r['trustee'])?>" class="btn btn-secondary btn-sm" style="font-size:.7rem;padding:2px 6px">
            <i class="fa fa-search"></i> QRZ
          </a>
        <?php endif; ?>
      </td>
      <td style="font-size:.78rem"><?=h($r['repeaters'])?></td>
      <td><?=h($r['district'])?></td>
      <td style="text-align:center">
        <?php if ($r['emails_sent']): ?>
          <span style="color:var(--success);font-weight:bold"><?=$r['emails_sent']?></span>
        <?php else: ?>
          <span style="color:var(--muted)">0</span>
        <?php endif; ?>
      </td>
      <td style="font-size:.78rem">
        <?=$r['last_sent'] ? date('M j Y', strtotime($r['last_sent'])) : '—'?>
      </td>
      <td>
        <?php if ($has_email && !$r['bounced']): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="send_outreach">
          <input type="hidden" name="trustee" value="<?=h($r['trustee'])?>">
          <input type="hidden" name="email" value="<?=h($r['fcc_email'])?>">
          <input type="hidden" name="email_source" value="FCC">
          <input type="hidden" name="repeater_id" value="<?=explode(',',$r['repeater_ids'])[0]?>">
          <button type="submit" class="btn btn-primary btn-sm"
            onclick="return confirm('Send outreach email to <?=h($r['fcc_email'])?>?')">
            <i class="fa fa-paper-plane"></i>
            <?=$r['emails_sent']?'Resend':'Send'?>
          </button>
        </form>
        <?php elseif ($r['bounced']): ?>
          <span style="color:#dc2626;font-size:.75rem"><i class="fa fa-ban"></i> Bounced</span>
        <?php else: ?>
          <a href="?lookup=<?=h($r['trustee'])?>" class="btn btn-secondary btn-sm">
            <i class="fa fa-search"></i> Find Email
          </a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Outreach History -->
<?php if (!empty($history)): ?>
<div class="card">
  <div class="card-header"><i class="fa fa-clock-rotate-left"></i> Outreach History (Last 50)</div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Trustee</th><th>Sent To</th><th>Source</th>
      <th>Sent By</th><th>Sent At</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
    <tr style="background:<?=$h['bounced']?'#fef2f2':'#fff'?>">
      <td><strong><?=h($h['trustee_callsign'])?></strong></td>
      <td style="font-size:.82rem"><?=h($h['email_sent_to'])?></td>
      <td><span style="font-size:.75rem;background:#f1f5f9;padding:2px 6px;border-radius:3px"><?=h($h['email_source'])?></span></td>
      <td><?=h($h['sent_by_call'] ?? 'System')?></td>
      <td style="font-size:.82rem"><?=date('M j Y g:i A', strtotime($h['sent_at']))?></td>
      <td>
        <?php if ($h['bounced']): ?>
          <span style="color:#dc2626"><i class="fa fa-triangle-exclamation"></i> Bounced <?=date('M j', strtotime($h['bounced_at']))?></span>
        <?php else: ?>
          <span style="color:var(--success)"><i class="fa fa-check"></i> Sent</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
