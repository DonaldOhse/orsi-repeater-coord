<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$id = (int)($_GET['id'] ?? 0);
$r  = $db->prepare("SELECT * FROM repeaters WHERE archived_at IS NULL AND id = ?");
$r->execute([$id]);
$rep = $r->fetch();
if (!$rep) { header('Location: ' . BASE_PATH . '/index.php'); exit; }
if ($rep['private'] && !is_logged_in()) {
    http_response_code(403);
    die('<div style="text-align:center;padding:60px;font-family:Arial"><h2>Access Denied</h2><p>This repeater record is private.</p><a href="' . BASE_PATH . '/login.php">Log in</a></div>');
}

$page_title = h($rep['callsign']) . ' - ' . number_format((float)$rep['output_freq'],4) . ' MHz';

// Find nearby repeaters (same or adjacent freq)
$nearby = $db->prepare("SELECT *, ABS(output_freq - ?) AS freq_diff_mhz FROM repeaters WHERE archived_at IS NULL AND id != ? AND ABS(output_freq - ?) <= 0.05 ORDER BY freq_diff_mhz, output_freq LIMIT 20");
$nearby->execute([$rep['output_freq'], $id, $rep['output_freq']]);
$near_rows = $nearby->fetchAll();

// Calculate distance and conflict status for each nearby repeater
$rep_lat = (float)($rep['latitude'] ?? 0);
$rep_lon = (float)($rep['longitude'] ?? 0);
$near_rule = $db->query("SELECT * FROM coordination_rules WHERE band_low_mhz <= {$rep['output_freq']} AND band_high_mhz >= {$rep['output_freq']} LIMIT 1")->fetch();
$co_min  = $near_rule ? (float)$near_rule['co_channel_min_miles']    : 120.0;
$adj_15  = $near_rule ? (float)($near_rule['adj_15khz_min_miles'] ?? 40.0) : 40.0;
$adj_20  = $near_rule ? (float)($near_rule['adj_20khz_min_miles'] ?? 25.0) : 25.0;
$adj_30  = $near_rule ? (float)($near_rule['adj_30khz_min_miles'] ?? 20.0) : 20.0;

foreach ($near_rows as &$n) {
    $n['distance_mi'] = null;
    $n['conflict'] = false;
    $n['conflict_type'] = '';
    if ($rep_lat && $rep_lon && $n['latitude'] && $n['longitude']) {
        $dlat = ($rep_lat - $n['latitude']) * M_PI/180;
        $dlon = ($rep_lon - $n['longitude']) * M_PI/180;
        $a = sin($dlat/2)*sin($dlat/2) + cos($rep_lat*M_PI/180)*cos($n['latitude']*M_PI/180)*sin($dlon/2)*sin($dlon/2);
        $dist = 3958.8 * 2 * atan2(sqrt($a), sqrt(1-$a));
        $n['distance_mi'] = round($dist, 1);
        $city_margin = ($n['location_source'] ?? '') === 'CITY' ? 0.85 : 1.0;
        $eff = $dist * $city_margin;
        $diff_khz = round((float)$n['freq_diff_mhz'] * 1000, 1);
        if ($diff_khz < 0.5  && $eff < $co_min)  { $n['conflict'] = true; $n['conflict_type'] = "Co-channel ({$co_min} mi min)"; }
        elseif ($diff_khz <= 15.5 && $eff < $adj_15) { $n['conflict'] = true; $n['conflict_type'] = "15 kHz adj ({$adj_15} mi min)"; }
        elseif ($diff_khz <= 20.5 && $eff < $adj_20) { $n['conflict'] = true; $n['conflict_type'] = "20 kHz adj ({$adj_20} mi min)"; }
        elseif ($diff_khz <= 30.5 && $eff < $adj_30) { $n['conflict'] = true; $n['conflict_type'] = "30 kHz adj ({$adj_30} mi min)"; }
    }
}
unset($n);

include __DIR__ . '/includes/header.php';

