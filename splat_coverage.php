<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$rep = $db->prepare("SELECT * FROM repeaters WHERE id=?");
$rep->execute([$id]);
$r = $rep->fetch();

if (!$r || !$r['latitude'] || !$r['longitude']) {
    http_response_code(404); exit;
}

// Cache file paths
$cache_dir  = __DIR__ . '/splat_cache';
$cache_base = $cache_dir . '/' . $r['callsign'] . '_' . $id;
$png_file   = $cache_base . '.png';
$geo_file   = $cache_base . '.geo';
$bounds_file = $cache_base . '.bounds.json';

// Return cached if exists and fresh (7 days)
if (file_exists($bounds_file) && file_exists($png_file) &&
    (time() - filemtime($png_file)) < 604800) {
    header('Content-Type: application/json');
    echo file_get_contents($bounds_file);
    exit;
}

// Need RF data
$haat   = (float)($r['haat'] ?? 0);
$erp    = (float)($r['erp_watts'] ?? 0);
$freq   = (float)($r['output_freq'] ?? 146.0);
$agl    = (float)($r['antenna_height_agl'] ?? 0);

if (!$haat && !$agl) {
    http_response_code(422);
    echo json_encode(['error' => 'No antenna height data']);
    exit;
}

$ant_height = $agl ?: $haat;
if (!$erp) {
    $gain = (float)($r['antenna_gain_dbd'] ?? 0);
    $loss = (float)($r['feedline_loss_db'] ?? 0);
    $tx   = (float)($r['tx_power_watts'] ?? 25);
    $erp  = $tx * pow(10, ($gain - $loss) / 10);
}

// SPLAT uses positive west longitude
$lon_west = abs((float)$r['longitude']);
$lat      = (float)$r['latitude'];
$call     = preg_replace('/[^A-Z0-9]/', '', strtoupper($r['callsign']));

$work_dir = '/tmp/splat_' . $id;
if (!is_dir($work_dir)) mkdir($work_dir, 0777, true);

// Write QTH file
file_put_contents("$work_dir/{$call}.qth",
    "{$call}\n{$lat}\n{$lon_west}\n{$ant_height}\n");

// Write LRP file
file_put_contents("$work_dir/{$call}.lrp",
    "15.0\n0.005\n301.0\n{$freq}\n5\n1\n0.50\n0.50\n{$erp}\n-110.0\n");

// Write color file
file_put_contents("$work_dir/{$call}.scf",
    "-70: 255   0   0\n-80: 255 165   0\n-90: 255 255   0\n-100:  0 200   0\n-110:  0   0 255\n");

// Run SPLAT!
$sdf_dir = '/usr/local/share/splat/sdf';
$radius  = min(75, max(20, (int)($haat / 20)));

$cmd = "cd $work_dir && sudo /usr/bin/splat " .
    "-t {$call}.qth " .
    "-d {$sdf_dir} " .
    "-R {$radius} " .
    "-c 7.0 " .
    "-n -ngs -geo " .
    "-o {$call}_cov " .
    "2>/dev/null";

exec($cmd, $out, $ret);

$ppm = "$work_dir/{$call}_cov.ppm";
$geo = "$work_dir/{$call}_cov.geo";

if (!file_exists($ppm)) {
    http_response_code(500);
    echo json_encode(['error' => 'SPLAT failed', 'cmd' => $cmd]);
    exit;
}

// Convert PPM to transparent PNG
// Remove SPLAT! grid lines (pure bright green #00FF00) then make white transparent
exec("sudo convert $ppm " .
    "-fill \"#00CC00\" -opaque \"#00FF00\" " .  // replace bright grid green with coverage green
    "-fuzz 1% -transparent white " .
    "$png_file 2>/dev/null");

// Parse geo bounds
$geo_content = file_get_contents($geo);
preg_match('/TIEPOINT\s+0\s+0\s+([\d\.\-]+)\s+([\d\.\-]+)/m', $geo_content, $nw);
preg_match('/TIEPOINT\s+\d+\s+\d+\s+([\d\.\-]+)\s+([\d\.\-]+)/m', $geo_content, $se);

// Last TIEPOINT is SE corner
preg_match_all('/TIEPOINT\s+\d+\s+\d+\s+([\d\.\-]+)\s+([\d\.\-]+)/m', $geo_content, $ties);
$bounds = [
    'north' => (float)$ties[2][0],
    'south' => (float)end($ties[2]),
    'west'  => (float)$ties[1][0],
    'east'  => (float)end($ties[1]),
    'image' => BASE_PATH . '/splat_cache/' . basename($png_file),
    'callsign' => $r['callsign'],
    'radius_mi' => $radius,
];

file_put_contents($bounds_file, json_encode($bounds));

// Cleanup
exec("rm -rf $work_dir");

header('Content-Type: application/json');
echo json_encode($bounds);
