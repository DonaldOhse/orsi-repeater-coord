<?php
require_once __DIR__ . '/includes/config.php';

// Coordinator emails are managed in Admin > Users - assign a district to each coordinator

$db = get_db();
$errors = [];
$success = false;
$suggested_freq = null;

// Band definitions: [low, high, step_khz, offset_mhz, input_is_lower]
$bands = [
    '10m'   => ['name'=>'10m (29 MHz)',     'low'=>29.620,  'high'=>29.680,  'step'=>20.0,  'offset'=>-0.100, 'dir'=>-1],
    '6m'    => ['name'=>'6m (52 MHz)',      'low'=>52.810,  'high'=>53.990,  'step'=>20.0,  'offset'=>-1.700, 'dir'=>-1],
    '2m-lo' => ['name'=>'2m Low (145 MHz)', 'low'=>145.110, 'high'=>145.490, 'step'=>20.0,  'offset'=>-0.600, 'dir'=>-1],
    '2m-mid'=> ['name'=>'2m Mid (146 MHz)', 'low'=>146.610, 'high'=>146.985, 'step'=>15.0,  'offset'=>-0.600, 'dir'=>-1],
    '2m-hi' => ['name'=>'2m High (147 MHz)','low'=>147.000, 'high'=>147.390, 'step'=>15.0,  'offset'=>0.600,  'dir'=>1],
    '1.25m' => ['name'=>'1.25m (222 MHz)',  'low'=>223.860, 'high'=>224.980, 'step'=>20.0,  'offset'=>-1.600, 'dir'=>-1],
    '70cm'  => ['name'=>'70cm (440 MHz)',   'low'=>442.000, 'high'=>444.975, 'step'=>25.0,  'offset'=>5.000,  'dir'=>1],
    '33cm'  => ['name'=>'33cm (902 MHz)',   'low'=>902.000, 'high'=>903.000, 'step'=>25.0,  'offset'=>25.000, 'dir'=>1],
    '23cm'  => ['name'=>'23cm (1.2 GHz)',   'low'=>1282.000,'high'=>1288.000,'step'=>25.0,  'offset'=>-12.000,'dir'=>-1],
];