function row(string $label, mixed $val, bool $pre=false): void {
    echo '<tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">' . htmlspecialchars($label) . '</th><td style="padding:8px 12px">' . ($pre ? '<pre style="margin:0;font-family:monospace">' . htmlspecialchars((string)$val) . '</pre>' : htmlspecialchars((string)$val)) . '</td></tr>';
}
function bool_row(string $label, mixed $val): void {
    $icon = $val ? '<span class="bool-yes"><i class="fa fa-check"></i> Yes</span>' : '<span class="bool-no"><i class="fa fa-times"></i> No</span>';
    echo '<tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">' . htmlspecialchars($label) . '</th><td style="padding:8px 12px">' . $icon . '</td></tr>';
}
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
  <a href="<?= BASE_PATH ?>/index.php" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
  <?php if (!$rep['private']): ?>
  <a href="<?= BASE_PATH ?>/update_request.php?id=<?= $id ?>" class="btn btn-warning btn-sm"><i class="fa fa-pen-to-square"></i> Submit Info Update</a>
  <?php endif; ?>
  <?php if (is_logged_in() && in_array($user['role'],['admin','coordinator'])): ?>
  <a href="<?= BASE_PATH ?>/admin/edit_repeater.php?id=<?= $id ?>" class="btn btn-warning btn-sm"><i class="fa fa-pen"></i> Edit</a>
  <?php endif; ?>
  <h1 style="font-size:1.5rem;color:var(--primary);margin:0"><?= h($rep['callsign']) ?><?= $rep['private'] ? ' <span style="color:#d97706" title="Private"><i class="fa fa-lock"></i></span>' : '' ?> &mdash; <span class="freq"><?= number_format((float)$rep['output_freq'],4) ?> MHz</span></h1>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="detail-grid">

  <!-- Left column -->
  <div class="card">
    <div class="card-header"><i class="fa fa-tower-broadcast"></i> Frequency & Identity</div>
    <div class="card-body" style="padding:0">
      <table style="width:100%;border-collapse:collapse">
        <?php row('Output Freq', number_format((float)$rep['output_freq'],4).' MHz'); ?>
        <?php row('Input Freq',  number_format((float)$rep['input_freq'],4).' MHz'); ?>
        <?php
        $offset = round((float)$rep['input_freq'] - (float)$rep['output_freq'], 4);
        $offset_display = ($offset > 0 ? '+' : '') . number_format($offset, 3) . ' MHz';
        row('Offset', $offset_display);
        ?>
        <?php
        // Smart access display based on tone type and mixed mode
        if ($rep['mixed_mode'] && $rep['mixed_mode_types']) {
            // Mixed mode - show access for each mode
            $modes = explode(',', $rep['mixed_mode_types']);
            foreach ($modes as $mode) {
                $mode = trim($mode);
                switch ($mode) {
                    case 'FM':
                        if ($rep['tone_type'] === 'CTCSS' && $rep['pl_tone'])
                            row('FM Access', number_format((float)$rep['pl_tone'],1).' Hz CTCSS/PL');
                        elseif ($rep['tone_type'] === 'DCS' && $rep['dcs_code'])
                            row('FM Access', 'DCS D'.$rep['dcs_code']);
                        elseif ($rep['tone_type'] === 'TSQ' && $rep['tsq_tone'])
                            row('FM Access', number_format((float)$rep['tsq_tone'],1).' Hz TSQ');
                        else
                            row('FM Access', 'Carrier Squelch');
                        break;
                    case 'DMR':
                        if ($rep['dmr_color_code'] !== null) {
                            $dmr_sum = 'Color Code '.$rep['dmr_color_code'];
                            if ($rep['dmr_network']) $dmr_sum .= ' / '.$rep['dmr_network'];
                            if ($rep['dmr_ts1_talk_groups']) $dmr_sum .= ' / TS1: '.$rep['dmr_ts1_talk_groups'];
                            if ($rep['dmr_ts2_talk_groups']) $dmr_sum .= ' / TS2: '.$rep['dmr_ts2_talk_groups'];
                            row('DMR Access', $dmr_sum);
                        }
                        else
                            row('DMR Access', 'See repeater details');
                        break;
                    case 'D-STAR':
                        row('D-STAR Access', 'Module '.($rep['dstar_module'] ?: '?'));
                        break;
                    case 'FUSION':
                        row('Fusion/C4FM', $rep['fusion_room'] ? 'Wires-X Room: '.$rep['fusion_room'] : 'C4FM / Wires-X');
                        break;
                    case 'P-25':
                        row('P-25 Access', $rep['p25_nac'] ? 'NAC: '.$rep['p25_nac'] : 'P-25');
                        break;
                    case 'NXDN':
                        row('NXDN Access', 'NXDN');
                        break;
                }
            }
        } else {
            // Single mode
            // Show DMR info if type=DMR regardless of tone_type
            if (($rep['type'] ?? '') === 'DMR' || ($rep['tone_type'] ?? '') === 'DMR') {
                $dmr_cc = 'Color Code '.($rep['dmr_color_code'] ?? '?');
                if ($rep['dmr_network']) $dmr_cc .= ' — '.$rep['dmr_network'];
                row('DMR Color Code', $dmr_cc);
                if ($rep['dmr_ts1_talk_groups'])
                    row('Time Slot 1 Talk Groups', $rep['dmr_ts1_talk_groups']);
                if ($rep['dmr_ts2_talk_groups'])
                    row('Time Slot 2 Talk Groups', $rep['dmr_ts2_talk_groups']);
            }
            switch ($rep['tone_type'] ?? 'CARRIER') {
                case 'CTCSS':
                    row('Access', $rep['pl_tone'] ? number_format((float)$rep['pl_tone'],1).' Hz CTCSS/PL' : 'CTCSS');
                    break;
                case 'DCS':
                    row('Access', $rep['dcs_code'] ? 'DCS D'.$rep['dcs_code'] : 'DCS');
                    break;
                case 'TSQ':
                    row('Access', $rep['tsq_tone'] ? number_format((float)$rep['tsq_tone'],1).' Hz TSQ' : 'TSQ');
                    break;
                case 'DMR':
                    // Already handled above
                    break;
                    break;
                case 'DSTAR': case 'D-STAR':
                    row('D-STAR', 'Module '.($rep['dstar_module'] ?: '?'));
                    break;
                case 'FUSION':
                    row('Fusion/C4FM', $rep['fusion_room'] ? 'Wires-X Room: '.$rep['fusion_room'] : 'C4FM / Wires-X');
                    break;
                case 'P25': case 'P-25':
                    row('P-25', $rep['p25_nac'] ? 'NAC: '.$rep['p25_nac'] : 'P-25');
                    break;
                default:
                    if (($rep['type'] ?? '') !== 'DMR') row('Access', 'Carrier Squelch (No Tone)');
            }
        }
        ?>
        <tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">Type</th>
        <td style="padding:8px 12px"><?= h($rep['type']) ?><?= $rep['mixed_mode'] ? ' <span class="badge badge-construction" style="margin-left:6px">Mixed Mode</span>' : '' ?></td></tr>
        <?php if ($rep['mixed_mode'] && $rep['mixed_mode_types']): ?>
        <tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">Supported Modes</th>
        <td style="padding:8px 12px"><?php
          foreach(explode(',', $rep['mixed_mode_types']) as $m) {
            echo '<span class="badge badge-type-repeater" style="margin-right:4px">'.h(trim($m)).'</span>';
          }
        ?></td></tr>
        <?php endif; ?>
        <?php row('Internet Link', $rep['internet_link'] ?: '-'); ?>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><i class="fa fa-id-card"></i> Ownership & Location</div>
    <div class="card-body" style="padding:0">
      <table style="width:100%;border-collapse:collapse">
        <?php
        $fcc_cs = null;
        $fcc_q2 = $db->prepare("SELECT * FROM fcc_licenses WHERE callsign = ?");
        $fcc_q2->execute([strtoupper(trim($rep['callsign']))]);
        $fcc_cs = $fcc_q2->fetch();
        $fcc_cs_badge = '';
        if ($fcc_cs) {
            $cs_days = $fcc_cs['expiry_date'] ? (int)((strtotime($fcc_cs['expiry_date']) - time()) / 86400) : null;
            $cs_expired = $cs_days !== null && $cs_days < 0;
            $cs_expiring = $cs_days !== null && $cs_days <= 365 && !$cs_expired;
            if ($cs_expired) {
                $fcc_cs_badge = ' <span style="background:#fef2f2;color:#dc2626;font-size:.72rem;font-weight:bold;padding:2px 6px;border-radius:4px;border:1px solid #fca5a5"><i class=\'fa fa-triangle-exclamation\'></i> LICENSE EXPIRED ' . abs($cs_days) . ' days ago</span>';
            } elseif ($cs_expiring) {
                $fcc_cs_badge = ' <span style="background:#fffbeb;color:#92400e;font-size:.72rem;font-weight:bold;padding:2px 6px;border-radius:4px;border:1px solid #fcd34d"><i class=\'fa fa-clock\'></i> Expires in ' . $cs_days . ' days</span>';
            } else {
                $fcc_cs_badge = ' <span style="background:#f0fdf4;color:#15803d;font-size:.72rem;padding:2px 6px;border-radius:4px;border:1px solid #86efac"><i class=\'fa fa-check\'></i> Valid thru ' . $fcc_cs['expiry_date'] . '</span>';
            }
        } else {
            $fcc_cs_badge = ' <span style="background:#f1f5f9;color:#64748b;font-size:.72rem;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1"><i class=\'fa fa-question\'></i> Not in FCC DB</span>';
        }
        ?>
        <tr>
          <th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89">Callsign</th>
          <td style="padding:8px 12px"><strong><?= h($rep['callsign']) ?></strong><?= $fcc_cs_badge ?></td>
        </tr>
        <?php
        $fcc = null;
        if ($rep['trustee']) {
            $fcc_q = $db->prepare("SELECT * FROM fcc_licenses WHERE callsign = ?");
            $fcc_q->execute([strtoupper(trim($rep['trustee']))]);
            $fcc = $fcc_q->fetch();
        }
        $fcc_badge = '';
        if ($fcc) {
            $days = $fcc['expiry_date'] ? (int)((strtotime($fcc['expiry_date']) - time()) / 86400) : null;
            $expired = $days !== null && $days < 0;
            $expiring = $days !== null && $days <= 365 && !$expired;
            if ($expired) {
                $fcc_badge = ' <span style="background:#fef2f2;color:#dc2626;font-size:.72rem;font-weight:bold;padding:2px 6px;border-radius:4px;border:1px solid #fca5a5"><i class=\'fa fa-triangle-exclamation\'></i> LICENSE EXPIRED ' . abs($days) . ' days ago</span>';
            } elseif ($expiring) {
                $fcc_badge = ' <span style="background:#fffbeb;color:#92400e;font-size:.72rem;font-weight:bold;padding:2px 6px;border-radius:4px;border:1px solid #fcd34d"><i class=\'fa fa-clock\'></i> Expires in ' . $days . ' days</span>';
            } else {
                $fcc_badge = ' <span style="background:#f0fdf4;color:#15803d;font-size:.72rem;padding:2px 6px;border-radius:4px;border:1px solid #86efac"><i class=\'fa fa-check\'></i> Valid thru ' . $fcc['expiry_date'] . '</span>';
            }
        } elseif ($rep['trustee']) {
            $fcc_badge = ' <span style="background:#f1f5f9;color:#64748b;font-size:.72rem;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1"><i class=\'fa fa-question\'></i> Not in FCC DB</span>';
        }
        ?>
        <tr>
          <th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89">Trustee</th>
          <td style="padding:8px 12px"><?= h($rep['trustee']) ?><?= $fcc_badge ?>
            <?php if ($fcc && $fcc['licensee_name']): ?>
            <span style="color:#888;font-size:.8rem;margin-left:6px">(<?= h(trim($fcc['licensee_name'])) ?>)</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php row('Sponsor',   $rep['sponsor']); ?>
        <?php row('District',  $rep['district']); ?>
        <?php row('County',    $rep['county']); ?>
        <?php row('City',      $rep['city']); ?>
        <?php if ($rep['url']): ?>
        <tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89">Website</th>
          <td style="padding:8px 12px"><?php
            $url = $rep['url'];
            if ($url && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
          ?><a href="<?= h($url) ?>" target="_blank"><?= h($rep['url']) ?></a></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><i class="fa fa-sliders"></i> Status & Features</div>
    <div class="card-body" style="padding:0">
      <table style="width:100%;border-collapse:collapse">
        <?php row('Status', $rep['status']); ?>
        <?php bool_row('Open System',      $rep['open_system']); ?>
        <?php bool_row('Auto-Patch',       $rep['autopatch']); ?>
        <?php bool_row('Closed Auto-Patch',$rep['closed_autopatch']); ?>
        <?php bool_row('SKYWARN',          $rep['skywarn']); ?>
        <?php bool_row('Linked',           $rep['linked']); ?>
        <?php bool_row('Backup Power',     $rep['backup_power']); ?>
        <?php if ($rep['allstar']): ?><tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">AllStar Node</th><td style="padding:8px 12px"><?= h($rep['allstar_node'] ?: 'Yes') ?></td></tr><?php endif; ?>
        <?php if ($rep['echolink']): ?><tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">EchoLink Node</th><td style="padding:8px 12px"><?= h($rep['echolink_node'] ?: 'Yes') ?></td></tr><?php endif; ?>
        <?php if (($rep['type'] ?? '') === 'DMR'): ?>
        <tr><th style="width:200px;padding:8px 12px;background:#f4f6f8;font-weight:600;font-size:.82rem;text-transform:uppercase;color:#6c7a89;white-space:nowrap">DMR</th>
        <td style="padding:8px 12px"><span style="color:var(--success);font-weight:bold">Yes</span>
        <?php if ($rep['dmr_network']): ?> — <?= h($rep['dmr_network']) ?><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><i class="fa fa-calendar"></i> Coordination Dates</div>
    <div class="card-body" style="padding:0">
      <table style="width:100%;border-collapse:collapse">
        <?php row('Date Coordinated', $rep['date_coordinated'] ?? '-'); ?>
        <?php row('Last Updated',     $rep['last_update'] ?? '-'); ?>
        <?php if ($rep['latitude']): row('Latitude',  $rep['latitude']); endif; ?>
        <?php if ($rep['longitude']): row('Longitude', $rep['longitude']); endif; ?>
      </table>
    </div>
  </div>

</div>

<?php
// Calculate coverage estimate - needs HAAT, ERP optional
$coverage_mi = null;
if ($rep['haat'] || $rep['antenna_height_agl']) {
    $haat_m  = (float)($rep['haat'] ?: $rep['antenna_height_agl']) * 0.3048;
    // Proper radio horizon with k=4/3 atmospheric refraction factor
    // Formula: d(km) = 4.12 * sqrt(h_meters)  (ITU-R P.1546 simplified)
    $los_km  = 4.12 * sqrt($haat_m);
    $los_mi  = $los_km * 0.621371;
    // If ERP available use it, otherwise calculate from TX power
    if ($rep['erp_watts']) {
        $erp = (float)$rep['erp_watts'];
    } elseif ($rep['tx_power_watts']) {
        $gain = (float)($rep['antenna_gain_dbd'] ?? 0);
        $loss = (float)($rep['feedline_loss_db'] ?? 0);
        $erp  = (float)$rep['tx_power_watts'] * pow(10, ($gain - $loss) / 10);
    } else {
        $erp = 25; // Default 25W if no power data
    }
    // Scale coverage by ERP - reference 50W at 0 dBd
    // Add mobile receiver horizon (7ft = 2.1m)
    $mobile_km   = (4.12 * sqrt($haat_m)) + (4.12 * sqrt(2.1));
    $handheld_km = (4.12 * sqrt($haat_m)) + (4.12 * sqrt(1.5));
    // ERP scaling + 65% real-world derating for terrain/vegetation/losses
    $erp_factor  = pow($erp / 50, 0.15);
    $coverage_mi = round($mobile_km   * 0.65 * $erp_factor * 0.621371, 1);
    $handheld_mi = round($handheld_km * 0.60 * $erp_factor * 0.621371, 1);
}
?>

<?php if ($rep['haat'] || $rep['erp_watts'] || $rep['tx_power_watts'] || $rep['antenna_height_agl']): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><i class="fa fa-broadcast-tower"></i> RF Parameters &amp; HAAT</div>
  <div class="card-body" style="padding:0">
    <table class="detail-table">
      <?php if ($rep['antenna_height_agl']): ?><tr><th>Antenna Height AGL</th><td><?= number_format((float)$rep['antenna_height_agl'],1) ?> ft</td></tr><?php endif; ?>
      <?php if ($rep['tower_height']): ?><tr><th>Tower Height</th><td><?= number_format((float)$rep['tower_height'],1) ?> ft</td></tr><?php endif; ?>
      <?php if ($rep['haat']): ?><tr><th>HAAT</th><td><strong><?= number_format((float)$rep['haat'],1) ?> ft</strong></td></tr><?php endif; ?>
      <?php if ($rep['tx_power_watts']): ?><tr><th>TX Power</th><td><?= number_format((float)$rep['tx_power_watts'],1) ?> W</td></tr><?php endif; ?>
      <?php if ($rep['feedline_loss_db']): ?><tr><th>Feedline Loss</th><td><?= number_format((float)$rep['feedline_loss_db'],1) ?> dB</td></tr><?php endif; ?>
      <?php if ($rep['antenna_gain_dbd']): ?><tr><th>Antenna Gain</th><td><?= number_format((float)$rep['antenna_gain_dbd'],1) ?> dBd</td></tr><?php endif; ?>
      <?php if ($rep['erp_watts']): ?><tr><th>ERP</th><td><strong><?= number_format((float)$rep['erp_watts'],1) ?> W</strong> (<?= number_format(10*log10((float)$rep['erp_watts']),1) ?> dBW)</td></tr><?php endif; ?>
      <?php if ($coverage_mi): ?><tr><th>Est. Coverage Radius</th><td><strong style="color:var(--primary)"><?= $coverage_mi ?> miles</strong> <span class="text-muted">(HAAT line-of-sight estimate)</span></td></tr><?php endif; ?>
    </table>
  </div>
  <?php if (is_logged_in() && in_array($user['role'],['admin','coordinator'])): ?>
  <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:8px">
    <a href="<?= BASE_PATH ?>/admin/splat_export.php?id=<?= $id ?>" class="btn btn-secondary btn-sm"><i class="fa fa-file-export"></i> Export SPLAT! Files</a>
    <a href="<?= BASE_PATH ?>/kml_export.php" class="btn btn-secondary btn-sm"><i class="fa fa-map"></i> Export All KML</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($rep['notes']): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><i class="fa fa-sticky-note"></i> Notes</div>
  <div class="card-body"><?= nl2br(h($rep['notes'])) ?></div>
</div>
<?php endif; ?>

<?php if (is_logged_in() && in_array($user['role'],['admin','coordinator']) && ($rep['contact_name'] || $rep['contact_email'] || $rep['contact_phone'] || $rep['contact_address'])): ?>
<div class="card" style="margin-bottom:20px;border-left:4px solid #dc2626">
  <div class="card-header" style="background:#fef2f2;color:#991b1b"><i class="fa fa-lock"></i> Contact Information <small style="font-weight:400">(Coordinators &amp; Admins only)</small></div>
  <div class="card-body" style="padding:0">
    <table class="detail-table">
      <?php if ($rep['contact_name']): ?><tr><th>Contact Name</th><td><?= h($rep['contact_name']) ?></td></tr><?php endif; ?>
      <?php if ($rep['contact_phone']): ?><tr><th>Phone</th><td><a href="tel:<?= h($rep['contact_phone']) ?>"><?= h($rep['contact_phone']) ?></a></td></tr><?php endif; ?>
      <?php if ($rep['contact_email']): ?><tr><th>Email</th><td><a href="mailto:<?= h($rep['contact_email']) ?>"><?= h($rep['contact_email']) ?></a></td></tr><?php endif; ?>
      <?php if ($rep['contact_address'] || $rep['contact_city']): ?>
      <tr><th>Mailing Address</th><td>
        <?= $rep['contact_address'] ? h($rep['contact_address']).'<br>' : '' ?>
        <?= $rep['contact_city'] ? h($rep['contact_city']) : '' ?>
        <?= $rep['contact_state'] ? ', '.h($rep['contact_state']) : '' ?>
        <?= $rep['contact_zip'] ? ' '.h($rep['contact_zip']) : '' ?>
      </td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($rep['internal_notes']) && is_logged_in() && in_array($user['role'],['admin','coordinator'])): ?>
