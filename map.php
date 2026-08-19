<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Repeater Map';
$db = get_db();

// Fetch repeaters that have coordinates
$private_clause = is_logged_in() ? '' : 'AND private = 0';
$reps = $db->query("SELECT id,callsign,output_freq,input_freq,type,status,district,county,city,pl_tone,latitude,longitude,internet_link,location_source FROM repeaters WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status NOT IN ('DEAD','DECOORDINATED') " . $private_clause . " ORDER BY output_freq")->fetchAll();

$json_reps = json_encode($reps);

include __DIR__ . '/includes/header.php';
?>

<div class="page-title"><i class="fa fa-map-marker-alt"></i> Repeater Map</div>

<?php if (count($reps) < 5): ?>
<div class="alert alert-warning">
  <i class="fa fa-triangle-exclamation"></i>
  <strong>Few or no GPS coordinates in database.</strong>
  Add latitude/longitude to repeater records in the
  <a href="<?= BASE_PATH ?>/admin/edit_repeater.php">edit form</a> to see them on the map.
  Currently showing <?= count($reps) ?> repeater<?= count($reps)!=1?'s':'' ?> with coordinates.
</div>
<?php endif; ?>

<!-- Filter -->
<div class="filter-bar" style="margin-bottom:12px">
  <div class="form-group">
    <label>Type</label>
    <select id="map-filter-type">
      <option value="">All Types</option>
      <?php foreach (['REPEATER','D-STAR','DMR','FUSION','P-25','ATV'] as $t): ?>
      <option value="<?= h($t) ?>"><?= h($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>District</label>
    <select id="map-filter-district">
      <option value="">All Districts</option>
      <?php foreach (['NE','NW','OKC','SE','SW','TUL'] as $d): ?>
      <option value="<?= h($d) ?>"><?= h($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>&nbsp;</label>
    <button class="btn btn-primary btn-sm" onclick="applyMapFilters()"><i class="fa fa-filter"></i> Apply</button>
  </div>
  <span class="text-muted" id="map-count" style="align-self:flex-end;font-size:.85rem"></span>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.Default.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/leaflet.markercluster.min.js"></script>

<div id="map"></div>

<script>
const ALL_REPS = <?= $json_reps ?>;

const map = L.map('map').setView([35.5, -97.5], 7);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors',
  maxZoom: 18
}).addTo(map);

const colorMap = {
  'REPEATER':'#1a5276','D-STAR':'#1e8449','DMR':'#7d3c0d',
  'FUSION':'#7d6608','P-25':'#4a235a','ATV':'#922b21'
};

function makeIcon(type, status, source) {
  const color = colorMap[type] || '#1a5276';
  const opacity = status === 'OPERATIONAL' ? 1 : 0.5;
  const approx = source === 'CITY';
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='22' height='28' viewBox='0 0 22 28' style='opacity:${opacity}'>
    <line x1='11' y1='2' x2='2' y2='24' stroke='${color}' stroke-width='2.2' stroke-linecap='round'/>
    <line x1='11' y1='2' x2='20' y2='24' stroke='${color}' stroke-width='2.2' stroke-linecap='round'/>
    <line x1='5' y1='10' x2='17' y2='10' stroke='${color}' stroke-width='1.6'/>
    <line x1='3.5' y1='17' x2='18.5' y2='17' stroke='${color}' stroke-width='1.6'/>
    <line x1='2' y1='24' x2='20' y2='24' stroke='${color}' stroke-width='2.2' stroke-linecap='round'/>
    <circle cx='11' cy='2' r='2.8' fill='${color}'/>
    ${approx ? `<circle cx='11' cy='2' r='5' fill='none' stroke='#d97706' stroke-width='1.5' stroke-dasharray='2.5,2'/>` : ''}
  </svg>`;
  return L.divIcon({
    html: svg,
    className: '',
    iconSize:   [22, 28],
    iconAnchor: [11, 28],
    popupAnchor:[0, -28]
  });
}

const clusterGroup = L.markerClusterGroup({
  maxClusterRadius: 50,
  spiderfyOnMaxZoom: true,
  showCoverageOnHover: false,
  zoomToBoundsOnClick: true,
  iconCreateFunction: function(cluster) {
    const count = cluster.getChildCount();
    const size  = count < 10 ? 36 : count < 50 ? 42 : 48;
    const bg    = count < 10 ? '#1a5276' : count < 50 ? '#d97706' : '#c0392b';
    return L.divIcon({
      html: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${bg};color:#fff;font-weight:700;font-size:${count<10?13:11}px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.4);border:2px solid rgba(255,255,255,.7)">${count}</div>`,
      className: '',
      iconSize:   [size, size],
      iconAnchor: [size/2, size/2]
    });
  }
});
map.addLayer(clusterGroup);

function renderMarkers(reps) {
  clusterGroup.clearLayers();
  reps.forEach(r => {
    const m = L.marker([r.latitude, r.longitude], { icon: makeIcon(r.type, r.status, r.location_source) })
      .bindPopup(`
        <b>${r.callsign}</b> - ${parseFloat(r.output_freq).toFixed(4)} MHz<br>
        <em>${r.type}</em> &bull; <strong>${r.status}</strong><br>
        ${r.city ? r.city + ', ' : ''}${r.county} Co. &bull; District: ${r.district}<br>
        ${r.pl_tone ? 'PL: ' + parseFloat(r.pl_tone).toFixed(1) + ' Hz' : 'No PL'}<br>
        ${r.internet_link ? '<em>' + r.internet_link + '</em><br>' : ''}
        ${r.location_source === 'CITY' ? '<em style="color:#d97706">&#9888; Approx. location (city center)</em><br>' : ''}
        <a href="<?= BASE_PATH ?>/repeater.php?id=${r.id}" target="_blank">View Details →</a>
      `);
    clusterGroup.addLayer(m);
  });
  document.getElementById('map-count').textContent = reps.length + ' repeater' + (reps.length !== 1 ? 's' : '') + ' shown';
}

function applyMapFilters() {
  const type = document.getElementById('map-filter-type').value;
  const dist = document.getElementById('map-filter-district').value;
  const filtered = ALL_REPS.filter(r =>
    (!type || r.type === type) && (!dist || r.district === dist)
  );
  renderMarkers(filtered);
}

renderMarkers(ALL_REPS);

// Legend
const legend = L.control({ position: 'bottomright' });
legend.onAdd = function() {
  const d = L.DomUtil.create('div', '');
  d.style.cssText = 'background:#fff;padding:10px;border-radius:6px;font-size:12px;box-shadow:0 2px 8px rgba(0,0,0,.2)';
  d.innerHTML = '<strong>Type</strong><br>' +
    Object.entries(colorMap).map(([t,c]) =>
      `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:;margin-right:4px"></span>`
    ).join('<br>') + '<br><br><strong>Location</strong><br>' +
    '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;border:2px solid #fff;background:#888;margin-right:4px"></span>GPS<br>' +
    '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;border:2px dashed #d97706;background:#888;margin-right:4px"></span>City (approx)';
  //
    Object.entries(colorMap).map(([t,c]) =>
      `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${c};margin-right:4px"></span>${t}`
    ).join('<br>');
  return d;
};
legend.addTo(map);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
