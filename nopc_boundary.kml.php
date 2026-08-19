<?php
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/vnd.google-earth.kml+xml');
header('Content-Disposition: attachment; filename="nopc_boundaries.kml"');

// Oklahoma border points (approximate polygon)
$oklahoma = [
    [37.000, -94.618], [37.000, -102.000],
    [36.500, -102.000], [36.500, -103.000],
    [36.993, -103.000], [37.000, -103.000],
    [37.000, -94.618],
];

// Generate a circle of points around a center lat/lon
function circle_points($lat, $lon, $radius_miles, $steps = 72) {
    $points = [];
    $R = 3958.8; // Earth radius miles
    $lat_r = deg2rad($lat);
    $lon_r = deg2rad($lon);
    $d = $radius_miles / $R;
    for ($i = 0; $i <= $steps; $i++) {
        $bearing = deg2rad($i * 360 / $steps);
        $lat2 = asin(sin($lat_r) * cos($d) + cos($lat_r) * sin($d) * cos($bearing));
        $lon2 = $lon_r + atan2(sin($bearing) * sin($d) * cos($lat_r), cos($d) - sin($lat_r) * sin($lat2));
        $points[] = [rad2deg($lat2), rad2deg($lon2)];
    }
    return $points;
}

// State border points from config
$borders = [
    'TX' => [
        'color'  => 'ff0000ff',
        'label'  => 'Texas Border - 100mi NOPC Zone',
        'points' => [
            [33.837,-94.043],[33.850,-94.500],[33.862,-95.000],[33.874,-95.500],
            [33.885,-96.000],[33.834,-96.500],[33.834,-97.000],[33.834,-97.500],
            [33.834,-98.000],[33.834,-98.500],[33.834,-99.000],[33.834,-99.500],
            [34.560,-100.000],[35.000,-100.000],[35.500,-100.000],
            [36.000,-100.000],[36.500,-100.000],
            [36.500,-103.000],[36.500,-102.000],[36.500,-101.000],[36.500,-100.000],
        ],
    ],
    'KS' => [
        'color'  => 'ff00ff00',
        'label'  => 'Kansas Border - 100mi NOPC Zone',
        'points' => [
            [37.000,-94.618],[37.000,-95.000],[37.000,-96.000],[37.000,-97.000],
            [37.000,-98.000],[37.000,-99.000],[37.000,-100.000],[37.000,-101.000],[37.000,-102.000],
        ],
    ],
    'MO' => [
        'color'  => 'ff00ffff',
        'label'  => 'Missouri Border - 100mi NOPC Zone',
        'points' => [
            [36.497,-94.618],[36.800,-94.618],[37.000,-94.618],
        ],
    ],
    'AR' => [
        'color'  => 'ffff8800',
        'label'  => 'Arkansas Border - 100mi NOPC Zone',
        'points' => [
            [36.497,-94.618],[36.000,-94.618],[35.500,-94.618],
            [35.000,-94.618],[34.500,-94.618],[33.837,-94.043],
        ],
    ],
    'CO' => [
        'color'  => 'ffff00ff',
        'label'  => 'Colorado Border - 100mi NOPC Zone',
        'points' => [
            [37.000,-102.000],[36.993,-102.000],[36.500,-102.000],
        ],
    ],
    'NM' => [
        'color'  => 'ffffff00',
        'label'  => 'New Mexico Border - 100mi NOPC Zone',
        'points' => [
            [37.000,-103.000],[36.500,-103.000],[36.000,-103.000],
            [35.500,-103.000],[35.000,-103.000],[34.500,-103.000],
        ],
    ],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
  <name>ORSI NOPC Boundaries - 100 Mile Zones</name>
  <description>100-mile NOPC notification zones for Oklahoma border states</description>

  <Style id="borderPoint">
    <IconStyle><scale>0.5</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/placemark_circle.png</href></Icon></IconStyle>
  </Style>

<?php foreach ($borders as $state => $info): ?>
  <Style id="zone_<?= $state ?>">
    <LineStyle><color><?= $info['color'] ?></color><width>2</width></LineStyle>
    <PolyStyle><color>33<?= substr($info['color'], 2) ?></color><fill>1</fill><outline>1</outline></PolyStyle>
  </Style>

  <Folder>
    <name><?= $info['label'] ?></name>

    <!-- Border reference points -->
    <?php foreach ($info['points'] as $i => $pt): ?>
    <Placemark>
      <name><?= $state ?> border pt <?= $i+1 ?></name>
      <styleUrl>#borderPoint</styleUrl>
      <Point><coordinates><?= $pt[1] ?>,<?= $pt[0] ?>,0</coordinates></Point>
    </Placemark>
    <?php endforeach; ?>

    <!-- 100-mile circles around each border point -->
    <?php foreach ($info['points'] as $i => $pt):
        $circle = circle_points($pt[0], $pt[1], 100);
        $coords = implode(' ', array_map(fn($p) => "{$p[1]},{$p[0]},0", $circle));
    ?>
    <Placemark>
      <name><?= $state ?> 100mi zone pt <?= $i+1 ?></name>
      <styleUrl>#zone_<?= $state ?></styleUrl>
      <Polygon>
        <outerBoundaryIs><LinearRing><coordinates><?= $coords ?></coordinates></LinearRing></outerBoundaryIs>
      </Polygon>
    </Placemark>
    <?php endforeach; ?>
  </Folder>

<?php endforeach; ?>

</Document>
</kml>
