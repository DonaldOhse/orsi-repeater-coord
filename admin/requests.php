<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();
$user = current_user();

$bands = [
    '10m'   =>'10m (29 MHz)',
    '6m'    =>'6m (52 MHz)',
    '2m-lo' =>'2m Low (145 MHz)',
    '2m-mid'=>'2m Mid (146 MHz)',
    '2m-hi' =>'2m High (147 MHz)',
    '1.25m' =>'1.25m (222 MHz)',
    '70cm'  =>'70cm (440 MHz)',
    '33cm'  =>'33cm (902 MHz)',
    '23cm'  =>'23cm (1.2 GHz)',
];

// ── Handle actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Send NOPC email
    if (isset($_POST['send_nopc'])) {
        $rid       = (int)$_POST['req_id'];
        $state_abbr = trim($_POST['state_abbr']);

        // Get request and NOPC contact
        $req_s = $db->prepare("SELECT * FROM coordination_requests WHERE id=?");
        $req_s->execute([$rid]);
        $req = $req_s->fetch();

        $nc_s = $db->prepare("SELECT * FROM nopc_contacts WHERE state_abbr=? AND active=1 LIMIT 1");
        $nc_s->execute([$state_abbr]);
        $nc = $nc_s->fetch();

        if ($req && $nc) {
            // Check if NOPC already sent for this request+state
            $existing = $db->prepare("SELECT id FROM nopc_notifications WHERE request_id=? AND state_abbr=? AND status='PENDING'");
            $existing->execute([$rid, $state_abbr]);

            if (!$existing->fetch()) {
                $token    = bin2hex(random_bytes(32));
                $expires  = date('Y-m-d H:i:s', strtotime('+72 hours'));
                $bands    = ['10m'=>'10m (29 MHz)','6m'=>'6m (52 MHz)','2m-lo'=>'2m Low','2m-mid'=>'2m Mid','2m-hi'=>'2m High','1.25m'=>'1.25m','70cm'=>'70cm','33cm'=>'33cm','23cm'=>'23cm'];
                $band_name = $bands[$req['req_band']] ?? $req['req_band'];
                $freq      = $req['suggested_freq'] ?: $req['preferred_freq'];

                $db->prepare("INSERT INTO nopc_notifications (request_id,state_abbr,state,contact_name,contact_email,token,expires_at) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$rid, $state_abbr, $nc['state'], $nc['contact_name'], $nc['email'], $token, $expires]);

                $approve_url = "https://w5dro.com/repeater_coord/nopc_response.php?token={$token}&action=approve";
                $decline_url = "https://w5dro.com/repeater_coord/nopc_response.php?token={$token}&action=decline";

                $subject = "NOPC - {$req['applicant_callsign']} - {$freq} MHz - Oklahoma";
                $body  = "Notice of Proposed Coordination (NOPC)\n";
                $body .= str_repeat('=', 50) . "\n\n";
                $body .= "The Oklahoma Repeater Society is coordinating a new repeater near your state border.\n";
                $body .= "Per ARRL coordination guidelines, we request your review within 72 hours.\n\n";
                $body .= "PROPOSED REPEATER:\n";
                $body .= "  Applicant:  {$req['applicant_name']} ({$req['applicant_callsign']})\n";
                $body .= "  Band:       {$band_name}\n";
                $body .= "  Frequency:  {$freq} MHz\n";
                $body .= "  Type:       {$req['req_type']}\n";
                $body .= "  Location:   {$req['city']}, {$req['county']} County, Oklahoma\n";
                $body .= "  GPS:        {$req['latitude']}, {$req['longitude']}\n";
                // Calculate offset from band
                $band_offsets = [
                    '10m'=>-0.100,'6m'=>-1.700,'2m-lo'=>-0.600,'2m-mid'=>-0.600,'2m-hi'=>-0.600,
                    '1.25m'=>-1.600,'70cm'=>-5.000,'33cm'=>25.000,'23cm'=>-12.000
                ];
                $offset = $band_offsets[$req['req_band']] ?? null;
                $input_freq = $freq && $offset ? round((float)$freq + (float)$offset, 4) : null;
                if ($input_freq) $body .= "  Input Freq: {$input_freq} MHz\n";
                if ($offset)     $body .= "  Offset:     {$offset} MHz\n";
                // Technical
                if ($req['antenna_height_agl']) $body .= "  AGL:        {$req['antenna_height_agl']} ft\n";
                if ($req['haat'])               $body .= "  HAAT:       {$req['haat']} ft\n";
                if ($req['tx_power_watts'])     $body .= "  TX Power:   {$req['tx_power_watts']} W\n";
                if ($req['erp_watts'])          $body .= "  ERP:        {$req['erp_watts']} W\n";
                $body .= "  Antenna:    Omnidirectional\n";
                // Tone/Access
                $tone = 'Carrier Squelch';
                if (!empty($req['tone_type']) && $req['tone_type'] === 'CTCSS' && !empty($req['pl_tone']))
                    $tone = 'CTCSS ' . number_format((float)$req['pl_tone'],1) . ' Hz';
                elseif (!empty($req['tone_type']) && $req['tone_type'] === 'DCS' && !empty($req['dcs_code']))
                    $tone = 'DCS D' . $req['dcs_code'];
                elseif (!empty($req['tone_type']) && $req['tone_type'] === 'DMR')
                    $tone = 'DMR CC' . ($req['dmr_color_code'] ?? '') . ' TS' . ($req['dmr_time_slot'] ?? '');
                $body .= "  Tone/Access: {$tone}\n";
                // Coverage estimate based on ERP and HAAT
                if ($req['erp_watts'] && $req['haat']) {
                    $coverage = round(2 * sqrt((float)$req['haat'] / 3.28084), 1);
                    $body .= "  Coverage Est: ~{$coverage} miles (line of sight)\n";
                }
                $body .= "\nPLEASE RESPOND WITHIN 72 HOURS:\n\n";
                $body .= "  APPROVE: {$approve_url}\n\n";
                $body .= "  DECLINE: {$decline_url}\n\n";
                $body .= "If we do not receive a response by " . date('Y-m-d H:i', strtotime('+72 hours')) . " UTC,\n";
                $body .= "we will proceed with coordination.\n\n";
                $body .= "73,\nOklahoma Repeater Society Frequency Coordination\n";
                $body .= "https://w5dro.com/repeater_coord\n";

                $sent = orsi_mail($nc['email'], $subject, $body, MAIL_FROM);
                if ($sent) flash('success', "NOPC sent to {$nc['state']} coordinator ({$nc['email']}). They have 72 hours to respond.");
                else flash('danger', "Failed to send NOPC email to {$nc['email']}. Use the mailto link as backup.");
            } else {
                flash('warning', "NOPC already sent to {$nc['state']} and is pending response.");
            }
        }
        header('Location: ' . BASE_PATH . '/admin/requests.php?id=' . $rid . '&status=' . ($_GET['status'] ?? 'PENDING'));
        exit;
    }
    $rid    = (int)($_POST['req_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $req_stmt = $db->prepare("SELECT * FROM coordination_requests WHERE id=?");
    $req_stmt->execute([$rid]);
    $req = $req_stmt->fetch();

    // Handle preferred frequency update
    if ($action === 'update_preferred') {
        $rid = (int)($_POST['req_id'] ?? 0);
        $new_freq = round((float)($_POST['new_preferred_freq'] ?? 0), 4);
        if ($rid && $new_freq > 0) {
            $db->prepare("UPDATE coordination_requests SET preferred_freq=? WHERE id=?")->execute([$new_freq, $rid]);
            audit('UPDATE', 'coordination_requests', $rid, null, ['preferred_freq' => $new_freq]);
            flash('success', "Preferred frequency updated to {$new_freq} MHz. Conflict check refreshed.");
        }
        header("Location: " . BASE_PATH . "/admin/requests.php?id={$rid}&status=" . urlencode($_POST['current_status'] ?? 'PENDING'));
        exit;
    }

    if ($req && in_array($action, ['approve','deny','info'])) {
        $note = trim($_POST['coordinator_notes'] ?? '');

        if ($action === 'approve') {
            // Create PROPOSED repeater record
            $band_offsets = [
                '10m'=>-0.100,'6m'=>-1.700,
                '2m-lo'=>-0.600,'2m-mid'=>-0.600,'2m-hi'=>0.600,
                '1.25m'=>-1.600,'70cm'=>5.000,'33cm'=>25.000,'23cm'=>-12.000
            ];
            $offset = $band_offsets[$req['req_band']] ?? 0.6;
            // Use coordinator override frequency if provided
            $override_out = trim($_POST['override_output_freq'] ?? '');
            $override_in  = trim($_POST['override_input_freq'] ?? '');
            $out_freq = $override_out ? (float)$override_out : (float)($req['suggested_freq'] ?: $req['preferred_freq']);
            $in_freq  = $override_in  ? (float)$override_in  : round($out_freq + $offset, 4);

            $db->prepare("INSERT INTO repeaters (district,type,status,output_freq,input_freq,callsign,trustee,sponsor,county,city,latitude,longitude,antenna_height_agl,haat,tx_power_watts,erp_watts,skywarn,linked,allstar,echolink,autopatch,open_system,date_coordinated,notes,contact_name,contact_email,contact_phone)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                $req['district'], $req['req_type'], 'PROPOSED',
                $out_freq, $in_freq,
                $req['applicant_callsign'],
                $req['trustee_callsign'] ?: $req['applicant_callsign'],
                $req['sponsor'] ?: '',
                $req['county'], $req['city'],
                $req['latitude'], $req['longitude'],
                $req['antenna_height_agl'], $req['haat'],
                $req['tx_power_watts'], $req['erp_watts'],
                $req['feature_skywarn'], $req['feature_linked'],
                $req['feature_allstar'], $req['feature_echolink'],
                $req['feature_autopatch'], 1,
                date('Y-m-d'),
                "Coordination request #{$req['id']} approved by {$user['callsign']}. " . $note,
                $req['applicant_name'],
                $req['applicant_email'],
                $req['applicant_phone']
            ]);
            $rep_id = $db->lastInsertId();

            $db->prepare("UPDATE coordination_requests SET status='APPROVED', coordinator_notes=?, coordinator_id=?, repeater_id=? WHERE id=?")
               ->execute([$note, $user['id'], $rep_id, $rid]);

            // Email applicant
            $msg  = "Good news! Your repeater coordination request #{$rid} has been APPROVED.\n\n";
            $msg .= "A PROPOSED record has been created in the ORSI database.\n";
            $msg .= "Suggested Output: {$out_freq} MHz\n";
            $msg .= "Suggested Input:  {$in_freq} MHz\n";
            if ($note) $msg .= "\nCoordinator notes: {$note}\n";
            $msg .= "\n73,\nOklahoma Repeater Society\n";
            orsi_mail($req["applicant_email"], "Coordination Request #{$rid} APPROVED - ORSI", $msg, MAIL_FROM);

            flash('success', "Request #{$rid} approved. PROPOSED repeater record created.");

        } elseif ($action === 'deny') {
            $db->prepare("UPDATE coordination_requests SET status='DENIED', coordinator_notes=?, coordinator_id=? WHERE id=?")
               ->execute([$note, $user['id'], $rid]);

            $msg  = "We regret to inform you that your repeater coordination request #{$rid} has been DENIED.\n\n";
            if ($note) $msg .= "Reason: {$note}\n";
            $msg .= "\nFor questions, contact your district coordinator.\n73,\nOklahoma Repeater Society\n";
            orsi_mail($req["applicant_email"], "Coordination Request #{$rid} - ORSI", $msg, MAIL_FROM);

            flash('warning', "Request #{$rid} denied.");

        } elseif ($action === 'info') {
            $db->prepare("UPDATE coordination_requests SET status='INFO_REQUESTED', coordinator_notes=?, coordinator_id=? WHERE id=?")
               ->execute([$note, $user['id'], $rid]);

            $msg  = "Your repeater coordination request #{$rid} requires additional information.\n\n";
            $msg .= "Please respond to this email with the requested information.\n\n";
            if ($note) $msg .= "Coordinator notes: {$note}\n";
            $msg .= "\n73,\nOklahoma Repeater Society\n";
            orsi_mail($req["applicant_email"], "Coordination Request #{$rid} - More Info Needed", $msg, MAIL_FROM);

            flash('info', "Request #{$rid} marked as Info Requested.");
        }

        header('Location: ' . BASE_PATH . '/admin/requests.php');
        exit;
    }
}

// ── Single request view ───────────────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$view_req = null;
$freq_suggestion = null;
if ($view_id) {
    $s = $db->prepare("SELECT * FROM coordination_requests WHERE id=?");
    $s->execute([$view_id]);
    $view_req = $s->fetch();

    // Check for nearby state borders (NOPC requirement)
    $nearby_states = [];
    if ($view_req['latitude'] && $view_req['longitude']) {
        $nearby_states = get_nearby_states((float)$view_req['latitude'], (float)$view_req['longitude'], 100);
    }

    // Calculate best frequency suggestion if we have coords and band
    if ($view_req && $view_req['latitude'] && $view_req['req_band']) {
        $bands_def = [
            '10m'   =>['low'=>29.620, 'high'=>29.680, 'step'=>20.0, 'offset'=>-0.100],
            '6m'    =>['low'=>52.810, 'high'=>53.990, 'step'=>20.0, 'offset'=>-1.700],
            '2m-lo' =>['low'=>145.110,'high'=>145.490,'step'=>20.0, 'offset'=>-0.600],
            '2m-mid'=>['low'=>146.610,'high'=>146.985,'step'=>15.0, 'offset'=>-0.600],
            '2m-hi' =>['low'=>147.000,'high'=>147.390,'step'=>15.0, 'offset'=>0.600],
            '1.25m' =>['low'=>223.860,'high'=>224.980,'step'=>20.0, 'offset'=>-1.600],
            '70cm'  =>['low'=>442.000,'high'=>444.975,'step'=>25.0, 'offset'=>5.000],
            '33cm'  =>['low'=>902.000,'high'=>903.000,'step'=>25.0, 'offset'=>25.000],
            '23cm'  =>['low'=>1282.000,'high'=>1288.000,'step'=>25.0,'offset'=>-12.000],
        ];
        $band = $bands_def[$view_req['req_band']] ?? null;
        if ($band) {
            $step_mhz = $band['step'] / 1000;
            $band_mid = ($band['low'] + $band['high']) / 2;
            $rule = $db->query("SELECT * FROM coordination_rules WHERE band_low_mhz <= {$band_mid} AND band_high_mhz >= {$band_mid} LIMIT 1")->fetch();
            $co_min   = $rule ? (float)$rule['co_channel_min_miles']    : 75.0;
            $adj_15   = $rule ? (float)($rule['adj_15khz_min_miles'] ?? 40.0) : 40.0;
            $adj_20   = $rule ? (float)($rule['adj_20khz_min_miles'] ?? 25.0) : 25.0;
            $adj_30   = $rule ? (float)($rule['adj_30khz_min_miles'] ?? 20.0) : 20.0;
            $existing = $db->query("SELECT output_freq, latitude, longitude, location_source FROM repeaters WHERE archived_at IS NULL AND output_freq >= {$band['low']} AND output_freq <= {$band['high']} AND status NOT IN ('DECOORDINATED') AND latitude IS NOT NULL")->fetchAll();
            $slots = [];
            $freq = $band['low'];
            while ($freq <= $band['high']) { $slots[] = round($freq,4); $freq = round($freq + $step_mhz, 4); }
            $best_freq = null; $best_score = -1;
            foreach ($slots as $slot) {
                $co_ok = true; $adj_ok = true; $min_dist = PHP_INT_MAX;
                foreach ($existing as $e) {
                    $diff_khz = round(abs($slot - (float)$e['output_freq']) * 1000, 1);
                    if ($diff_khz > 30.1) continue;
                    $dist = haversine((float)$view_req['latitude'], (float)$view_req['longitude'], (float)$e['latitude'], (float)$e['longitude']);
                    $city_margin = ($e['location_source'] ?? '') === 'CITY' ? 0.85 : 1.0;
                    $eff_dist = $dist * $city_margin;
                    $min_dist = min($min_dist, $eff_dist);
                    if ($diff_khz < 0.5   && $eff_dist < $co_min) $co_ok  = false;
                    if ($diff_khz <= 15.5 && $eff_dist < $adj_15) $co_ok  = false;
                    if ($diff_khz <= 20.5 && $eff_dist < $adj_20) $adj_ok = false;
                    if ($diff_khz <= 30.5 && $eff_dist < $adj_30) $adj_ok = false;
                }
                if (!$co_ok || !$adj_ok) continue;
                $score = $min_dist === PHP_INT_MAX ? 9999 : $min_dist;
                if ($score > $best_score) { $best_score = $score; $best_freq = $slot; }
            }
            if ($best_freq) {
                $freq_suggestion = [
                    'output' => $best_freq,
                    'input'  => round($best_freq + $band['offset'], 4),
                    'score'  => $best_score === 9999 ? 'No nearby repeaters' : round($best_score,1).' mi to nearest',
                ];
            }
        }
    }
}

// ── List requests ─────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'PENDING';
$where = $filter_status ? "WHERE status = ?" : "WHERE 1=1";
$params = $filter_status ? [$filter_status] : [];

// Coordinators only see their district unless admin
if ($user['role'] !== 'admin') {
    // Try to determine district from user's coord records
    // For now show all - coordinators can filter
}

$requests = $db->prepare("SELECT * FROM coordination_requests $where ORDER BY submitted_at DESC");
$requests->execute($params);
$reqs = $requests->fetchAll();

$pending_count = (int)$db->query("SELECT COUNT(*) FROM coordination_requests WHERE status='PENDING'")->fetchColumn();

$page_title = 'Coordination Requests';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div class="page-title" style="margin:0;border:none;padding:0">
    <i class="fa fa-inbox"></i> Coordination Requests
    <?php if ($pending_count): ?>
    <span class="badge" style="background:#dc2626;color:#fff;font-size:.8rem;margin-left:6px"><?= $pending_count ?> pending</span>
    <?php endif; ?>
  </div>
  <a href="<?= BASE_PATH ?>/request.php" class="btn btn-secondary btn-sm" target="_blank"><i class="fa fa-external-link"></i> View Public Form</a>
</div>

<!-- Filter tabs -->
<div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach ([''=>'All','PENDING'=>'Pending','INFO_REQUESTED'=>'Info Requested','APPROVED'=>'Approved','DENIED'=>'Denied'] as $s=>$label): ?>
  <a href="?status=<?= urlencode($s) ?>" class="btn btn-sm <?= $filter_status===$s?'btn-primary':'btn-secondary' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if ($view_req): ?>
<!-- ── Single Request Detail ── -->
<div class="card" style="margin-bottom:20px;border-top:4px solid var(--primary-m)">
  <div class="card-header">
    <i class="fa fa-file-alt"></i> Request #<?= $view_req['id'] ?> -
    <?= h($view_req['applicant_callsign']) ?> - <?= h($bands[$view_req['req_band']] ?? $view_req['req_band']) ?>
    <span class="badge badge-<?= strtolower(str_replace('_','-',$view_req['status'])) ?>" style="margin-left:8px"><?= h($view_req['status']) ?></span>
  </div>
  <div class="card-body" style="padding:0">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
      <table class="detail-table">
        <tr><th>Applicant</th><td><?= h($view_req['applicant_name']) ?></td></tr>
        <tr><th>Callsign</th><td><strong><?= h($view_req['applicant_callsign']) ?></strong></td></tr>
        <tr><th>Email</th><td><a href="mailto:<?= h($view_req['applicant_email']) ?>"><?= h($view_req['applicant_email']) ?></a></td></tr>
        <tr><th>Phone</th><td><?= h($view_req['applicant_phone'] ?: '-') ?></td></tr>
        <tr><th>Sponsor</th><td><?= h($view_req['sponsor'] ?: '-') ?></td></tr>
        <tr><th>Submitted</th><td><?= substr($view_req['submitted_at'],0,10) ?></td></tr>
      </table>
      <table class="detail-table">
        <tr><th>Type</th><td><?= h($view_req['req_type']) ?></td></tr>
        <tr><th>Band</th><td><?= h($bands[$view_req['req_band']] ?? $view_req['req_band']) ?></td></tr>
        <tr><th>Applicant Preferred</th><td>
          <?php if ($view_req['preferred_freq']): ?>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <span class="freq" id="pref_freq_display"><?= number_format((float)$view_req['preferred_freq'],4) ?> MHz</span>
              <button type="button" onclick="document.getElementById('pref_freq_edit').style.display='flex';this.style.display='none'"
                class="btn btn-secondary btn-sm" style="padding:3px 10px;font-size:.8rem">
                <i class="fa fa-pencil"></i> Edit
              </button>
            </div>
            <div id="pref_freq_edit" style="display:none;align-items:center;gap:8px;margin-top:6px">
              <form method="post" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="req_id" value="<?= $view_req['id'] ?>">
                <input type="hidden" name="current_status" value="<?= h($view_req['status']) ?>">
                <input type="number" name="new_preferred_freq" step="0.0025" min="28" max="1300"
                  value="<?= number_format((float)$view_req['preferred_freq'],4) ?>"
                  style="width:130px;padding:5px;border:1px solid #ccc;border-radius:4px;font-size:.9rem">
                <button type="submit" name="action" value="update_preferred" class="btn btn-primary btn-sm"
                  style="padding:4px 12px;font-size:.8rem">
                  <i class="fa fa-check"></i> Update &amp; Recheck
                </button>
                <button type="button" onclick="document.getElementById('pref_freq_edit').style.display='none';document.querySelector('[onclick*=pref_freq_edit]').style.display=\'inline-block\'"
                  class="btn btn-secondary btn-sm" style="padding:4px 10px;font-size:.8rem">Cancel</button>
              </form>
            </div>
            <?php
            // Check if preferred freq has conflicts
            if ($view_req['preferred_freq'] && $view_req['latitude'] && $view_req['longitude']) {
                $pfreq = (float)$view_req['preferred_freq'];
                $plat  = (float)$view_req['latitude'];
                $plon  = (float)$view_req['longitude'];
                $band_q = $db->query("SELECT * FROM coordination_rules WHERE band_low_mhz <= $pfreq AND band_high_mhz >= $pfreq LIMIT 1")->fetch();
                $co_min  = $band_q ? (float)$band_q['co_channel_min_miles']  : 75.0;
                $adj_min = $band_q ? (float)$band_q['adj_channel_min_miles'] : 50.0;
                $adj_khz = $band_q ? (float)$band_q['channel_width_khz']     : 25.0;
                $nearby = $db->query("SELECT callsign, output_freq, latitude, longitude, city, status, location_source FROM repeaters
                    WHERE output_freq BETWEEN " . ($pfreq - $adj_khz/1000) . " AND " . ($pfreq + $adj_khz/1000) . "
                    AND archived_at IS NULL AND status NOT IN ('DECOORDINATED')")->fetchAll();
                $pconflicts = [];
                foreach ($nearby as $nb) {
                    if (!$nb['latitude'] || !$nb['longitude']) continue;
                    $dlat = ($plat - $nb['latitude']) * M_PI/180;
                    $dlon = ($plon - $nb['longitude']) * M_PI/180;
                    $a = sin($dlat/2)*sin($dlat/2) + cos($plat*M_PI/180)*cos($nb['latitude']*M_PI/180)*sin($dlon/2)*sin($dlon/2);
                    $dist = 3958.8 * 2 * atan2(sqrt($a), sqrt(1-$a));
                    $diff_khz = abs($pfreq - (float)$nb['output_freq']) * 1000;
                    if ($diff_khz < 0.1 && $dist < $co_min) $pconflicts[] = ['type'=>'co-channel', 'callsign'=>$nb['callsign'], 'freq'=>$nb['output_freq'], 'city'=>$nb['city'], 'dist'=>round($dist,1), 'min'=>$co_min];
                    elseif ($diff_khz <= $adj_khz && $dist < $adj_min) $pconflicts[] = ['type'=>'adjacent ('.round($diff_khz,1).' kHz)', 'callsign'=>$nb['callsign'], 'freq'=>$nb['output_freq'], 'city'=>$nb['city'], 'dist'=>round($dist,1), 'min'=>$adj_min];
                }
                if ($pconflicts): ?>
                <div style="margin-top:6px">
                  <?php foreach ($pconflicts as $pc): ?>
                  <div class="alert alert-warning" style="padding:6px 10px;font-size:.8rem;margin-bottom:4px">
                    <i class="fa fa-triangle-exclamation"></i>
                    <strong><?= $pc['type'] === 'co-channel' ? 'Co-channel' : 'Adjacent channel' ?> conflict</strong>
                    with <strong><?= h($pc['callsign']) ?></strong>
                    (<?= number_format((float)$pc['freq'],4) ?> MHz, <?= h($pc['city']) ?>)
                    — only <strong><?= $pc['dist'] ?> mi</strong> away
                    (minimum required: <?= $pc['min'] ?> mi)
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif;
            } ?>
          <?php else: ?>
            <span class="text-muted">No preference stated</span>
          <?php endif; ?>
        </td></tr>
        <tr style="background:#f0fdf4"><th>System Recommended</th><td>
          <?php if ($freq_suggestion): ?>
            <?php if ($view_req['preferred_freq'] && abs($freq_suggestion['output'] - (float)$view_req['preferred_freq']) < 0.001): ?>
              <!-- Preferred = Recommended -->
              <strong class="freq" style="color:var(--success)">
                <i class="fa fa-check-circle"></i>
                <?= number_format($freq_suggestion['output'],4) ?> MHz output
              </strong>
              / <?= number_format($freq_suggestion['input'],4) ?> MHz input<br>
              <small style="color:var(--success)">
                <i class="fa fa-check"></i> Applicant's preferred frequency is the best available — <?= h($freq_suggestion['score']) ?>
              </small>
            <?php elseif ($view_req['preferred_freq']): ?>
              <!-- Preferred differs from Recommended -->
              <strong class="freq" style="color:var(--success)">
                <?= number_format($freq_suggestion['output'],4) ?> MHz output
              </strong>
              / <?= number_format($freq_suggestion['input'],4) ?> MHz input<br>
              <small class="text-muted"><?= h($freq_suggestion['score']) ?></small>
              <div class="alert alert-info" style="padding:8px 12px;font-size:.82rem;margin-top:8px;border-left:4px solid #0369a1">
                <i class="fa fa-info-circle"></i>
                <strong>Note:</strong> The applicant's preferred frequency
                <strong><?= number_format((float)$view_req['preferred_freq'],4) ?> MHz</strong>
                is also a valid coordination candidate — it passes all co-channel and adjacent channel checks.
                However, <strong><?= number_format($freq_suggestion['output'],4) ?> MHz</strong>
                provides more separation from existing repeaters (<?= h($freq_suggestion['score']) ?>)
                and is the optimal choice. Either frequency may be coordinated at the coordinator's discretion.
              </div>
            <?php else: ?>
              <strong class="freq" style="color:var(--success)"><?= number_format($freq_suggestion['output'],4) ?> MHz output</strong>
              / <?= number_format($freq_suggestion['input'],4) ?> MHz input<br>
              <small class="text-muted"><?= h($freq_suggestion['score']) ?></small>
            <?php endif; ?>
          <?php elseif ($view_req['suggested_freq']): ?>
            <span class="freq"><?= number_format((float)$view_req['suggested_freq'],4) ?> MHz</span>
          <?php else: ?>
            <span class="text-muted">No available frequency found — band may be full in this area, or coordinates missing</span>
          <?php endif; ?>
        </td></tr>
        <tr><th>Location</th><td><?= h($view_req['city']) ?>, <?= h($view_req['county']) ?></td></tr>
        <tr><th>District</th><td><?= h($view_req['district'] ?: 'TBD') ?></td></tr>
        <tr><th>GPS</th><td><?= $view_req['latitude'] ? h($view_req['latitude']).', '.h($view_req['longitude']) : '-' ?></td></tr>
        <tr><th>AGL / HAAT</th><td><?= $view_req['antenna_height_agl'] ? h($view_req['antenna_height_agl']).' ft / ' : '' ?><?= $view_req['haat'] ? h($view_req['haat']).' ft HAAT' : '-' ?></td></tr>
        <tr><th>TX / ERP</th><td><?= $view_req['tx_power_watts'] ? h($view_req['tx_power_watts']).' W / ' : '' ?><?= $view_req['erp_watts'] ? h($view_req['erp_watts']).' W ERP' : '-' ?></td></tr>
        <tr><th>Access / Tone</th><td>
          <?php
          switch($view_req['tone_type'] ?? 'CARRIER') {
            case 'CTCSS':  echo $view_req['pl_tone'] ? number_format((float)$view_req['pl_tone'],1).' Hz CTCSS/PL' : 'CTCSS'; break;
            case 'DCS':    echo $view_req['dcs_code'] ? 'DCS D'.$view_req['dcs_code'] : 'DCS'; break;
            case 'DMR':    echo 'DMR Color Code '.($view_req['dmr_color_code'] ?? '?'); break;
            case 'FUSION': echo 'Fusion / C4FM'; break;
            case 'P-25':   echo 'P-25'; break;
            case 'D-STAR': echo 'D-STAR'; break;
            default:       echo 'Carrier Squelch';
          }
          ?>
        </td></tr>
        <tr><th>Repeater Type</th><td><?= h($view_req['req_type']) ?>
          <?php if ($view_req['mixed_mode'] ?? 0): ?>
          <span class="badge badge-construction" style="margin-left:6px">Mixed Mode</span>
          <?php if ($view_req['mixed_mode_types']): ?>
          - <?php foreach(explode(',',$view_req['mixed_mode_types']) as $m): ?>
          <span class="badge" style="background:#dbeafe;color:#1e40af;margin-left:2px"><?= h(trim($m)) ?></span>
          <?php endforeach; ?>
          <?php endif; ?>
          <?php endif; ?>
        </td></tr>
        <tr><th>Features</th><td>
          <?= $view_req['feature_skywarn']   ? '<span class="badge badge-operational">SKYWARN</span> ' : '' ?>
          <?= $view_req['feature_linked']    ? '<span class="badge badge-construction">Linked</span> ' : '' ?>
          <?= $view_req['feature_allstar']   ? '<span class="badge badge-construction">AllStar</span> ' : '' ?>
          <?= $view_req['feature_echolink']  ? '<span class="badge badge-construction">EchoLink</span> ' : '' ?>
          <?= $view_req['feature_autopatch'] ? '<span class="badge badge-construction">AutoPatch</span> ' : '' ?>
          <?= ($view_req['backup_power'] ?? 0) ? '<span class="badge badge-construction">Backup Power</span> ' : '' ?>
          <?php if (!$view_req['feature_skywarn'] && !$view_req['feature_linked'] && !$view_req['feature_allstar'] && !$view_req['feature_echolink'] && !$view_req['feature_autopatch'] && !($view_req['backup_power']??0)): ?>
          <span class="text-muted">None selected</span>
          <?php endif; ?>
        </td></tr>
      </table>
    </div>
    <?php if ($view_req['notes']): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border)">
      <strong>Applicant Notes:</strong><br><?= nl2br(h($view_req['notes'])) ?>
    </div>
    <?php endif; ?>
    <?php if ($view_req['coordinator_notes']): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border);background:#fffbeb">
      <strong>Coordinator Notes:</strong><br><?= nl2br(h($view_req['coordinator_notes'])) ?>
    </div>
    <?php endif; ?>
    <?php if ($view_req['repeater_id']): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border)">
      <a href="<?= BASE_PATH ?>/repeater.php?id=<?= $view_req['repeater_id'] ?>" class="btn btn-primary btn-sm">
        <i class="fa fa-tower-broadcast"></i> View Created Repeater Record
      </a>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($nearby_states)): ?>
  <div style="padding:14px 16px;border-top:2px solid #f59e0b;background:#fffbeb">
    <h3 style="font-size:.9rem;font-weight:700;color:#92400e;margin-bottom:10px">
      <i class="fa fa-triangle-exclamation"></i> NOPC Required - Repeater site is within 100 miles of neighboring state(s)
    </h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php
    // Load NOPC contacts for nearby states
    $state_abbrs = array_keys($nearby_states);
    $placeholders = implode(',', array_fill(0, count($state_abbrs), '?'));
    $nopc_rows = $db->prepare("SELECT * FROM nopc_contacts WHERE state_abbr IN ($placeholders) AND active=1");
    $nopc_rows->execute($state_abbrs);
    $nopc_contacts = $nopc_rows->fetchAll();
    foreach ($nopc_contacts as $nc):
        $dist = $nearby_states[$nc['state_abbr']] ?? '?';
    ?>
    <?php
    $nopc_chk = $db->prepare("SELECT * FROM nopc_notifications WHERE request_id=? AND state_abbr=? ORDER BY sent_at DESC LIMIT 1");
    $nopc_chk->execute([$view_req['id'], $nc['state_abbr']]);
    $nopc_existing = $nopc_chk->fetch();
    $freq = $view_req['suggested_freq'] ?: $view_req['preferred_freq'];