// ── AJAX: Check specific frequency for conflicts ──────────────
if (isset($_GET['check_freq'])) {
    header('Content-Type: application/json');
    $freq    = round((float)($_GET['freq'] ?? 0), 4);
    $lat     = (float)($_GET['lat'] ?? 0);
    $lon     = (float)($_GET['lon'] ?? 0);
    $req_id  = (int)($_GET['exclude_id'] ?? 0); // exclude self when editing

    if (!$freq || !$lat || !$lon) { echo json_encode(['error'=>'Missing parameters']); exit; }

    // Find band
    $band_found = null;
    $bands = [
        '10m'   => ['low'=>28.0,  'high'=>29.7,  'step'=>0.010],
        '6m'    => ['low'=>50.0,  'high'=>54.0,  'step'=>0.020],
        '2m'    => ['low'=>144.0, 'high'=>148.0, 'step'=>0.020],
        '1.25m' => ['low'=>222.0, 'high'=>225.0, 'step'=>0.020],
        '70cm'  => ['low'=>420.0, 'high'=>450.0, 'step'=>0.025],
        '33cm'  => ['low'=>902.0, 'high'=>928.0, 'step'=>0.025],
        '23cm'  => ['low'=>1240.0,'high'=>1300.0,'step'=>0.025],
    ];
    foreach ($bands as $name => $b) {
        if ($freq >= $b['low'] && $freq <= $b['high']) { $band_found = $b; $band_found['name'] = $name; break; }
    }
    if (!$band_found) { echo json_encode(['error'=>'Frequency not in a recognized amateur band']); exit; }

    $rule = $db->query("SELECT * FROM coordination_rules WHERE band_low_mhz <= {$freq} AND band_high_mhz >= {$freq} LIMIT 1")->fetch();
    $co_min  = $rule ? (float)$rule['co_channel_min_miles']  : 75.0;
    $adj_min = $rule ? (float)$rule['adj_channel_min_miles'] : 50.0;
    $adj_khz = $rule ? (float)$rule['channel_width_khz']     : 25.0;

    $existing = $db->query("SELECT id, callsign, output_freq, latitude, longitude, status, city, location_source
        FROM repeaters
        WHERE output_freq >= {$band_found['low']} AND output_freq <= {$band_found['high']}
        AND archived_at IS NULL
        AND status NOT IN ('DEAD','DECOORDINATED')
        " . ($req_id ? "AND id != $req_id" : "") . "
    ")->fetchAll();

    // Multi-tier adjacent channel distances
    $adj_15_min = $rule ? (float)($rule['adj_15khz_min_miles'] ?? 40.0) : 40.0;
    $adj_20_min = $rule ? (float)($rule['adj_20khz_min_miles'] ?? 25.0) : 25.0;
    $adj_30_min = $rule ? (float)($rule['adj_30khz_min_miles'] ?? 20.0) : 20.0;

    $conflicts = [];
    foreach ($existing as $e) {
        if (!$e['latitude'] || !$e['longitude']) continue;
        $diff_khz = round(abs($freq - (float)$e['output_freq']) * 1000, 1);
        if ($diff_khz > 30.1) continue;
        $dist = haversine($lat, $lon, (float)$e['latitude'], (float)$e['longitude']);
        $city_margin = ($e['location_source'] ?? '') === 'CITY' ? 0.85 : 1.0;
        $eff_dist = $dist * $city_margin;
        $type = null; $min_req = 0;
        if ($diff_khz < 0.5)  {
            if ($eff_dist < $co_min)    { $type = 'co_channel';    $min_req = $co_min; }
        } elseif ($diff_khz <= 15.5) {
            if ($eff_dist < $adj_15_min) { $type = 'adjacent_15khz'; $min_req = $adj_15_min; }
        } elseif ($diff_khz <= 20.5) {
            if ($eff_dist < $adj_20_min) { $type = 'adjacent_20khz'; $min_req = $adj_20_min; }
        } elseif ($diff_khz <= 30.5) {
            if ($eff_dist < $adj_30_min) { $type = 'adjacent_30khz'; $min_req = $adj_30_min; }
        }
        if ($type) {
            $conflicts[] = [
                'type'       => $type,
                'callsign'   => $e['callsign'],
                'freq'       => number_format((float)$e['output_freq'], 4),
                'city'       => $e['city'],
                'status'     => $e['status'],
                'distance'   => round($dist, 1),
                'eff_dist'   => round($eff_dist, 1),
                'diff_khz'   => $diff_khz,
                'min_req'    => $min_req,
                'city_coords'=> ($e['location_source'] ?? '') === 'CITY',
                'co_min'     => $co_min,
                'adj_min'    => $adj_15_min,
            ];
        }
    }


    echo json_encode([
        'freq'      => $freq,
        'band'      => $band_found['name'],
        'conflicts' => $conflicts,
        'co_min'    => $co_min,
        'adj_min'   => $adj_min,
        'clear'     => empty($conflicts),
    ]);
    exit;
}

// ── AJAX: Suggest best frequency ──────────────────────────────
if (isset($_GET['suggest_freq'])) {
    header('Content-Type: application/json');
    $band_key = $_GET['band'] ?? '';
    $lat      = (float)($_GET['lat'] ?? 0);
    $lon      = (float)($_GET['lon'] ?? 0);

    if (!isset($bands[$band_key]) || !$lat || !$lon) {
        echo json_encode(['error' => 'Invalid band or coordinates']);
        exit;
    }

    $band = $bands[$band_key];
    $step_mhz = $band['step'] / 1000;

    // Get coordination rules for this band
    $band_mid = ($band['low'] + $band['high']) / 2;
    $rule = $db->query("SELECT * FROM coordination_rules WHERE band_low_mhz <= {$band_mid} AND band_high_mhz >= {$band_mid} LIMIT 1")->fetch();
    $co_min  = $rule ? (float)$rule['co_channel_min_miles']  : 75.0;
    $adj_min = $rule ? (float)$rule['adj_channel_min_miles'] : 50.0;

    // Get all active repeaters in this band with coords
    $existing = $db->query("SELECT output_freq, latitude, longitude, location_source FROM repeaters
        WHERE output_freq >= {$band['low']} AND output_freq <= {$band['high']}
        AND status NOT IN ('DECOORDINATED')
        AND latitude IS NOT NULL")->fetchAll();

    // Generate all channel slots in the band
    $slots = [];
    $freq = $band['low'];
    while ($freq <= $band['high']) {
        $slots[] = round($freq, 4);
        $freq += $step_mhz;
        $freq = round($freq, 4);
    }

    // Score each slot
    $best_freq  = null;
    $best_score = -1;

    foreach ($slots as $slot) {
        $min_co_dist  = PHP_INT_MAX;
        $min_adj_dist = PHP_INT_MAX;
        $co_conflict  = false;
        $adj_conflict = false;

        foreach ($existing as $e) {
            $diff_khz = round(abs($slot - (float)$e['output_freq']) * 1000, 1);
            if ($diff_khz > 30.1) continue;
            $dist = haversine($lat, $lon, (float)$e['latitude'], (float)$e['longitude']);
            $city_margin = ($e['location_source'] ?? '') === 'CITY' ? 0.85 : 1.0;
            $eff_dist = $dist * $city_margin;
            $adj_15 = (float)($rule['adj_15khz_min_miles'] ?? 40.0);
            $adj_20 = (float)($rule['adj_20khz_min_miles'] ?? 25.0);
            $adj_30 = (float)($rule['adj_30khz_min_miles'] ?? 20.0);
            if ($diff_khz < 0.5   && $eff_dist < $co_min)  $co_conflict  = true;
            if ($diff_khz <= 15.5 && $eff_dist < $adj_15)  $co_conflict  = true;
            if ($diff_khz <= 20.5 && $eff_dist < $adj_20)  $adj_conflict = true;
            if ($diff_khz <= 30.5 && $eff_dist < $adj_30)  $adj_conflict = true;
            $min_co_dist = min($min_co_dist, $eff_dist);
            $min_adj_dist = min($min_adj_dist, $eff_dist);
            if (false) { // padding to match structure
            }
        }

        if ($co_conflict || $adj_conflict) continue;

        // Score = distance to nearest existing repeater (more distance = better)
        $score = min($min_co_dist, $min_adj_dist);
        if ($score === PHP_INT_MAX) $score = 9999;

        if ($score > $best_score) {
            $best_score = $score;
            $best_freq  = $slot;
        }
    }

    $input_freq = $best_freq ? round($best_freq + $band['offset'], 4) : null;

    echo json_encode([
        'output_freq' => $best_freq,
        'input_freq'  => $input_freq,
        'offset'      => $band['offset'],
        'score_miles' => $best_score === 9999 ? 'No nearby repeaters' : round($best_score, 1) . ' miles to nearest',
        'band_name'   => $band['name'],
    ]);
    exit;
}

// ── Process form submission ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'applicant_name'     => trim($_POST['applicant_name']     ?? ''),
        'applicant_callsign'  => strtoupper(trim($_POST['applicant_callsign'] ?? '')),
        'trustee_callsign'    => strtoupper(trim($_POST['trustee_callsign'] ?? '')),
        'applicant_email'    => trim($_POST['applicant_email']    ?? ''),
        'applicant_phone'    => trim($_POST['applicant_phone']    ?? ''),
        'sponsor'            => trim($_POST['sponsor']            ?? ''),
        'req_type'           => trim($_POST['req_type']           ?? 'REPEATER'),
        'req_band'           => trim($_POST['req_band']           ?? ''),
        'preferred_freq'     => trim($_POST['preferred_freq'] ?? '') !== '' ? (float)$_POST['preferred_freq'] : null,
        'suggested_freq'     => trim($_POST['suggested_freq'] ?? '') !== '' ? (float)$_POST['suggested_freq'] : null,
        'latitude'           => trim($_POST['latitude']  ?? '') !== '' ? (float)$_POST['latitude']  : null,
        'longitude'          => trim($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null,
        'city'               => trim($_POST['city']               ?? ''),
        'county'             => strtoupper(trim($_POST['county']  ?? '')),
        'antenna_height_agl' => trim($_POST['antenna_height_agl'] ?? '') !== '' ? (float)$_POST['antenna_height_agl'] : null,
        'haat'               => trim($_POST['haat']               ?? '') !== '' ? (float)$_POST['haat']               : null,
        'tx_power_watts'     => trim($_POST['tx_power_watts']     ?? '') !== '' ? (float)$_POST['tx_power_watts']     : null,
        'feedline_loss_db'   => trim($_POST['feedline_loss_db']   ?? '') !== '' ? (float)$_POST['feedline_loss_db']   : null,
        'antenna_gain_dbd'   => trim($_POST['antenna_gain_dbd']   ?? '') !== '' ? (float)$_POST['antenna_gain_dbd']   : null,
        'erp_watts'          => trim($_POST['erp_watts']          ?? '') !== '' ? (float)$_POST['erp_watts']          : null,
        'tone_type'          => trim($_POST['tone_type']          ?? 'CARRIER'),
        'pl_tone'            => trim($_POST['pl_tone']            ?? '') !== '' ? (float)$_POST['pl_tone'] : null,
        'dcs_code'           => trim($_POST['dcs_code']           ?? '') ?: null,
        'dmr_color_code'     => trim($_POST['dmr_color_code']     ?? '') !== '' ? (int)$_POST['dmr_color_code'] : null,
        'backup_power'       => isset($_POST['backup_power'])     ? 1 : 0,
        'feature_skywarn'    => isset($_POST['feature_skywarn'])  ? 1 : 0,
        'feature_linked'     => isset($_POST['feature_linked'])   ? 1 : 0,
        'feature_allstar'    => isset($_POST['feature_allstar'])  ? 1 : 0,
        'feature_echolink'   => isset($_POST['feature_echolink']) ? 1 : 0,
        'feature_autopatch'  => isset($_POST['feature_autopatch'])? 1 : 0,
        'notes'              => trim($_POST['notes']              ?? ''),
    ];

    // Determine district from county name (most reliable)
    // ORSI District map by Oklahoma county
    $county_district = [
        // NE District
        'CRAIG'=>'NE','DELAWARE'=>'NE','MAYES'=>'NE','NOWATA'=>'NE',
        'OTTAWA'=>'NE','ROGERS'=>'NE','WASHINGTON'=>'NE','OSAGE'=>'NE',
        'PAYNE'=>'NE','PAWNEE'=>'NE','NOBLE'=>'NE','KAY'=>'NE',
        'OKMULGEE'=>'NE','MUSKOGEE'=>'NE','CHEROKEE'=>'NE','ADAIR'=>'NE',
        // TUL District
        'TULSA'=>'TUL','WAGONER'=>'TUL','CREEK'=>'TUL',
        // SE District
        'SEQUOYAH'=>'SE','HASKELL'=>'SE','LEFLORE'=>'SE','LATIMER'=>'SE',
        'PITTSBURG'=>'SE','COAL'=>'SE','ATOKA'=>'SE','PUSHMATAHA'=>'SE',
        'CHOCTAW'=>'SE','MCCURTAIN'=>'SE','BRYAN'=>'SE','MARSHALL'=>'SE',
        'JOHNSTON'=>'SE','PONTOTOC'=>'SE','HUGHES'=>'SE','MCINTOSH'=>'SE',
        'OKFUSKEE'=>'SE',
        // OKC District
        'OKLAHOMA'=>'OKC','CLEVELAND'=>'OKC','MCCLAIN'=>'OKC',
        'POTTAWATOMIE'=>'OKC','LINCOLN'=>'OKC','LOGAN'=>'OKC',
        'CANADIAN'=>'OKC','GARVIN'=>'OKC','MURRAY'=>'OKC',
        'CARTER'=>'OKC','LOVE'=>'OKC','STEPHENS'=>'OKC',
        // SW District
        'COMANCHE'=>'SW','TILLMAN'=>'SW','COTTON'=>'SW','JEFFERSON'=>'SW',
        'CADDO'=>'SW','WASHITA'=>'SW','KIOWA'=>'SW','GREER'=>'SW','GRADY'=>'SW',
        'HARMON'=>'SW','JACKSON'=>'SW','BECKHAM'=>'SW','CUSTER'=>'SW',
        'DEWEY'=>'SW','ROGER MILLS'=>'SW',
        // NW District
        'ALFALFA'=>'NW','GRANT'=>'NW','GARFIELD'=>'NW','MAJOR'=>'NW',
        'KINGFISHER'=>'NW','BLAINE'=>'NW','WOODWARD'=>'NW','ELLIS'=>'NW',
        'HARPER'=>'NW','WOODS'=>'NW','BEAVER'=>'NW','CIMARRON'=>'NW',
        'TEXAS'=>'NW',
    ];

    // Try county first
    if (!empty($d['county'])) {
        $county_upper = strtoupper(trim($d['county']));
        if (isset($county_district[$county_upper])) {
            $d['district'] = $county_district[$county_upper];
        }
    }

    // Fall back to coordinates if county not found
    if (empty($d['district']) && $d['latitude'] && $d['longitude']) {
        $lat = $d['latitude']; $lon = $d['longitude'];
        // Better coordinate-based fallback using ORSI district centers
        $districts = [
            'NE'  => [36.2, -95.5],
            'TUL' => [36.1, -95.9],
            'SE'  => [34.5, -95.2],
            'OKC' => [35.4, -97.5],
            'SW'  => [34.8, -98.8],
            'NW'  => [36.5, -98.5],
        ];
        $closest = 'OKC'; $min_dist = PHP_INT_MAX;
        foreach ($districts as $dist => $center) {
            $d2 = sqrt(pow($lat - $center[0], 2) + pow($lon - $center[1], 2));
            if ($d2 < $min_dist) { $min_dist = $d2; $closest = $dist; }
        }
        $d['district'] = $closest;
    }

    // Validate
    if (!$d['applicant_name'])     $errors[] = 'Name is required.';
    if (!$d['applicant_callsign']) $errors[] = 'Callsign is required.';
    if (!$d['applicant_email'] || !filter_var($d['applicant_email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Valid email address is required.';
    if (!$d['req_band'])           $errors[] = 'Please select a frequency band.';
    if (!$d['city'] && !$d['latitude']) $errors[] = 'Please provide either GPS coordinates or a city name.';
    // Validate preferred frequency is within valid OUTPUT range for selected band
    if ($d['preferred_freq'] && $d['req_band'] && isset($bands[$d['req_band']])) {
        $band_def = $bands[$d['req_band']];
        $pfreq = (float)$d['preferred_freq'];
        if ($pfreq < $band_def['low'] || $pfreq > $band_def['high']) {
            // Check if it looks like they entered the INPUT frequency instead
            $input_low  = round($band_def['low']  + $band_def['offset'], 4);
            $input_high = round($band_def['high'] + $band_def['offset'], 4);
            $in_range = [$input_low, $input_high];
            sort($in_range);
            if ($pfreq >= $in_range[0] && $pfreq <= $in_range[1]) {
                $errors[] = number_format($pfreq,4).' MHz is an INPUT frequency for the '.$band_def['name'].' band, not a valid output. '
                    .'Valid output range is '.number_format($band_def['low'],3).' – '.number_format($band_def['high'],3).' MHz. '
                    .'Did you mean output '.number_format($pfreq - $band_def['offset'],4).' MHz?';
            } else {
                $errors[] = number_format($pfreq,4).' MHz is outside the valid output range for '.$band_def['name']
                    .' ('.number_format($band_def['low'],3).' – '.number_format($band_def['high'],3).' MHz).';
            }
        }
    }
    // Validate coordinates are within reasonable range for Oklahoma
    if ($d['latitude'] && $d['longitude']) {
        $lat = (float)$d['latitude'];
        $lon = (float)$d['longitude'];
        if ($lat < 33.0 || $lat > 37.5) $errors[] = 'Latitude appears out of range for Oklahoma (should be between 33.0 and 37.5). Got: '.$lat;
        if ($lon > -94.0 || $lon < -103.5) {
            if ($lon > 94.0 && $lon < 103.5) {
                $errors[] = 'Longitude must be negative for Oklahoma (e.g. -97.464, not 97.464). Got: '.$lon;
            } else {
                $errors[] = 'Longitude appears out of range for Oklahoma (should be between -103.5 and -94.0). Got: '.$lon;
            }
        }
    }

    // Simple spam check
    if (!empty($_POST['website'])) { $errors[] = 'Bot detected.'; }

    if (!$d['tx_power_watts'])    $errors[] = 'TX Power Output is required.';
    if (!$d['antenna_height_agl']) $errors[] = 'Tower/Antenna Height AGL is required.';
    if (!$d['haat'])               $errors[] = 'HAAT (Height Above Average Terrain) is required.';
    if (!$d['feedline_loss_db'] && $d['feedline_loss_db'] !== 0.0) $errors[] = 'Feedline Loss is required.';
    if (!$d['antenna_gain_dbd'] && $d['antenna_gain_dbd'] !== 0.0) $errors[] = 'Antenna Gain is required.';
    if (!$errors) {
        $cols = array_keys($d);
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare("INSERT INTO coordination_requests (" . implode(',', $cols) . ") VALUES ($ph)")->execute(array_values($d));
        $req_id = $db->lastInsertId();

        // Send email to district coordinator
        $dist = $d['district'] ?? 'OKC';
        $coord_email = get_coordinator_email($dist);
        $band_name = $bands[$d['req_band']]['name'] ?? $d['req_band'];
        $subject = "New Repeater Coordination Request #{$req_id} - {$d['applicant_callsign']} - {$band_name}";
        $body  = "A new repeater coordination request has been submitted.\n\n";
        $body .= "Request #: {$req_id}\n";
        $body .= "Applicant: {$d['applicant_name']} ({$d['applicant_callsign']})\n";
        $body .= "Email:     {$d['applicant_email']}\n";
        $body .= "Phone:     " . ($d['applicant_phone'] ?: 'N/A') . "\n";
        $body .= "Sponsor:   " . ($d['sponsor'] ?: 'N/A') . "\n";
        $body .= "Band:      {$band_name}\n";
        $body .= "Type:      {$d['req_type']}\n";
        $body .= "Location:  {$d['city']}, {$d['county']}\n";
        if ($d['suggested_freq']) $body .= "Suggested Freq: {$d['suggested_freq']} MHz\n";
        if ($d['preferred_freq']) $body .= "Preferred Freq: {$d['preferred_freq']} MHz\n";
        $body .= "\nView and process this request:\n";
        $body .= "https://w5dro.com/repeater_coord/admin/requests.php?id={$req_id}\n";

        orsi_mail($coord_email, $subject, $body, "".MAIL_FROM."\r\nReply-To: {$d['applicant_email']}");

        // Confirm email to applicant
        $confirm_body  = "Thank you for submitting a repeater coordination request.\n\n";
        $confirm_body .= "Request #: {$req_id}\n";
        $confirm_body .= "Band: {$band_name}\n";
        if ($d['suggested_freq']) $confirm_body .= "Suggested Output Frequency: {$d['suggested_freq']} MHz\n";
        $confirm_body .= "\nYour request has been forwarded to the District {$dist} coordinator.\n";
        $confirm_body .= "You will be contacted when your request has been reviewed.\n\n";
        $confirm_body .= "73,\nOklahoma Repeater Society Coordination Team\n";
        $confirm_body .= "https://oklahomarepeatersociety.org\n";

        orsi_mail($d["applicant_email"], "Coordination Request #{$req_id} Received - ORSI", $confirm_body, MAIL_FROM);

        $success = true;
    }
}

$page_title = 'Request Repeater Coordination';
include __DIR__ . '/includes/header.php';
?>

<div class="page-title"><i class="fa fa-tower-broadcast"></i> Request Repeater Coordination</div>

<?php if ($success): ?>
<div class="alert alert-success" style="font-size:1rem;padding:20px">
  <i class="fa fa-circle-check" style="font-size:1.5rem"></i>
  <div>
    <strong>Request Submitted Successfully!</strong><br>
    Your coordination request has been forwarded to your district coordinator.
    You will receive a confirmation email shortly. Please save your request number for reference.
  </div>
</div>
<?php else: ?>

<div class="alert alert-info">
  <i class="fa fa-circle-info"></i>
  Fill out this form to begin the repeater coordination process with the Oklahoma Repeater Society.
  After submitting, your district coordinator will review your request and contact you.
  Fields marked <strong>*</strong> are required.
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= h($e) ?></div>
<?php endforeach; ?>

<form method="post" id="reqForm">
<!-- Honeypot spam field -->
<input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

<!-- Applicant Info -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-user"></i> Applicant Information</div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" name="applicant_name" id="applicant_name" value="<?= h($_POST['applicant_name'] ?? '') ?>" required maxlength="100">
      </div>
      <div class="form-group">
        <label>Callsign *</label>
        <div id="fcc_msg" style="display:none;padding:6px 10px;border-radius:4px;font-size:.82rem;margin-bottom:8px"></div>
        <div style="display:flex;gap:8px;align-items:center">
        <input type="text" name="applicant_callsign" id="applicant_callsign" value="<?= h($_POST['applicant_callsign'] ?? '') ?>" required maxlength="20" style="text-transform:uppercase">
        <button type="button" id="fcc_lookup_btn" onclick="lookupCallsign(document.getElementById('applicant_callsign').value.toUpperCase(),'applicant')" class="btn btn-secondary btn-sm"><i class="fa fa-search"></i> FCC Lookup</button>
        </div>
      </div>
      <div class="form-group">
        <label>Trustee Callsign <span style="color:var(--muted);font-size:.8rem">(if different from applicant)</span></label>
        <input type="text" name="trustee_callsign" value="<?= h($_POST['trustee_callsign'] ?? '') ?>" maxlength="20" style="text-transform:uppercase" placeholder="Leave blank if same as applicant">
      </div>
      <div class="form-group">
        <label>Email Address *</label>
        <input type="email" name="applicant_email" id="applicant_email" value="<?= h($_POST['applicant_email'] ?? '') ?>" required maxlength="150">
      </div>
      <div class="form-group">
        <label>Phone</label>
        <input type="tel" name="applicant_phone" id="applicant_phone" value="<?= h($_POST['applicant_phone'] ?? '') ?>" maxlength="20" placeholder="405-555-1234">
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Mailing Address</label>
        <input type="text" name="applicant_address" id="applicant_address" value="<?= h($_POST['applicant_address'] ?? '') ?>" maxlength="150" placeholder="Street address">
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="applicant_city" id="applicant_city" value="<?= h($_POST['applicant_city'] ?? '') ?>" maxlength="60">
      </div>
      <div class="form-group">
        <label>State</label>
        <input type="text" name="applicant_state" id="applicant_state" value="<?= h($_POST['applicant_state'] ?? '') ?>" maxlength="2" style="text-transform:uppercase" placeholder="OK">
      </div>
      <div class="form-group">
        <label>ZIP Code</label>
        <input type="text" name="applicant_zip" id="applicant_zip" value="<?= h($_POST['applicant_zip'] ?? '') ?>" maxlength="15">
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Club / Sponsor Name</label>
        <input type="text" name="sponsor" value="<?= h($_POST['sponsor'] ?? '') ?>" maxlength="100" placeholder="e.g. Tulsa Amateur Radio Club">
      </div>
    </div>
  </div>
</div>

<!-- Repeater Info -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-broadcast-tower"></i> Repeater Details</div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Repeater Type *</label>
        <select name="req_type" id="req_type">
          <?php foreach (['REPEATER','DMR','D-STAR','FUSION','P-25','ATV'] as $t): ?>
          <option value="<?= h($t) ?>" <?= ($_POST['req_type']??'REPEATER')===$t?'selected':'' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Frequency Band *</label>
        <select name="req_band" id="req_band" onchange="onBandChange()">
          <option value="">- Select Band -</option>
          <?php foreach ($bands as $key => $b): ?>
          <option value="<?= h($key) ?>" <?= ($_POST['req_band']??'')===$key?'selected':'' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Preferred Output Frequency (MHz) <span class="text-muted" style="font-size:.75rem">optional</span></label>
        <input type="number" name="preferred_freq" value="<?= h($_POST['preferred_freq'] ?? '') ?>" step="0.0001" placeholder="e.g. 146.940 - leave blank if no preference">
        <small style="color:var(--muted)">If you have a specific frequency in mind, enter it here. The coordinator will evaluate availability.</small>
      </div>
      <input type="hidden" name="suggested_freq" id="suggested_freq_input">
    </div>
  </div>
</div>

<!-- Location -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-map-marker-alt"></i> Proposed Site Location</div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Latitude (decimal) *</label>
        <input type="number" name="latitude" onblur="validateOKCoords(); checkRequestedFreq();" id="latitude" value="<?= h($_POST['latitude'] ?? '') ?>" step="0.000001" placeholder="35.4675" onchange="onCoordsChange()">
      </div>
      <div class="form-group">
        <label>Longitude (decimal) *</label>
        <input type="number" name="longitude" onblur="validateOKCoords(); checkRequestedFreq();" id="longitude" value="<?= h($_POST['longitude'] ?? '') ?>" step="0.000001" placeholder="-97.5164" onchange="onCoordsChange()">
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="city" value="<?= h($_POST['city'] ?? '') ?>" maxlength="60">
      </div>
      <div class="form-group">
        <label>County</label>
        <input type="text" name="county" value="<?= h($_POST['county'] ?? '') ?>" maxlength="50" style="text-transform:uppercase">
      </div>
    </div>
    <div class="alert alert-info" style="margin-top:12px;font-size:.82rem">
      <i class="fa fa-lightbulb"></i>
      Use <a href="https://www.google.com/maps" target="_blank">Google Maps</a> to find decimal coordinates:
      right-click your site location and the coordinates will appear at the top of the menu.
    </div>
  </div>
</div>

<!-- RF Parameters -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-signal"></i> RF Parameters</div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Antenna Height AGL (ft)</label>
        <input type="number" name="antenna_height_agl" value="<?= h($_POST['antenna_height_agl'] ?? '') ?>" step="0.1" placeholder="e.g. 120" required onchange="calcERP()">
      </div>
      <div class="form-group">
        <label>HAAT (ft)</label>
        <input type="number" name="haat" value="<?= h($_POST['haat'] ?? '') ?>" step="0.1" placeholder="e.g. 340" required>
      </div>
      <div class="form-group">
        <label>TX Power Output (watts)</label>
        <input type="number" name="tx_power_watts" id="txpwr" value="<?= h($_POST['tx_power_watts'] ?? '') ?>" step="0.1" placeholder="e.g. 50" required onchange="calcERP()">
      </div>
      <div class="form-group">
        <label>ERP (watts) <span class="text-muted" style="font-size:.75rem">auto-calculated</span></label>
        <input type="number" name="erp_watts" id="erp" value="<?= h($_POST['erp_watts'] ?? '') ?>" step="0.001" placeholder="Auto-calculated">
      </div>
      <div class="form-group">
        <label>Feedline Loss (dB) <span style="color:red">*</span></label>
        <input type="number" name="feedline_loss_db" id="feedline" value="<?= h($_POST['feedline_loss_db'] ?? '') ?>" step="0.1" placeholder="e.g. 2.5" required onchange="calcERP()">
        <small style="color:var(--muted)">Total feedline/connector loss in dB</small>
      </div>
      <div class="form-group">
        <label>Antenna Gain (dBd) <span style="color:red">*</span></label>
        <input type="number" name="antenna_gain_dbd" id="antgain" value="<?= h($_POST['antenna_gain_dbd'] ?? '') ?>" step="0.1" placeholder="e.g. 6.0" required onchange="calcERP()">
        <small style="color:var(--muted)">Antenna gain in dBd (0 for dipole)</small>
      </div>
    </div>
  </div>
</div>

<!-- Access / Tone -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-key"></i> Access Information</div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Tone Type</label>
        <select name="tone_type" id="req_tone_type" onchange="updateReqToneFields()">
          <option value="CARRIER">Carrier Squelch (No Tone)</option>
          <option value="CTCSS">CTCSS / PL Tone</option>
          <option value="DCS">DCS</option>
          <option value="DMR">DMR Color Code</option>
          <option value="FUSION">Fusion / C4FM</option>
          <option value="P-25">P-25</option>
          <option value="D-STAR">D-STAR</option>
        </select>
      </div>
      <div class="form-group" id="req_ctcss_field" style="display:none">
        <label>CTCSS / PL Tone (Hz)</label>
        <select name="pl_tone">
          <option value="">- Select Tone -</option>
          <?php foreach (['67.0','69.3','71.9','74.4','77.0','79.7','82.5','85.4','88.5','91.5','94.8','97.4','100.0','103.5','107.2','110.9','114.8','118.8','123.0','127.3','131.8','136.5','141.3','146.2','151.4','156.7','162.2','167.9','173.8','179.9','186.2','192.8','203.5','210.7','218.1','225.7','233.6','241.8','250.3','254.1'] as $t): ?>
          <option value="<?= $t ?>"><?= $t ?> Hz</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="req_dcs_field" style="display:none">
        <label>DCS Code</label>
        <select name="dcs_code">
          <option value="">- Select Code -</option>
          <?php foreach (['023','025','026','031','032','036','043','047','051','053','054','065','071','072','073','074','114','115','116','122','125','131','132','134','143','145','152','155','156','162','165','172','174','205','212','223','225','226','243','244','245','246','251','252','255','261','263','265','266','271','274','306','311','315','325','331','332','343','346','351','356','364','365','371','411','412','413','423','431','432','445','446','452','454','455','462','464','465','466','503','506','516','523','526','532','546','565','606','612','624','627','631','632','654','662','664','703','712','723','731','732','734','743','754'] as $c): ?>
          <option value="<?= $c ?>">D<?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="req_dmr_field" style="display:none">
        <label>DMR Color Code</label>
        <select name="dmr_color_code">
          <option value="">- Select Color Code -</option>
          <?php for ($cc=0; $cc<=15; $cc++): ?>
          <option value="<?= $cc ?>" <?= ($_POST['dmr_color_code']??'')==$cc?'selected':'' ?>>CC<?= $cc ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- Planned Features -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-sliders"></i> Planned Features</div>
  <div class="card-body">
    <div style="display:flex;flex-wrap:wrap;gap:20px">
      <label class="form-check"><input type="checkbox" name="feature_skywarn"   value="1" <?= !empty($_POST['feature_skywarn'])  ?'checked':'' ?>> SKYWARN</label>
      <label class="form-check"><input type="checkbox" name="feature_linked"    value="1" <?= !empty($_POST['feature_linked'])   ?'checked':'' ?>> Linked</label>
      <label class="form-check"><input type="checkbox" name="feature_allstar"   value="1" <?= !empty($_POST['feature_allstar'])  ?'checked':'' ?>> AllStar</label>
      <label class="form-check"><input type="checkbox" name="feature_echolink"  value="1" <?= !empty($_POST['feature_echolink']) ?'checked':'' ?>> EchoLink</label>
      <label class="form-check"><input type="checkbox" name="feature_autopatch" value="1" <?= !empty($_POST['feature_autopatch'])?'checked':'' ?>> Auto-Patch</label>
      <label class="form-check"><input type="checkbox" name="backup_power" value="1" <?= !empty($_POST['backup_power'])?'checked':'' ?>> Backup Power</label>
    </div>
  </div>
</div>

<!-- Notes -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><i class="fa fa-sticky-note"></i> Additional Notes</div>
  <div class="card-body">
    <div class="form-group">
      <textarea name="notes" rows="4" style="width:100%;resize:vertical" placeholder="Any additional information about your repeater project…"><?= h($_POST['notes'] ?? '') ?></textarea>
    </div>
  </div>
</div>

<div class="form-actions">
  <button type="submit" class="btn btn-success" style="font-size:1rem;padding:10px 24px">
    <i class="fa fa-paper-plane"></i> Submit Coordination Request
  </button>
  <span class="text-muted" style="font-size:.82rem;align-self:center">You will receive a confirmation email after submitting.</span>
</div>

</form>

<script>
const BASE = '<?= BASE_PATH ?>';

function calcERP() {
    var pwr  = parseFloat(document.getElementById('txpwr')?.value) || 0;
    var loss = parseFloat(document.getElementById('feedline')?.value) || 0;
    var gain = parseFloat(document.getElementById('antgain')?.value) || 0;
    if (pwr > 0) {
        var pwr_dbw  = 10 * Math.log10(pwr);
        var erp_dbw  = pwr_dbw - loss + gain;
        var erp_w    = Math.pow(10, erp_dbw / 10);
        var erpEl = document.getElementById('erp');
        if (erpEl) erpEl.value = erp_w.toFixed(3);
    }
}

function onCoordsChange() {
  const band = document.getElementById('req_band').value;
  if (band) getSuggestion(band);
}

function onBandChange() {
  const lat = document.getElementById('latitude').value;
  const lon = document.getElementById('longitude').value;
  if (lat && lon) getSuggestion(document.getElementById('req_band').value);
}

function updateReqToneFields() {
  const t = document.getElementById('req_tone_type').value;
  document.getElementById('req_ctcss_field').style.display = t === 'CTCSS' ? '' : 'none';
  document.getElementById('req_dcs_field').style.display   = t === 'DCS'   ? '' : 'none';
  document.getElementById('req_dmr_field').style.display   = t === 'DMR'   ? '' : 'none';
}

function getSuggestion(band) {
  const lat = document.getElementById('latitude').value;
  const lon = document.getElementById('longitude').value;
  if (!lat || !lon || !band) return;

  const el = document.getElementById('freq_suggestion');
  el.textContent = 'Calculating best available frequency…';
  el.style.background = '#fffbeb';
  el.style.borderColor = '#fcd34d';

  fetch(BASE + '/request.php?suggest_freq=1&band=' + encodeURIComponent(band) +
        '&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon))
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        el.textContent = 'Band may be full in this area: ' + data.error;
        el.style.background = '#fef2f2';
        el.style.borderColor = '#fca5a5';
        return;
      }
      el.innerHTML = '<strong>' + data.output_freq.toFixed(4) + ' MHz output</strong> / ' +
        data.input_freq.toFixed(4) + ' MHz input<br>' +
        '<small style="color:var(--muted)">' + data.score_miles + ' - ' + data.band_name + '</small>';
      el.style.background = '#f0fdf4';
      el.style.borderColor = '#86efac';
      document.getElementById('suggested_freq_input').value = data.output_freq;
    })
    .catch(() => {
      el.textContent = 'Could not reach server. Please try again.';
      el.style.background = '#fef2f2';
    });
}

function validateOKCoords() {
  const lat = parseFloat(document.getElementById('latitude')?.value);
  const lon = parseFloat(document.getElementById('longitude')?.value);
  const latEl = document.getElementById('lat_warning');
  const lonEl = document.getElementById('lon_warning');
  if (latEl) latEl.remove();
  if (lonEl) lonEl.remove();
  const latInput = document.getElementById('latitude');
  const lonInput = document.getElementById('longitude');
  if (latInput && !isNaN(lat) && (lat < 33.0 || lat > 37.5)) {
    const warn = document.createElement('div');
    warn.id = 'lat_warning';
    warn.style = 'color:#dc2626;font-size:.82rem;margin-top:4px';
    warn.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Latitude out of range for Oklahoma (33.0 to 37.5)';
    latInput.parentNode.appendChild(warn);
  }
  if (lonInput && !isNaN(lon)) {
    if (lon > 0 && lon > 94.0 && lon < 103.5) {
      const warn = document.createElement('div');
      warn.id = 'lon_warning';
      warn.style = 'color:#dc2626;font-size:.82rem;margin-top:4px';
      warn.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Longitude must be negative for Oklahoma (e.g. -97.464, not 97.464)';
      lonInput.parentNode.appendChild(warn);
      lonInput.style.borderColor = '#dc2626';
    } else if (lon > -94.0 || lon < -103.5) {
      const warn = document.createElement('div');
      warn.id = 'lon_warning';
      warn.style = 'color:#dc2626;font-size:.82rem;margin-top:4px';
      warn.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Longitude out of range for Oklahoma (-103.5 to -94.0)';
      lonInput.parentNode.appendChild(warn);
    }
  }
}

let freqCheckTimer = null;
function checkRequestedFreq() {
  const freq = document.getElementById('req_output_freq')?.value;
  const lat  = document.getElementById('latitude').value;
  const lon  = document.getElementById('longitude').value;
  const el   = document.getElementById('freq_check_result');
  if (!el) return;
  if (!freq || freq.length < 5) { el.innerHTML = ''; return; }
  if (!lat || !lon) { el.innerHTML = '<div style="background:#fffbeb;border:1px solid #fcd34d;padding:8px 12px;border-radius:4px;font-size:.85rem;color:#92400e"><i class="fa fa-triangle-exclamation"></i> Enter latitude/longitude first to check for conflicts.</div>'; return; }

  clearTimeout(freqCheckTimer);
  el.innerHTML = '<div style="color:#aaa;font-size:.85rem"><i class="fa fa-spinner fa-spin"></i> Checking frequency...</div>';

  freqCheckTimer = setTimeout(() => {
    fetch(BASE + '/request.php?check_freq=1&freq=' + encodeURIComponent(freq) +
          '&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon))
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          el.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;padding:8px 12px;border-radius:4px;font-size:.85rem;color:#b91c1c"><i class="fa fa-times-circle"></i> ' + data.error + '</div>';
          return;
        }
        if (data.clear) {
          el.innerHTML = '<div style="background:#f0fdf4;border:1px solid #86efac;padding:8px 12px;border-radius:4px;font-size:.85rem;color:#15803d"><i class="fa fa-check-circle"></i> <strong>' + parseFloat(freq).toFixed(4) + ' MHz</strong> appears clear of conflicts in your area. Co-channel minimum: ' + data.co_min + ' mi, Adjacent: ' + data.adj_min + ' mi.</div>';
        } else {
          let html = '<div style="background:#fef2f2;border:1px solid #fca5a5;padding:10px 14px;border-radius:4px;font-size:.85rem;color:#b91c1c;margin-bottom:4px"><i class="fa fa-triangle-exclamation"></i> <strong>Potential conflicts found for ' + parseFloat(freq).toFixed(4) + ' MHz:</strong></div>';
          html += '<table style="width:100%;font-size:.82rem;border-collapse:collapse;margin-top:4px">';
          html += '<thead><tr style="background:#fef2f2"><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #fca5a5">Callsign</th><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #fca5a5">Freq</th><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #fca5a5">City</th><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #fca5a5">Distance</th><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #fca5a5">Type</th><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #fca5a5">Min Required</th></tr></thead><tbody>';
          data.conflicts.forEach(c => {
            const typeLabel = c.type === 'co_channel' ? '<span style="color:#dc2626;font-weight:bold">Co-channel</span>' : '<span style="color:#d97706;font-weight:bold">Adjacent (' + c.diff_khz + ' kHz)</span>';
            const minReq = c.type === 'co_channel' ? c.co_min + ' mi' : c.adj_min + ' mi';
            html += '<tr style="border-bottom:1px solid #fee2e2"><td style="padding:6px 8px"><strong>' + c.callsign + '</strong></td><td style="padding:6px 8px">' + c.freq + '</td><td style="padding:6px 8px">' + c.city + '</td><td style="padding:6px 8px;color:#dc2626"><strong>' + c.distance + ' mi</strong></td><td style="padding:6px 8px">' + typeLabel + '</td><td style="padding:6px 8px">' + minReq + '</td></tr>';
          });
          html += '</tbody></table>';
          html += '<div style="background:#fffbeb;border:1px solid #fcd34d;padding:8px 12px;border-radius:4px;font-size:.82rem;color:#92400e;margin-top:6px"><i class="fa fa-info-circle"></i> You may still submit this request. A coordinator will review conflicts and make the final determination. Consider using the <strong>Suggest Frequency</strong> tool to find a clear frequency.</div>';
          el.innerHTML = html;
        }
      })
      .catch(() => {
        el.innerHTML = '';
      });
  }, 800);
}
</script>

