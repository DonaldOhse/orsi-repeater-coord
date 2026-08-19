<?php
require_once __DIR__ . '/../includes/config.php';

// CORS headers for mobile app
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$db = get_db();

// Router
$path   = trim($_GET['path'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];
$parts  = explode('/', $path);

// Rate limiting - 60 requests per minute per IP
$ip      = $_SERVER['REMOTE_ADDR'];
$rk      = 'rate_' . md5($ip);
$rf      = sys_get_temp_dir() . '/' . $rk;
$now     = time();
$window  = 60;
$limit   = 60;
$hits    = [];
if (file_exists($rf)) $hits = array_filter(json_decode(file_get_contents($rf), true), fn($t) => $t > $now - $window);
$hits[] = $now;
file_put_contents($rf, json_encode(array_values($hits)));
if (count($hits) > $limit) { http_response_code(429); echo json_encode(['error' => 'Rate limit exceeded']); exit; }

function api_error($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function api_ok($data, $meta = []) { echo json_encode(array_merge(['success' => true], $meta, ['data' => $data])); exit; }

// ── GET /repeaters ─────────────────────────────────────────
if ($method === 'GET' && $parts[0] === 'repeaters' && !isset($parts[1])) {
    $where  = ["r.private = 0", "r.status NOT IN ('DEAD','DECOORDINATED')"];
    $params = [];

    if (!empty($_GET['band'])) {
        $band_ranges = [
            '10m'   => [29.0,   30.0],
            '6m'    => [50.0,   54.0],
            '2m'    => [144.0, 148.0],
            '1.25m' => [222.0, 225.0],
            '70cm'  => [420.0, 450.0],
            '33cm'  => [900.0, 930.0],
            '23cm'  => [1240.0,1300.0],
        ];
        $b = $_GET['band'];
        if (isset($band_ranges[$b])) {
            $where[]  = "r.output_freq BETWEEN ? AND ?";
            $params[] = $band_ranges[$b][0];
            $params[] = $band_ranges[$b][1];
        }
    }

    if (!empty($_GET['status']))   { $where[] = "r.status = ?";   $params[] = $_GET['status']; }
    if (!empty($_GET['county']))   { $where[] = "r.county = ?";   $params[] = strtoupper($_GET['county']); }
    if (!empty($_GET['search'])) {
        $where[]  = "(r.callsign LIKE ? OR r.city LIKE ? OR r.county LIKE ? OR r.trustee LIKE ?)";
        $s = '%' . $_GET['search'] . '%';
        $params = array_merge($params, [$s,$s,$s,$s]);
    }

    // Nearby - sort by distance from lat/lon
    $lat = (float)($_GET['lat'] ?? 0);
    $lon = (float)($_GET['lon'] ?? 0);
    $nearby_select = '';
    $nearby_order  = 'r.output_freq ASC';
    if ($lat && $lon) {
        $nearby_select = ", ROUND((3959 * acos(cos(radians({$lat})) * cos(radians(r.latitude)) * cos(radians(r.longitude) - radians({$lon})) + sin(radians({$lat})) * sin(radians(r.latitude)))), 1) AS distance_mi";
        $nearby_order  = 'distance_mi ASC';
        $where[]       = "r.latitude IS NOT NULL AND r.longitude IS NOT NULL";
    }

    $limit  = min((int)($_GET['limit'] ?? 100), 500);
    $offset = (int)($_GET['offset'] ?? 0);
    $sql    = "SELECT r.id, r.callsign, r.trustee, r.output_freq, r.input_freq,
                      r.pl_tone, r.tone_type, r.dcs_code, r.status, r.type,
                      r.city, r.county, r.district, r.latitude, r.longitude,
                      r.haat, r.erp_watts, r.skywarn, r.linked, r.allstar,
                      r.echolink, r.autopatch, r.open_system, r.backup_power,
                      r.mixed_mode, r.mixed_mode_types, r.date_coordinated
                      {$nearby_select}
               FROM repeaters r
               WHERE " . implode(' AND ', $where) . "
               ORDER BY {$nearby_order}
               LIMIT {$limit} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Total count
    $count_sql = "SELECT COUNT(*) FROM repeaters r WHERE r.archived_at IS NULL AND " . implode(' AND ', $where);
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    api_ok($rows, ['total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

// ── GET /repeaters/{id} ────────────────────────────────────
if ($method === 'GET' && $parts[0] === 'repeaters' && isset($parts[1]) && is_numeric($parts[1])) {
    $stmt = $db->prepare("SELECT * FROM repeaters WHERE archived_at IS NULL AND id=? AND private=0");
    $stmt->execute([(int)$parts[1]]);
    $r = $stmt->fetch();
    if (!$r) api_error(404, 'Repeater not found');

    // Remove sensitive internal fields
    unset($r['internal_notes'], $r['contact_address'], $r['contact_phone'],
          $r['contact_email'], $r['renewal_token'], $r['renewal_token_exp']);

    api_ok($r);
}

// ── GET /bands ─────────────────────────────────────────────
if ($method === 'GET' && $parts[0] === 'bands') {
    $rows = $db->query("SELECT
        CASE
            WHEN output_freq BETWEEN 29 AND 30     THEN '10m'
            WHEN output_freq BETWEEN 50 AND 54     THEN '6m'
            WHEN output_freq BETWEEN 144 AND 148   THEN '2m'
            WHEN output_freq BETWEEN 222 AND 225   THEN '1.25m'
            WHEN output_freq BETWEEN 420 AND 450   THEN '70cm'
            WHEN output_freq BETWEEN 900 AND 930   THEN '33cm'
            WHEN output_freq BETWEEN 1240 AND 1300 THEN '23cm'
            ELSE 'Other'
        END AS band,
        COUNT(*) AS count
        FROM repeaters
        WHERE private=0 AND status NOT IN ('DEAD','DECOORDINATED')
        GROUP BY band ORDER BY MIN(output_freq)")->fetchAll();
    api_ok($rows);
}

// ── GET /stats ─────────────────────────────────────────────
if ($method === 'GET' && $parts[0] === 'stats') {
    $stats = [
        'total'       => (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND private=0")->fetchColumn(),
        'operational' => (int)$db->query("SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL AND private=0 AND status='OPERATIONAL'")->fetchColumn(),
        'by_status'   => $db->query("SELECT status, COUNT(*) as count FROM repeaters WHERE archived_at IS NULL AND private=0 GROUP BY status ORDER BY count DESC")->fetchAll(),
        'by_band'     => [],
        'last_updated'=> date('Y-m-d H:i:s'),
    ];
    api_ok($stats);
}

// ── POST /update_request ───────────────────────────────────
if ($method === 'POST' && $parts[0] === 'update_request') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) $body = $_POST;

    $rid      = (int)($body['repeater_id'] ?? 0);
    $name     = trim($body['submitter_name']     ?? '');
    $call     = strtoupper(trim($body['submitter_callsign'] ?? ''));
    $rel      = trim($body['relationship']       ?? '');
    $email    = trim($body['contact_email']      ?? '');
    $changes  = trim($body['proposed_changes']   ?? '');

    if (!$rid || !$name || !$call || !$changes)
        api_error(400, 'Missing required fields: repeater_id, submitter_name, submitter_callsign, proposed_changes');

    // Verify repeater exists
    $rep = $db->prepare("SELECT id, callsign, district FROM repeaters WHERE archived_at IS NULL AND id=? AND private=0");
    $rep->execute([$rid]);
    $r = $rep->fetch();
    if (!$r) api_error(404, 'Repeater not found');

    $changes_json = json_encode(['description' => $changes]);

    $db->prepare("INSERT INTO update_requests
        (repeater_id, submitter_name, submitter_call, relationship, submitter_email, changes, change_summary)
        VALUES (?,?,?,?,?,?,?)")
       ->execute([$rid, $name, $call, $rel, $email, $changes_json, $changes]);

    $new_id = $db->lastInsertId();

    // Notify coordinators
    $all_emails = get_all_coordinator_emails('OKC');
    $subject = "Update Request: {$r['callsign']} from {$call}";
    $body_txt = "A repeater update request has been submitted via the ORSI mobile app.\n\n";
    $body_txt .= "Repeater:  {$r['callsign']}\n";
    $body_txt .= "From:      {$name} ({$call})\n";
    $body_txt .= "Changes:   {$changes}\n\n";
    $body_txt .= "Review: https://w5dro.com/repeater_coord/admin/update_requests.php\n\n73,\nORSI System\n";
    $headers = "".MAIL_FROM."\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    foreach ($all_emails as $em) mail($em, $subject, $body_txt, $headers);

    api_ok(['request_id' => $new_id], ['message' => 'Update request submitted successfully']);
}

// ── CONFIRM REPEATER ON AIR ─────────────────────────────────
if ($parts[0] === 'confirm' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $repeater_id = (int)($data['repeater_id'] ?? 0);
    $callsign    = strtoupper(trim($data['callsign'] ?? ''));
    $radio_type  = in_array($data['radio_type'] ?? '', ['HT','Mobile','Base']) ? $data['radio_type'] : 'Unknown';
    $signal      = $data['signal_report'] ?? null;
    $lat         = isset($data['latitude'])  ? (float)$data['latitude']  : null;
    $lon         = isset($data['longitude']) ? (float)$data['longitude'] : null;
    $notes       = isset($data['notes']) ? substr($data['notes'], 0, 200) : null;

    if (!$repeater_id || !$callsign)
        api_error(400, 'repeater_id and callsign are required');
    if (!preg_match('/^[A-Z0-9]{3,10}$/', $callsign))
        api_error(400, 'Invalid callsign format');

    $repeater = $db->prepare("SELECT id, callsign, status FROM repeaters WHERE archived_at IS NULL AND id=?");
    $repeater->execute([$repeater_id]);
    $rep = $repeater->fetch();
    if (!$rep) api_error(404, 'Repeater not found');

    // Check 24hr duplicate
    $recent = $db->prepare("SELECT id FROM repeater_confirmations WHERE repeater_id=? AND callsign=? AND heard_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $recent->execute([$repeater_id, $callsign]);
    if ($recent->fetch())
        api_ok(['status'=>'already_confirmed'], ['message'=>'You already confirmed this repeater in the last 24 hours']);

    // Insert
    $db->prepare("INSERT INTO repeater_confirmations (repeater_id, callsign, latitude, longitude, radio_type, signal_report, notes) VALUES (?,?,?,?,?,?,?)")
       ->execute([$repeater_id, $callsign, $lat, $lon, $radio_type, $signal, $notes]);

    // Count unique in last 12 months
    $count = (int)$db->prepare("SELECT COUNT(DISTINCT callsign) FROM repeater_confirmations WHERE repeater_id=? AND heard_at > DATE_SUB(NOW(), INTERVAL (SELECT COALESCE(setting_value,120) FROM system_settings WHERE setting_key='confirm_days') DAY)")
                     ->execute([$repeater_id]) ? $db->query("SELECT COUNT(DISTINCT callsign) FROM repeater_confirmations WHERE repeater_id={$repeater_id} AND heard_at > DATE_SUB(NOW(), INTERVAL (SELECT COALESCE(setting_value,120) FROM system_settings WHERE setting_key='confirm_days') DAY)")->fetchColumn() : 0;

    $threshold = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key='confirm_threshold'")->fetchColumn() ?: 2);

    $status_updated = false;
    if ($count >= $threshold && in_array($rep['status'], ['UNKNOWN','DOWN TEMPORARILY','DEAD'])) {
        $db->prepare("UPDATE repeaters SET status='OPERATIONAL', last_update=CURDATE() WHERE id=?")->execute([$repeater_id]);
        $status_updated = true;
    }

    api_ok([
        'status'         => 'confirmed',
        'confirmations'  => $count,
        'threshold'      => $threshold,
        'status_updated' => $status_updated,
    ], ['message' => "Thank you {$callsign}! Confirmation recorded for {$rep['callsign']}."]);
}

// ── GET CONFIRMATIONS ─────────────────────────────────────────
if ($parts[0] === 'confirmations' && isset($parts[1]) && is_numeric($parts[1])) {
    $repeater_id = (int)$parts[1];
    $confs = $db->prepare("SELECT callsign, heard_at, radio_type, signal_report, latitude, longitude FROM repeater_confirmations WHERE repeater_id=? AND heard_at > DATE_SUB(NOW(), INTERVAL (SELECT COALESCE(setting_value,120) FROM system_settings WHERE setting_key='confirm_days') DAY) ORDER BY heard_at DESC");
    $confs->execute([$repeater_id]);
    $unique = (int)$db->prepare("SELECT COUNT(DISTINCT callsign) FROM repeater_confirmations WHERE repeater_id=? AND heard_at > DATE_SUB(NOW(), INTERVAL (SELECT COALESCE(setting_value,120) FROM system_settings WHERE setting_key='confirm_days') DAY)")->execute([$repeater_id]) ? $db->query("SELECT COUNT(DISTINCT callsign) FROM repeater_confirmations WHERE repeater_id={$repeater_id} AND heard_at > DATE_SUB(NOW(), INTERVAL (SELECT COALESCE(setting_value,120) FROM system_settings WHERE setting_key='confirm_days') DAY)")->fetchColumn() : 0;
    $threshold = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key='confirm_threshold'")->fetchColumn() ?: 2);
    api_ok([
        'confirmations' => $confs->fetchAll(),
        'unique_count'  => $unique,
        'threshold'     => $threshold,
    ]);
}

// ── CANT HEAR REPORT ────────────────────────────────────────
if ($parts[0] === 'cant_hear' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $repeater_id = (int)($data['repeater_id'] ?? 0);
    $callsign    = strtoupper(trim($data['callsign'] ?? ''));
    $radio_type  = in_array($data['radio_type'] ?? '', ['HT','Mobile','Base']) ? $data['radio_type'] : 'Unknown';
    $lat         = isset($data['latitude'])  ? (float)$data['latitude']  : null;
    $lon         = isset($data['longitude']) ? (float)$data['longitude'] : null;
    $notes       = isset($data['notes']) ? substr($data['notes'], 0, 200) : null;

    if (!$repeater_id || !$callsign) api_error(400, 'repeater_id and callsign are required');
    if (!preg_match('/^[A-Z0-9]{3,10}$/', $callsign)) api_error(400, 'Invalid callsign format');

    $rep = $db->prepare("SELECT id, callsign, status FROM repeaters WHERE archived_at IS NULL AND id=?");
    $rep->execute([$repeater_id]);
    $repeater = $rep->fetch();
    if (!$repeater) api_error(404, 'Repeater not found');

    // Check 24hr duplicate
    $recent = $db->prepare("SELECT id FROM repeater_cant_hear WHERE repeater_id=? AND callsign=? AND reported_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $recent->execute([$repeater_id, $callsign]);
    if ($recent->fetch())
        api_ok(['status'=>'already_reported'], ['message'=>'You already reported this repeater in the last 24 hours']);

    // Insert report
    $db->prepare("INSERT INTO repeater_cant_hear (repeater_id, callsign, latitude, longitude, radio_type, notes) VALUES (?,?,?,?,?,?)")
       ->execute([$repeater_id, $callsign, $lat, $lon, $radio_type, $notes]);

    // Count unique reports in last 120 days
    $days = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key='confirm_days'")->fetchColumn() ?: 120);
    $threshold = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key='cant_hear_threshold'")->fetchColumn() ?: 3);
    $count = (int)$db->query("SELECT COUNT(DISTINCT callsign) FROM repeater_cant_hear WHERE repeater_id={$repeater_id} AND reported_at > DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();

    $flagged = false;
    if ($count >= $threshold && $repeater['status'] === 'OPERATIONAL') {
        // Flag for coordinator review
        $db->prepare("INSERT IGNORE INTO audit_log (action, table_name, record_id, new_data, user_id) VALUES (?,?,?,?,?)")
           ->execute(['CANT_HEAR_THRESHOLD', 'repeaters', $repeater_id,
             json_encode(['cant_hear_count'=>$count, 'threshold'=>$threshold, 'needs_review'=>true]), 0]);

        // Only notify once per 30 days
        $already_notified = $db->prepare("SELECT id FROM audit_log WHERE action='CANT_HEAR_NOTIFIED' AND record_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $already_notified->execute([$repeater_id]);
        if (!$already_notified->fetch()) {
            // Get full repeater info
            $full = $db->prepare("SELECT * FROM repeaters WHERE archived_at IS NULL AND id=?");
            $full->execute([$repeater_id]);
            $full_rep = $full->fetch();

            $subject = "Action Required: {$repeater['callsign']} - {$count} Cannot Hear Reports";
            $coord_body = "REPEATER REVIEW REQUIRED\n\n" .
                "Repeater {$repeater['callsign']} has received {$count} Cannot Hear reports.\n\n" .
                "Callsign:  {$repeater['callsign']}\n" .
                "Frequency: " . number_format((float)($full_rep['output_freq'] ?? 0), 4) . " MHz\n" .
                "Location:  " . ($full_rep['city'] ?? '') . "\n" .
                "Status:    {$repeater['status']}\n\n" .
                "Please investigate and take action:\n" .
                "https://w5dro.com/repeater_coord/admin/cant_hear_review.php\n\n" .
                "73,\nORSI System";

            // Email district coordinator
            $coord_email = get_coordinator_email($full_rep['district'] ?? 'OKC');
            if ($coord_email) orsi_mail($coord_email, $subject, $coord_body);

            // Email trustee
            if (!empty($full_rep['contact_email'])) {
                $trustee_body = "Dear " . ($full_rep['contact_name'] ?: $full_rep['trustee']) . ",\n\n" .
                    "We have received {$count} reports from amateur radio operators who cannot hear your repeater {$repeater['callsign']}.\n\n" .
                    "Please check your repeater and contact your district coordinator.\n\n" .
                    "View your repeater: https://w5dro.com/repeater_coord/repeater.php?id={$repeater_id}\n\n" .
                    "73,\nOklahoma Repeater Society\nhttps://w5dro.com";
                orsi_mail($full_rep['contact_email'], "Action Required: {$repeater['callsign']} - Cannot Hear Reports", $trustee_body);
            }

            // Log notification sent
            $db->prepare("INSERT INTO audit_log (action, table_name, record_id, new_data, user_id) VALUES (?,?,?,?,?)")
               ->execute(['CANT_HEAR_NOTIFIED', 'repeaters', $repeater_id,
                 json_encode(['count'=>$count, 'coord_emailed'=>$coord_email??null, 'trustee_emailed'=>$full_rep['contact_email']??null]), 0]);
        }
        $flagged = true;
    }

    api_ok([
        'status'    => 'reported',
        'count'     => $count,
        'threshold' => $threshold,
        'flagged'   => $flagged,
    ], ['message' => "Thank you {$callsign}! Your report has been recorded for {$repeater['callsign']}." . 
        ($flagged ? " This repeater has been flagged for coordinator review." : "")]);
}

// ── GET CANT HEAR COUNT + LOCATIONS ─────────────────────────
if ($parts[0] === 'cant_hear_count' && isset($parts[1]) && is_numeric($parts[1])) {
    $repeater_id = (int)$parts[1];
    $days = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key='confirm_days'")->fetchColumn() ?: 120);
    $count = (int)$db->query("SELECT COUNT(DISTINCT callsign) FROM repeater_cant_hear WHERE repeater_id={$repeater_id} AND reported_at > DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();
    $threshold = (int)($db->query("SELECT setting_value FROM system_settings WHERE setting_key='cant_hear_threshold'")->fetchColumn() ?: 3);
    $reports = $db->prepare("SELECT callsign, reported_at, radio_type, latitude, longitude FROM repeater_cant_hear WHERE repeater_id=? AND reported_at > DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY reported_at DESC");
    $reports->execute([$repeater_id, $days]);
    api_ok(['count' => $count, 'threshold' => $threshold, 'reports' => $reports->fetchAll()]);
}

// ── FCC Lookup ────────────────────────────────────────────────
if ($path === 'fcc_lookup' && isset($_GET['callsign'])) {
    $cs = strtoupper(trim($_GET['callsign']));
    $q = $db->prepare("SELECT callsign, licensee_name, license_status, expiry_date,
        email, phone, street_address, city, state, zip_code
        FROM fcc_licenses WHERE callsign=? AND license_status='A' LIMIT 1");
    $q->execute([$cs]);
    $row = $q->fetch();
    if ($row) {
        api_ok([
            'found'   => true,
            'callsign'=> $row['callsign'],
            'name'    => $row['licensee_name'],
            'status'  => $row['license_status'],
            'expiry'  => $row['expiry_date'],
            'email'   => $row['email'],
            'phone'   => $row['phone'],
            'address' => $row['street_address'],
            'city'    => $row['city'],
            'state'   => $row['state'],
            'zip'     => $row['zip_code'],
        ]);
    } else {
        api_ok(['found' => false, 'callsign' => $cs]);
    }
}

// ── 404 ────────────────────────────────────────────────────
api_error(404, 'Unknown endpoint: ' . $path);
