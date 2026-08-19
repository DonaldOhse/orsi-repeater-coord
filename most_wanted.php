<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$total       = (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE private=0")->fetchColumn();
$missing_email = (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE private=0 AND (contact_email IS NULL OR contact_email='')")->fetchColumn();
$unknown_dead  = (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE private=0 AND status IN ('UNKNOWN','DEAD')")->fetchColumn();
$missing_coords= (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE private=0 AND (latitude IS NULL OR longitude IS NULL OR location_source='CITY')")->fetchColumn();
$no_renewal    = (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE private=0 AND (last_renewal_sent IS NULL OR last_renewal_sent < DATE_SUB(CURDATE(), INTERVAL 2 YEAR))")->fetchColumn();

$health_score = $total > 0 ? round((($total - $missing_email - $unknown_dead/2) / $total) * 100) : 100;
$health_score = max(0, min(100, $health_score));
$health_color = $health_score >= 80 ? '#16a34a' : ($health_score >= 60 ? '#d97706' : '#dc2626');
$health_label = $health_score >= 80 ? 'Good' : ($health_score >= 60 ? 'Fair' : 'Needs Attention');

$wanted = $db->query("
    SELECT id, callsign, output_freq, city, status, trustee,
           contact_email, latitude, longitude, location_source, last_renewal_sent,
           (
               CASE WHEN status IN ('UNKNOWN','DEAD') THEN 30 ELSE 0 END +
               CASE WHEN status = 'PROPOSED' THEN 5 ELSE 0 END +
               CASE WHEN contact_email IS NULL OR contact_email='' THEN 20 ELSE 0 END +
               CASE WHEN latitude IS NULL OR longitude IS NULL OR location_source='CITY' THEN 15 ELSE 0 END +
               CASE WHEN last_renewal_sent IS NULL OR last_renewal_sent < DATE_SUB(CURDATE(), INTERVAL 2 YEAR) THEN 10 ELSE 0 END
           ) AS priority_score
    FROM repeaters WHERE private=0
    HAVING priority_score >= 10
    ORDER BY priority_score DESC, callsign ASC
    LIMIT 25
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ORSI Most Wanted Repeaters</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
<style>
body { padding: 0; }
.mw-wrap { max-width: 900px; margin: 0 auto; padding: 16px; }
.gauge-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-bottom: 16px; box-shadow: var(--shadow); }
.gauge-bar { background: #e2e8f0; border-radius: 20px; height: 28px; overflow: hidden; margin: 8px 0; }
.gauge-fill { height: 100%; border-radius: 20px; display: flex; align-items: center; justify-content: flex-end; padding-right: 12px; transition: width 1s ease; }
.gauge-label { font-size: .82rem; font-weight: bold; color: #fff; }
.stats-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.stat-box { flex: 1; min-width: 80px; background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius); padding: 10px; text-align: center; }
.stat-box .num { font-size: 1.4rem; font-weight: 700; }
.stat-box .lbl { font-size: .7rem; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }
.missing-tag { display: inline-flex; align-items: center; gap: 3px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 2px 6px; border-radius: 4px; font-size: .7rem; margin: 1px; }
.missing-tag.yellow { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.missing-tag.gray { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
.embed-section { background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; margin-top: 16px; }
.embed-section p { font-size: .8rem; color: var(--muted); margin-bottom: 6px; }
.embed-code { background: var(--white); border: 1px solid var(--border); padding: 8px 10px; border-radius: var(--radius); font-family: monospace; font-size: .75rem; color: var(--text); word-break: break-all; cursor: pointer; }
.embed-code:hover { border-color: var(--primary-m); }
.rank-num { color: var(--muted); font-size: .85rem; text-align: center; }
.mw-header { background: var(--primary); color: #fff; padding: 14px 20px; display: flex; align-items: center; gap: 12px; }
.mw-header img { height: 38px; }
.mw-header h1 { font-size: 1.1rem; margin: 0; }
.mw-header p { font-size: .78rem; color: rgba(255,255,255,.7); margin: 2px 0 0; }
.mw-footer { text-align: center; padding: 14px; border-top: 1px solid var(--border); margin-top: 8px; }
.mw-footer a { color: var(--primary-m); font-size: .8rem; }
@media(max-width:600px){ .hide-sm { display:none; } }
</style>
</head>
<body>

<div class="mw-header">
  <img src="<?= BASE_PATH ?>/favicon.png" alt="ORSI">
  <div>
    <h1>ORSI Most Wanted Repeaters</h1>
    <p>Oklahoma Repeater Society, Inc. &mdash; Help us keep our database accurate!</p>
  </div>
</div>

<div class="mw-wrap">

  <!-- Health Gauge -->
  <div class="gauge-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <span style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Database Health</span>
      <span style="font-size:.85rem;font-weight:700;color:<?= $health_color ?>"><?= $health_label ?></span>
    </div>
    <div class="gauge-bar">
      <div class="gauge-fill" style="width:<?= $health_score ?>%;background:<?= $health_color ?>">
        <span class="gauge-label"><?= $health_score ?>%</span>
      </div>
    </div>
    <div class="stats-row">
      <div class="stat-box"><div class="num" style="color:var(--primary)"><?= $total ?></div><div class="lbl">Total</div></div>
      <div class="stat-box"><div class="num" style="color:#dc2626"><?= $unknown_dead ?></div><div class="lbl">Unknown/Dead</div></div>
      <div class="stat-box"><div class="num" style="color:#ea580c"><?= $missing_email ?></div><div class="lbl">No Contact</div></div>
      <div class="stat-box"><div class="num" style="color:#d97706"><?= $missing_coords ?></div><div class="lbl">No GPS</div></div>
      <div class="stat-box"><div class="num" style="color:#7c3aed"><?= $no_renewal ?></div><div class="lbl">No Renewal</div></div>
    </div>
  </div>

  <!-- Most Wanted List -->
  <div class="card">
    <div class="card-header">
      <i class="fa fa-star"></i>
      Top <?= count($wanted) ?> Most Wanted &mdash; <span style="font-weight:400;color:var(--muted)">Sorted by priority score</span>
    </div>
    <table class="table" style="margin:0">
      <thead>
        <tr>
          <th style="width:32px">#</th>
          <th>Callsign</th>
          <th>Frequency</th>
          <th class="hide-sm">City</th>
          <th>Status</th>
          <th>Needs</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($wanted as $i => $r): ?>
      <tr>
        <td class="rank-num"><?= $i+1 ?></td>
        <td>
          <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $r['id'] ?>" target="_blank" style="font-weight:700;color:var(--primary)">
            <?= h($r['callsign']) ?>
          </a>
          <?php if ($r['trustee']): ?>
            <div style="font-size:.72rem;color:var(--muted)"><?= h($r['trustee']) ?></div>
          <?php endif; ?>
        </td>
        <td><span class="freq"><?= number_format((float)$r['output_freq'],4) ?></span></td>
        <td class="hide-sm" style="color:var(--muted);font-size:.85rem"><?= h($r['city']) ?></td>
        <td>
          <?php
          $bc = match($r['status']) {
            'UNKNOWN' => 'badge-secondary',
            'DEAD'    => 'badge-danger',
            'PROPOSED'=> 'badge-warning',
            default   => 'badge-success'
          };
          ?>
          <span class="badge <?= $bc ?>"><?= $r['status'] ?></span>
        </td>
        <td>
          <?php if (empty($r['contact_email'])): ?>
            <span class="missing-tag">&#x2709; No Email</span>
          <?php endif; ?>
          <?php if (empty($r['latitude']) || $r['location_source']==='CITY'): ?>
            <span class="missing-tag yellow">&#x1F4CD; <?= $r['location_source']=='CITY' ? 'City GPS Only' : 'No GPS' ?></span>
          <?php endif; ?>
          <?php if (in_array($r['status'], ['UNKNOWN','DEAD'])): ?>
            <span class="missing-tag">&#x26A0; <?= $r['status'] ?></span>
          <?php endif; ?>
          <?php if (empty($r['last_renewal_sent']) || strtotime($r['last_renewal_sent']) < strtotime('-2 years')): ?>
            <span class="missing-tag gray">&#x1F4C5; No Renewal</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="<?= BASE_PATH ?>/update_request.php?id=<?= $r['id'] ?>"
             target="_blank" class="btn btn-primary btn-sm">Submit Info</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Embed Code -->
  <div class="embed-section">
    <p><strong>Embed this on your website:</strong></p>
    <div class="embed-code" id="embed-code">&lt;iframe src="https://w5dro.com/repeater_coord/most_wanted.php" width="100%" height="700" frameborder="0" style="border:1px solid #dde4ed;border-radius:5px"&gt;&lt;/iframe&gt;</div>
    <p style="margin-top:6px;font-size:.75rem">Click to copy</p>
  </div>

  <div class="mw-footer">
    <a href="<?= BASE_PATH ?>/" target="_blank">View Full Repeater Directory</a>
    &nbsp;&bull;&nbsp;
    <a href="<?= BASE_PATH ?>/request.php" target="_blank">Submit New Coordination Request</a>
    <div style="color:var(--muted);font-size:.72rem;margin-top:6px">
      &copy; <?= date('Y') ?> Oklahoma Repeater Society, Inc.
    </div>
  </div>

</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>
document.getElementById('embed-code').addEventListener('click', function() {
  const text = this.textContent;
  navigator.clipboard.writeText(text).then(() => {
    this.style.background = '#f0fdf4';
    this.style.borderColor = '#16a34a';
    const orig = this.textContent;
    this.textContent = '✓ Copied to clipboard!';
    setTimeout(() => {
      this.textContent = orig;
      this.style.background = '';
      this.style.borderColor = '';
    }, 2000);
  });
});
</script>
</body>
</html>