<div class="card" style="margin-bottom:20px;border-left:4px solid #d97706">
  <div class="card-header" style="background:#fffbeb;color:#92400e"><i class="fa fa-lock"></i> Internal Notes <small style="font-weight:400">(Coordinators &amp; Admins only)</small></div>
  <div class="card-body" style="background:#fffbeb"><?= nl2br(h($rep['internal_notes'])) ?></div>
</div>
<?php endif; ?>

<!-- Nearby / potentially conflicting -->
<?php if ($near_rows && is_logged_in()): ?>
<div class="card">
  <div class="card-header"><i class="fa fa-triangle-exclamation"></i> Nearby Frequencies (±50 kHz)</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Output MHz</th><th>Callsign</th><th>Type</th><th>Status</th>
        <th>County</th><th>City</th><th>PL</th><th>Freq Δ kHz</th>
        <th>Distance</th><th>Conflict?</th>
      </tr></thead>
      <tbody>
      <?php foreach ($near_rows as $n): ?>
      <tr style="<?= $n['conflict'] ? 'background:#fef2f2;' : '' ?>">
        <td><span class="freq"><?= number_format((float)$n['output_freq'],4) ?></span></td>
        <td><a href="<?= BASE_PATH ?>/repeater.php?id=<?= $n['id'] ?>"><?= h($n['callsign']) ?></a></td>
        <td><?= h($n['type']) ?></td>
        <td><?= h($n['status']) ?></td>
        <td><?= h($n['county']) ?></td>
        <td><?= h($n['city']) ?></td>
        <td><?= $n['pl_tone'] ? number_format((float)$n['pl_tone'],1) : '-' ?></td>
        <td class="<?= (float)$n['freq_diff_mhz']==0?'conflict-co':'conflict-adj' ?>">
          <?= number_format((float)$n['freq_diff_mhz']*1000,1) ?>
        </td>
        <td><?= $n['distance_mi'] !== null ? $n['distance_mi'].' mi'.($n['location_source']==='CITY'?' <small style="color:#aaa">(approx)</small>':'') : '-' ?></td>
        <td><?php if ($n['conflict']): ?>
          <span style="color:#dc2626;font-weight:bold;font-size:.8rem">
            <i class="fa fa-triangle-exclamation"></i> <?= h($n['conflict_type']) ?>
          </span>
        <?php elseif ($n['distance_mi'] !== null): ?>
          <span style="color:#16a34a;font-size:.8rem"><i class="fa fa-check"></i> Clear</span>
        <?php else: ?>
          <span style="color:#aaa;font-size:.8rem">No GPS</span>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