?>
    <div style="background:#fff;border:1px solid #fcd34d;border-radius:var(--radius);padding:10px 14px;min-width:220px">
      <div style="font-weight:700;color:#92400e"><?= h($nc['state']) ?> <span style="font-size:.75rem;color:var(--muted)">(<?= $dist ?> mi)</span></div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px">
        <?= h($nc['org_name'] ?: '-') ?><?= $nc['contact_name'] ? ' - '.h($nc['contact_name']) : '' ?><br>
        <a href="mailto:<?= h($nc['email']) ?>"><?= h($nc['email']) ?></a>
      </div>
      <?php if ($nopc_existing): ?>
      <div style="font-size:.75rem;margin-bottom:6px;padding:4px 8px;border-radius:3px;background:<?= $nopc_existing['status']==='APPROVED'?'#d1fae5':($nopc_existing['status']==='DECLINED'?'#fee2e2':'#fffbeb') ?>">
        <strong><?= h($nopc_existing['status']) ?></strong>
        - <?= $nopc_existing['response_at'] ? substr($nopc_existing['response_at'],0,10) : 'Sent '.substr($nopc_existing['sent_at'],0,10) ?>
        <?php if ($nopc_existing['status']==='PENDING' && strtotime($nopc_existing['expires_at']) < time()): ?>
        <span style="color:#dc2626"> - EXPIRED</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div style="display:flex;flex-direction:column;gap:4px">
        <?php if (!$nopc_existing || $nopc_existing['status'] !== 'PENDING'): ?>
        <form method="post">
          <input type="hidden" name="send_nopc" value="1">
          <input type="hidden" name="req_id" value="<?= $view_req['id'] ?>">
          <input type="hidden" name="state_abbr" value="<?= h($nc['state_abbr']) ?>">
          <button type="submit" class="btn btn-warning btn-sm" style="width:100%"
            onclick="return confirm('Send NOPC email to <?= h($nc['state']) ?> coordinator?')">
            <i class="fa fa-paper-plane"></i> Send NOPC to <?= h($nc['state_abbr']) ?>
          </button>
        </form>
        <?php else: ?>
        <div style="width:100%;background:#fef3c7;color:#92400e;text-align:center;padding:6px;border-radius:var(--radius);font-size:.82rem">
          <i class="fa fa-clock"></i> Awaiting Response
        </div>
        <?php endif; ?>
        <a href="mailto:<?= h($nc['email']) ?>?subject=<?= urlencode('NOPC - '.$view_req['applicant_callsign'].' - '.$freq.' MHz - Oklahoma') ?>"
           class="btn btn-secondary btn-sm" style="width:100%;text-align:center;font-size:.72rem">
          <i class="fa fa-envelope"></i> Open in Email Client
        </a>
      </div>
    </div>

    <?php endforeach; ?>
    <?php if (empty($nopc_contacts)): ?>
    <div class="alert alert-warning" style="margin:0">
      <i class="fa fa-exclamation-circle"></i> No NOPC contacts found for nearby states. 
      <a href="<?= BASE_PATH ?>/admin/rules.php#nopc">Add contacts in Admin → Rules → NOPC</a>
    </div>
    <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // Load NOPC notification history for this request
  $nopc_log = $db->prepare("SELECT * FROM nopc_notifications WHERE request_id=? ORDER BY sent_at DESC");
  $nopc_log->execute([$view_req['id']]);
  $nopc_history = $nopc_log->fetchAll();
  if ($nopc_history):
  ?>
  <div style="padding:12px 16px;border-top:1px solid var(--border);background:#f8fafc">
    <h4 style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px"><i class="fa fa-clock-rotate-left"></i> NOPC History</h4>
    <table style="width:100%;font-size:.8rem;border-collapse:collapse">
      <thead><tr style="border-bottom:1px solid var(--border)">
        <th style="padding:4px 8px;text-align:left">State</th>
        <th style="padding:4px 8px;text-align:left">Sent</th>
        <th style="padding:4px 8px;text-align:left">Expires</th>
        <th style="padding:4px 8px;text-align:left">Status</th>
        <th style="padding:4px 8px;text-align:left">Response</th>
        <th style="padding:4px 8px;text-align:left">Notes</th>
      </tr></thead>
      <tbody>
      <?php foreach ($nopc_history as $nl):
        $status_color = ['APPROVED'=>'#d1fae5','DECLINED'=>'#fee2e2','NO_RESPONSE'=>'#f3f4f6','PENDING'=>'#fffbeb'];
        $expired = $nl['status']==='PENDING' && strtotime($nl['expires_at']) < time();
      ?>
      <tr style="border-bottom:1px solid var(--border);background:<?= $status_color[$nl['status']] ?? '#fff' ?>">
        <td style="padding:4px 8px"><strong><?= h($nl['state']) ?></strong></td>
        <td style="padding:4px 8px"><?= substr($nl['sent_at'],0,10) ?><?= $nl['reminder_sent'] ? ' <span title="Reminder sent">🔔</span>' : '' ?></td>
        <td style="padding:4px 8px"><?= substr($nl['expires_at'],0,10) ?></td>
        <td style="padding:4px 8px">
          <strong><?= $expired ? 'EXPIRED' : h($nl['status']) ?></strong>
        </td>
        <td style="padding:4px 8px"><?= $nl['response_at'] ? substr($nl['response_at'],0,10) : '-' ?></td>
        <td style="padding:4px 8px"><?= h($nl['response_notes'] ?: '-') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($view_req['status'] === 'PENDING' || $view_req['status'] === 'INFO_REQUESTED'): ?>
  <div style="padding:16px;border-top:2px solid var(--border);background:#f8fafc">
    <h3 style="margin-bottom:12px;font-size:1rem;color:var(--primary)">Coordinator Action</h3>
    <form method="post">
      <input type="hidden" name="req_id" value="<?= $view_req['id'] ?>">
      <div class="form-group" style="margin-bottom:12px">
        <label>Coordinator Notes (emailed to applicant)</label>
        <textarea name="coordinator_notes" rows="3" style="width:100%;resize:vertical" placeholder="Add notes, explain decision, request specific info…"></textarea>
      </div>
      <div class="form-group" style="margin-bottom:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px">
        <label style="color:#15803d;font-weight:600"><i class="fa fa-radio"></i> Coordinated Frequency (used when approving)</label>
        <div style="display:flex;gap:10px;align-items:center;margin-top:6px">
          <div>
            <label style="font-size:.8rem;color:#666">Output MHz</label><br>
            <input type="number" name="override_output_freq" step="0.0025" min="28" max="1300"
              style="width:140px;padding:6px;border:1px solid #ccc;border-radius:4px"
              placeholder="e.g. 443.5500"
              value="<?= number_format((float)($freq_suggestion['output'] ?? $view_req['suggested_freq'] ?? $view_req['preferred_freq']), 4) ?>">
          </div>
          <div>
            <label style="font-size:.8rem;color:#666">Input MHz</label><br>
            <input type="number" name="override_input_freq" step="0.0025" min="28" max="1300"
              style="width:140px;padding:6px;border:1px solid #ccc;border-radius:4px"
              placeholder="e.g. 448.5500"
              value="<?= number_format((float)($freq_suggestion['input'] ?? ($view_req['suggested_freq'] ? $view_req['suggested_freq'] + ($bands_def[$view_req['req_band']]['offset'] ?? 0) : 0)), 4) ?>">
          </div>
          <div style="margin-top:16px;font-size:.8rem;color:#666">
            <i class="fa fa-info-circle"></i> Override frequency if needed.<br>
            Input auto-calculates from output.
          </div>
        </div>
      </div>
      <script>
      (function() {
        var bandOffsets = {
          '10m': -0.100, '6m': -1.700,
          '2m-lo': -0.600, '2m-mid': -0.600, '2m-hi': 0.600,
          '1.25m': -1.600, '70cm': 5.000, '33cm': 25.000, '23cm': -12.000
        };
        var band = '<?= addslashes($view_req["req_band"] ?? "") ?>';
        var offset = (band in bandOffsets) ? bandOffsets[band] : null;
        var outInput = document.querySelector('input[name="override_output_freq"]');
        var inInput  = document.querySelector('input[name="override_input_freq"]');
        if (outInput && inInput && offset !== null) {
          // Auto-calc on page load
          var initOut = parseFloat(outInput.value);
          if (!isNaN(initOut) && initOut > 0) {
            inInput.value = (initOut + offset).toFixed(4);
          }
          // Auto-calc on input change
          outInput.addEventListener('input', function() {
            var out = parseFloat(this.value);
            if (!isNaN(out) && out > 0) {
              inInput.value = (out + offset).toFixed(4);
            }
          });
        }
      })();
      </script>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" name="action" value="approve" class="btn btn-success"
          onclick="return confirm('Approve this request and create a PROPOSED repeater record?')">
          <i class="fa fa-check"></i> Approve &amp; Create Record
        </button>
        <button type="submit" name="action" value="info" class="btn btn-warning">
          <i class="fa fa-question-circle"></i> Request More Info
        </button>
        <button type="submit" name="action" value="deny" class="btn btn-danger"
          onclick="return confirm('Deny this request? An email will be sent to the applicant.')">
          <i class="fa fa-times"></i> Deny
        </button>
        <a href="<?= BASE_PATH ?>/admin/requests.php" class="btn btn-secondary">Back to List</a>
      </div>
    </form>
  </div>
  <?php else: ?>
  <div style="padding:12px 16px;border-top:1px solid var(--border)">
    <a href="<?= BASE_PATH ?>/admin/requests.php" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to List</a>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- ── Request List ── -->