<?php endif; ?>
<script>
// Auto-fill address from FCC database when callsign is entered
function lookupCallsign(callsign, prefix) {
    if (!callsign || callsign.length < 3) return;
    var btn = document.getElementById('fcc_lookup_btn');
    if (btn) { btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Looking up...'; btn.disabled = true; }
    fetch('<?= BASE_PATH ?>/api/index.php?path=fcc_lookup&callsign=' + encodeURIComponent(callsign))
        .then(r => r.json())
        .then(data => {
            if (btn) { btn.innerHTML = '<i class="fa fa-search"></i> FCC Lookup'; btn.disabled = false; }
            if (data && data.data && data.data.found) {
                var d = data.data;
                if (d.name) { var el = document.getElementById(prefix + '_name'); if (el && !el.value) el.value = d.name; }
                if (d.email) { var el = document.getElementById(prefix + '_email'); if (el && !el.value) el.value = d.email; }
                if (d.address) { var el = document.getElementById(prefix + '_address'); if (el) el.value = d.address; }
                if (d.city) { var el = document.getElementById(prefix + '_city'); if (el) el.value = d.city; }
                if (d.state) { var el = document.getElementById(prefix + '_state'); if (el) el.value = d.state; }
                if (d.zip) { var el = document.getElementById(prefix + '_zip'); if (el) el.value = d.zip; }
                showMsg('FCC data loaded for ' + callsign, 'success');
            } else {
                showMsg('Callsign ' + callsign + ' not found in FCC database', 'warning');
            }
        }).catch(() => {
            if (btn) { btn.innerHTML = '<i class="fa fa-search"></i> FCC Lookup'; btn.disabled = false; }
        });
}

function showMsg(msg, type) {
    var div = document.getElementById('fcc_msg');
    if (!div) return;
    div.innerHTML = msg;
    div.style.display = 'block';
    div.style.background = type === 'success' ? '#f0fdf4' : '#fffbeb';
    div.style.color = type === 'success' ? '#15803d' : '#92400e';
    div.style.border = '1px solid ' + (type === 'success' ? '#86efac' : '#fcd34d');
    setTimeout(() => div.style.display = 'none', 4000);
}

document.addEventListener('DOMContentLoaded', function() {
    var csField = document.getElementById('applicant_callsign');
    if (csField) {
        csField.addEventListener('blur', function() {
            if (this.value.trim().length >= 3) {
                lookupCallsign(this.value.trim().toUpperCase(), 'applicant');
            }
        });
    }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