@media(max-width:768px){.detail-grid{grid-template-columns:1fr!important}}
</style>

<?php if ($rep['latitude'] && $rep['longitude']): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <i class="fa fa-map"></i> Repeater Location
    <?php if ($coverage_mi): ?>
    <span style="font-size:.78rem;font-weight:400;color:var(--muted);margin-left:8px">
      &mdash; Estimated coverage: <strong style="color:var(--primary)"><?= $coverage_mi ?> miles</strong>
      <span style="color:var(--muted)">(HAAT line-of-sight estimate)</span>
    </span>
    <?php endif; ?>
  </div>
  <div id="rep-map" style="height:550px;width:100%;border-radius:0 0 var(--radius) var(--radius)"></div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function() {
  var lat          = <?= (float)$rep['latitude'] ?>;
  var lon          = <?= (float)$rep['longitude'] ?>;
  var callsign     = <?= json_encode($rep['callsign']) ?>;
  var freq         = <?= json_encode(number_format((float)$rep['output_freq'],4)) ?>;
  var status       = <?= json_encode($rep['status']) ?>;
  var coverage_mi  = <?= $coverage_mi ? (float)$coverage_mi : 0 ?>;
  var haat         = <?= $rep['haat'] ? (float)$rep['haat'] : 0 ?>;
  var erp          = <?= $rep['erp_watts'] ? (float)$rep['erp_watts'] : 0 ?>;
  var approx       = <?= $rep['location_source']==='CITY' ? 'true' : 'false' ?>;

  var zoom = coverage_mi > 50 ? 8 : coverage_mi > 25 ? 9 : coverage_mi > 0 ? 10 : 11;

  var map = L.map('rep-map', {center:[lat,lon], zoom:zoom, scrollWheelZoom:true});

  // Base map layers
  var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
  });

  var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles &copy; Esri', maxZoom: 18
  });

  var topo = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenTopoMap contributors', maxZoom: 17
  });

  var cartodb = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; CartoDB', maxZoom: 19
  });

  var cartodbDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; CartoDB', maxZoom: 19
  });

  // Satellite labels overlay
  var esriLabels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 18, opacity: 0.85
  });

  // Default: satellite + labels
  satellite.addTo(map);
  esriLabels.addTo(map);

  // Layer control
  var baseLayers = {
    '<i class="fa fa-satellite"></i> Satellite': satellite,
    '<i class="fa fa-map"></i> Street Map': osm,
    '<i class="fa fa-mountain"></i> Topo': topo,
    '<i class="fa fa-circle"></i> Light': cartodb,
    '<i class="fa fa-moon"></i> Dark': cartodbDark,
  };
  var overlays = {
    '<i class="fa fa-font"></i> Place Labels': esriLabels,
  };
  L.control.layers(baseLayers, overlays, {position:'topright', collapsed:false}).addTo(map);

  // Remove labels overlay when switching away from satellite
  map.on('baselayerchange', function(e) {
    if (e.name.indexOf('Satellite') === -1) {
      map.removeLayer(esriLabels);
    }
  });

  var color = status === 'OPERATIONAL' ? '#16a34a' : (status === 'DOWN TEMPORARILY' ? '#d97706' : '#6b7280');

  var towerHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 28 36">'
    + '<line x1="14" y1="2" x2="2" y2="30" stroke="' + color + '" stroke-width="2.5" stroke-linecap="round"/>'
    + '<line x1="14" y1="2" x2="26" y2="30" stroke="' + color + '" stroke-width="2.5" stroke-linecap="round"/>'
    + '<line x1="6" y1="12" x2="22" y2="12" stroke="' + color + '" stroke-width="2"/>'
    + '<line x1="4" y1="21" x2="24" y2="21" stroke="' + color + '" stroke-width="2"/>'
    + '<line x1="2" y1="30" x2="26" y2="30" stroke="' + color + '" stroke-width="2.5" stroke-linecap="round"/>'
    + '<circle cx="14" cy="2" r="3.5" fill="' + color + '"/>'
    + (approx ? '<circle cx="14" cy="2" r="6" fill="none" stroke="#d97706" stroke-width="1.5" stroke-dasharray="2,2"/>' : '')
    + '</svg>';

  var icon = L.divIcon({html:towerHtml, className:'', iconSize:[28,36], iconAnchor:[14,36], popupAnchor:[0,-38]});

  var popupHtml = '<div style="font-family:Arial;font-size:13px;min-width:180px">'
    + '<div style="font-weight:700;font-size:16px;margin-bottom:4px">' + callsign + '</div>'
    + '<div style="color:#555;margin-bottom:6px">' + freq + ' MHz</div>'
    + '<span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;background:' + color + ';color:#fff">' + status + '</span>';
  if (haat)        popupHtml += '<div style="margin-top:8px;font-size:12px;color:#444">HAAT: <strong>' + haat + ' ft</strong></div>';
  if (erp)         popupHtml += '<div style="font-size:12px;color:#444">ERP: <strong>' + erp + ' W</strong></div>';
  if (coverage_mi) popupHtml += '<div style="font-size:12px;color:#1a5276">Est. coverage: <strong>' + coverage_mi + ' miles</strong></div>';
  if (approx)      popupHtml += '<div style="font-size:11px;color:#d97706;margin-top:4px">* Approximate location (city center)</div>';
  popupHtml += '</div>';

  L.marker([lat,lon], {icon:icon}).addTo(map).bindPopup(popupHtml).openPopup();

  if (coverage_mi) {
    var radius_m = coverage_mi * 1609.34;
    // Outer coverage circle - bright yellow/white visible on satellite
    L.circle([lat,lon], {
      radius: radius_m,
      color: '#ffffff', fillColor: '#fbbf24',
      fillOpacity: 0.12, weight: 2.5,
      dashArray: '10,6', opacity: 0.9
    }).addTo(map);
    // Inner 50% circle - orange tint
    L.circle([lat,lon], {
      radius: radius_m * 0.5,
      color: '#fbbf24', fillColor: '#f59e0b',
      fillOpacity: 0.1, weight: 1.5,
      dashArray: '5,5', opacity: 0.7
    }).addTo(map);
  }

  L.control.scale({imperial:true, metric:false, position:'bottomleft'}).addTo(map);

  // Load confirmation markers
  fetch('/repeater_coord/api/index.php?path=confirmations/' + <?= $rep['id'] ?>)
    .then(r => r.json())
    .then(data => {
      if (!data.data || !data.data.confirmations) return;
      data.data.confirmations.forEach(function(c) {
        if (!c.latitude || !c.longitude) return;
        var color = c.radio_type === 'HT' ? '#22c55e' : c.radio_type === 'Mobile' ? '#3b82f6' : '#f59e0b';
        var popup = '<b>' + c.callsign + '</b> (' + c.radio_type + ')' +
          (c.signal_report ? ' &mdash; ' + c.signal_report : '') +
          '<br><small style="color:#888">' + c.heard_at.substring(0,10) + '</small>';
        var html = c.radio_type === 'HT'
          ? '<div style="width:12px;height:12px;border-radius:50%;background:' + color + ';border:2px solid #fff"></div>'
          : c.radio_type === 'Mobile'
          ? '<div style="width:12px;height:12px;background:' + color + ';border:2px solid #fff"></div>'
          : '<div style="width:12px;height:12px;background:' + color + ';border:2px solid #fff;transform:rotate(45deg)"></div>';
        L.marker([c.latitude, c.longitude], {icon: L.divIcon({html:html,className:'',iconSize:[12,12],iconAnchor:[6,6]})}).addTo(map).bindPopup(popup);
      });
      // Load cant hear markers
      fetch('/repeater_coord/api/index.php?path=cant_hear_count/' + <?= $rep['id'] ?>)
        .then(r => r.json())
        .then(chData => {
          if (!chData.data || !chData.data.reports) return;
          chData.data.reports.forEach(function(r) {
            if (!r.latitude || !r.longitude) return;
            var popup = '<b style="color:#ef4444">Cannot Hear</b><br>' + r.callsign + ' (' + r.radio_type + ')<br><small>' + r.reported_at.substring(0,10) + '</small>';
            var html = '<div style="width:16px;height:16px;position:relative"><div style="position:absolute;width:14px;height:2px;background:#ef4444;top:7px;left:1px;transform:rotate(45deg)"></div><div style="position:absolute;width:14px;height:2px;background:#ef4444;top:7px;left:1px;transform:rotate(-45deg)"></div></div>';
            L.marker([r.latitude, r.longitude], {icon: L.divIcon({html:html,className:'',iconSize:[16,16],iconAnchor:[8,8]})}).addTo(map).bindPopup(popup);
          });
        });
      var hasCoords = data.data.confirmations.some(function(c){ return c.latitude && c.longitude; });
      if (hasCoords) {
        var legend = L.control({position: 'bottomright'});
        legend.onAdd = function() {
          var d = L.DomUtil.create('div');
          d.style.cssText = 'background:rgba(0,0,0,0.7);padding:6px 10px;border-radius:6px;font-size:11px;color:#fff';
          d.innerHTML = '<b style="display:block;margin-bottom:4px">Heard From</b>' +
            '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;margin-right:4px"></span>HT<br>' +
            '<span style="display:inline-block;width:10px;height:10px;background:#3b82f6;margin:2px 4px 0 0"></span>Mobile<br>' +
            '<span style="display:inline-block;width:10px;height:10px;background:#f59e0b;transform:rotate(45deg);margin:2px 4px 0 0"></span>Base';
          return d;
        };
        legend.addTo(map);
      }
    });

})();
</script>
<?php endif; ?>


<?php include __DIR__ . '/includes/footer.php'; ?>