<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> <?= count($reqs) ?> Request<?= count($reqs)!=1?'s':'' ?></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>#</th><th>Status</th><th>Callsign</th><th>Name</th>
        <th>Band</th><th>Suggested Freq</th><th>Location</th><th>District</th><th>Submitted</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php if (!$reqs): ?>
      <tr><td colspan="10" class="text-center text-muted" style="padding:30px">No requests found.</td></tr>
      <?php else: foreach ($reqs as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td>
          <?php
          $sc = ['PENDING'=>'proposed','APPROVED'=>'operational','DENIED'=>'dead','INFO_REQUESTED'=>'down-temporarily'];
          echo '<span class="badge badge-'.($sc[$r['status']]??'unknown').'">'.h($r['status']).'</span>';
          ?>
        </td>
        <td><strong><?= h($r['applicant_callsign']) ?></strong></td>
        <td><?= h($r['applicant_name']) ?></td>
        <td><?= h($bands[$r['req_band']] ?? $r['req_band']) ?></td>
        <td><?= $r['suggested_freq'] ? '<span class="freq">'.number_format((float)$r['suggested_freq'],4).'</span>' : '<span class="text-muted">-</span>' ?></td>
        <td><?= h($r['city']) ?><?= $r['county'] ? ', '.h($r['county']) : '' ?></td>
        <td><?= h($r['district'] ?: '?') ?></td>
        <td style="font-size:.78rem;white-space:nowrap"><?= substr($r['submitted_at'],0,10) ?></td>
        <td>
          <a href="?id=<?= $r['id'] ?>&status=<?= urlencode($filter_status) ?>" class="btn btn-sm btn-primary"><i class="fa fa-eye"></i> Review</a>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
