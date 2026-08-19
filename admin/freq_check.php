<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db = get_db();
$page_title = 'Frequency Availability Check';

$bands = [
    '2m-lo'  => ['name'=>'2m Low (145 MHz)',  'low'=>145.110, 'high'=>145.490, 'step'=>20.0, 'offset'=>-0.600],
    '2m-mid' => ['name'=>'2m Mid (146 MHz)',  'low'=>146.610, 'high'=>146.985, 'step'=>15.0, 'offset'=>-0.600],
    '2m-hi'  => ['name'=>'2m High (147 MHz)', 'low'=>147.000, 'high'=>147.390, 'step'=>15.0, 'offset'=>0.600],
    '70cm'   => ['name'=>'70cm (440 MHz)',    'low'=>442.000, 'high'=>444.975, 'step'=>25.0, 'offset'=>5.000],
    '1.25m'  => ['name'=>'1.25m (222 MHz)',   'low'=>223.860, 'high'=>224.980, 'step'=>20.0, 'offset'=>-1.600],
    '6m'     => ['name'=>'6m (52 MHz)',       'low'=>52.810,  'high'=>53.990,  'step'=>20.0, 'offset'=>-1.700],
];

$results = null;
$lat = $lon = $band_key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lat      = (float)($_POST['latitude'] ?? 0);
    $lon      = (float)($_POST['longitude'] ?? 0);
    $band_key = $_POST['band'] ?? '70cm';
    $band     = $bands[$band_key] ?? $bands['70cm'];

    // Get coordination rules
    $rule = $db->query("SELECT * FROM coordination_rules 
        WHERE band_low_mhz <= {$band['low']} AND band_high_mhz >= {$band['high']} LIMIT 1")->fetch();
    $co_min  = $rule ? (float)$rule['co_channel_min_miles']  : 120.0;
    $adj_15  = $rule ? (float)$rule['adj_15khz_min_miles']  : 40.0;
    $adj_20  = $rule ? (float)$rule['adj_20khz_min_miles']  : 25.0;
    $adj_30  = $rule ? (float)$rule['adj_30khz_min_miles']  : 20.0;

    // Get all repeaters in this band
    $existing = $db->query("
        SELECT callsign, output_freq, city, county, status, latitude, longitude, location_source
        FROM repeaters
        WHERE output_freq >= {$band['low']} AND output_freq <= {$band['high']}
        AND archived_at IS NULL
        AND status NOT IN ('DECOORDINATED')
        AND latitude IS NOT NULL
    ")->fetchAll();

    // Generate all frequency slots
    $step_mhz = $band['step'] / 1000;
    $slots = [];
    $freq = $band['low'];
    while ($freq <= $band['high'] + 0.001) {
        $freq_r = round($freq, 4);
        $conflicts = [];
        $nearest_dist = 9999;
        $nearest_call = '';

        foreach ($existing as $e) {
            $diff_khz = round(abs($freq_r - (float)$e['output_freq']) * 1000, 1);
            if ($diff_khz > max($adj_30, 30.1)) continue;

            // Calculate distance
            $dlat = ($lat - (float)$e['latitude']) * M_PI/180;
            $dlon = ($lon - (float)$e['longitude']) * M_PI/180;
            $a = sin($dlat/2)*sin($dlat/2) + cos($lat*M_PI/180)*cos((float)$e['latitude']*M_PI/180)*sin($dlon/2)*sin($dlon/2);
            $dist = round(3958.8 * 2 * atan2(sqrt($a), sqrt(1-$a)), 1);
            $margin = $e['location_source'] === 'CITY' ? 0.85 : 1.0;
            $eff = $dist * $margin;

            if ($dist < $nearest_dist && $diff_khz < 0.1) {
                $nearest_dist = $dist;
                $nearest_call = $e['callsign'];
            }

            $conflict = null;
            if ($diff_khz < 0.1 && $eff < $co_min) {
                $conflict = ['type'=>'Co-channel', 'min'=>$co_min, 'dist'=>$dist, 'call'=>$e['callsign'], 'status'=>$e['status']];
            } elseif ($diff_khz <= 15.5 && $eff < $adj_15) {
                $conflict = ['type'=>'15kHz adj', 'min'=>$adj_15, 'dist'=>$dist, 'call'=>$e['callsign'], 'status'=>$e['status']];
            } elseif ($diff_khz <= 20.5 && $eff < $adj_20) {
                $conflict = ['type'=>'20kHz adj', 'min'=>$adj_20, 'dist'=>$dist, 'call'=>$e['callsign'], 'status'=>$e['status']];
            } elseif ($diff_khz <= 30.5 && $eff < $adj_30) {
                $conflict = ['type'=>'30kHz adj', 'min'=>$adj_30, 'dist'=>$dist, 'call'=>$e['callsign'], 'status'=>$e['status']];
            }

            if ($conflict) $conflicts[] = $conflict;

            if ($diff_khz < 0.1 && $dist < $nearest_dist) {
                $nearest_dist = $dist;
                $nearest_call = $e['callsign'];
            }
        }

        // Find nearest co-channel for scoring
        $nearest = 9999;
        foreach ($existing as $e) {
            if (abs(round((float)$e['output_freq'],4) - $freq_r) < 0.001) {
                $dlat = ($lat - (float)$e['latitude']) * M_PI/180;
                $dlon = ($lon - (float)$e['longitude']) * M_PI/180;
                $a = sin($dlat/2)*sin($dlat/2) + cos($lat*M_PI/180)*cos((float)$e['latitude']*M_PI/180)*sin($dlon/2)*sin($dlon/2);
                $dist = round(3958.8 * 2 * atan2(sqrt($a), sqrt(1-$a)), 1);
                if ($dist < $nearest) { $nearest = $dist; $nearest_call = $e['callsign']; }
            }
        }

        $slots[] = [
            'freq'      => $freq_r,
            'input'     => round($freq_r + $band['offset'], 4),
            'conflicts' => $conflicts,
            'clear'     => empty($conflicts),
            'nearest'   => $nearest === 9999 ? null : $nearest,
            'nearest_call' => $nearest_call,
        ];

        $freq = round($freq + $step_mhz, 4);
    }

    // Sort: clear first, then by nearest distance descending
    usort($slots, function($a, $b) {
        if ($a['clear'] !== $b['clear']) return $b['clear'] - $a['clear'];
        return ($b['nearest'] ?? 0) - ($a['nearest'] ?? 0);
    });

    $results = $slots;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-tower-broadcast"></i> Frequency Availability Check</div>
<p style="color:var(--muted);font-size:.85rem;margin-bottom:16px">
  Enter a location to see which frequencies are available for coordination in that area.
</p>

<div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">

<!-- Left: Input Form -->
<div>
  <div class="card">
    <div class="card-header"><i class="fa fa-search"></i> Check Location</div>
    <div style="padding:16px">
      <form method="post" id="checkForm">
        <div class="form-group" style="margin-bottom:12px">
          <label>Band</label>
          <select name="band" id="band_select">
            <?php foreach ($bands as $k => $b): ?>
            <option value="<?=$k?>" <?=$band_key===$k?'selected':''?>><?=h($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:12px">
          <label>Latitude</label>
          <input type="number" name="latitude" id="lat_input" step="0.000001"
            value="<?=$lat?>" placeholder="e.g. 35.4676">
        </div>
        <div class="form-group" style="margin-bottom:12px">
          <label>Longitude</label>
          <input type="number" name="longitude" id="lon_input" step="0.000001"
            value="<?=$lon?>" placeholder="e.g. -97.5164">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="fa fa-search"></i> Check Frequencies
        </button>
      </form>
    </div>
  </div>

  <!-- Map for clicking -->
  <div class="card" style="margin-top:12px">
    <div class="card-header"><i class="fa fa-map"></i> Click Map to Set Location</div>
    <div id="map" style="height:300px;border-radius:0 0 8px 8px"></div>
  </div>
</div>

<!-- Right: Results -->
<div>
<?php if ($results !== null): ?>
<?php
  $clear = array_filter($results, fn($s) => $s['clear']);
  $blocked = array_filter($results, fn($s) => !$s['clear']);
?>
<div style="display:flex;gap:12px;margin-bottom:12px">
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 16px;text-align:center;min-width:100px">
    <div style="font-size:1.8rem;font-weight:bold;color:#15803d"><?=count($clear)?></div>
    <div style="font-size:.8rem;color:var(--muted)">✅ Available</div>
  </div>
  <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;text-align:center;min-width:100px">
    <div style="font-size:1.8rem;font-weight:bold;color:#dc2626"><?=count($blocked)?></div>
    <div style="font-size:.8rem;color:var(--muted)">❌ Blocked</div>
  </div>
  <div style="background:#f1f5f9;border:1px solid var(--border);border-radius:8px;padding:10px 16px;flex:1">
    <div style="font-size:.8rem;color:var(--muted)">Location</div>
    <div style="font-weight:600"><?=number_format($lat,6)?>, <?=number_format($lon,6)?></div>
    <div style="font-size:.8rem;color:var(--muted)"><?=$bands[$band_key]['name']?></div>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> All Frequency Slots</div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Output MHz</th><th>Input MHz</th><th>Status</th>
      <th>Nearest Repeater</th><th>Conflicts</th>
    </tr></thead>
    <tbody>
    <?php foreach ($results as $slot): ?>
    <tr style="background:<?=$slot['clear']?'#f0fdf4':'#fff8f8'?>">
      <td>
        <strong style="font-size:1rem;color:<?=$slot['clear']?'#15803d':'#dc2626'?>">
          <?=number_format($slot['freq'],4)?>
        </strong>
      </td>
      <td style="color:var(--muted)"><?=number_format($slot['input'],4)?></td>
      <td>
        <?php if ($slot['clear']): ?>
          <span style="color:#15803d;font-weight:bold"><i class="fa fa-check-circle"></i> CLEAR</span>
        <?php else: ?>
          <span style="color:#dc2626;font-weight:bold"><i class="fa fa-times-circle"></i> BLOCKED</span>
        <?php endif; ?>
      </td>
      <td style="font-size:.82rem">
        <?php if ($slot['nearest']): ?>
          <?=h($slot['nearest_call'])?> — <?=$slot['nearest']?> mi
        <?php else: ?>
          <span style="color:var(--muted)">No nearby</span>
        <?php endif; ?>
      </td>
      <td style="font-size:.78rem">
        <?php foreach ($slot['conflicts'] as $c): ?>
        <div style="color:#dc2626">
          <?=h($c['call'])?> [<?=h($c['type'])?>] <?=$c['dist']?>mi 
          <span style="color:#aaa">(min <?=$c['min']?>mi)</span>
          <?php if (in_array($c['status'],['DEAD','ADMIN HOLD - LICENSE EXPIRED','ADMIN HOLD - HOLDER DECEASED'])): ?>
          <span style="background:#fef3c7;color:#92400e;font-size:.65rem;padding:1px 4px;border-radius:2px"><?=$c['status']?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php else: ?>
<div class="card">
  <div style="padding:60px;text-align:center;color:var(--muted)">
    <i class="fa fa-map-marker-alt" style="font-size:3rem;margin-bottom:16px;display:block"></i>
    Click the map or enter coordinates and select a band to check frequency availability.
  </div>
</div>
<?php endif; ?>
</div>
</div>

<!-- Leaflet Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
var map = L.map('map').setView([35.4676, -97.5164], 7);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
var marker = null;

<?php if ($lat && $lon): ?>
marker = L.marker([<?=$lat?>, <?=$lon?>]).addTo(map);
map.setView([<?=$lat?>, <?=$lon?>], 10);
<?php endif; ?>

map.on('click', function(e) {
    var lat = e.latlng.lat.toFixed(6);
    var lng = e.latlng.lng.toFixed(6);
    document.getElementById('lat_input').value = lat;
    document.getElementById('lon_input').value = lng;
    if (marker) map.removeLayer(marker);
    marker = L.marker([lat, lng]).addTo(map);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
