<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

$single_id = (int)($_GET['id'] ?? 0);
$private_clause = is_logged_in() ? '' : 'AND private = 0';

if ($single_id) {
    $stmt = $db->prepare("SELECT * FROM repeaters WHERE id = ? $private_clause");
    $stmt->execute([$single_id]);
    $reps = $stmt->fetchAll();
    $filename = 'repeater_' . $single_id;
} else {
    $reps = $db->query("SELECT * FROM repeaters WHERE latitude IS NOT NULL AND status NOT IN ('DEAD','DECOORDINATED') $private_clause ORDER BY output_freq")->fetchAll();
    $filename = 'OK_Repeaters_' . date('Ymd');
}

header('Content-Type: application/vnd.google-earth.kml+xml');
header('Content-Disposition: attachment; filename="' . $filename . '.kml"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
  <name>Oklahoma Repeaters - ORSI <?= date('Y-m-d') ?></name>
  <description>Oklahoma Repeater Society Coordinated Repeaters</description>

  <!-- Styles by status -->
  <Style id="operational"><IconStyle><color>ff00aa00</color><scale>0.9</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/target.png</href></Icon></IconStyle></Style>
  <Style id="proposed"><IconStyle><color>ff00aaff</color><scale>0.8</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/target.png</href></Icon></IconStyle></Style>
  <Style id="down"><IconStyle><color>ff0066ff</color><scale>0.8</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/target.png</href></Icon></IconStyle></Style>
  <Style id="other"><IconStyle><color>ff888888</color><scale>0.7</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/target.png</href></Icon></IconStyle></Style>

  <?php
  $style_map = ['OPERATIONAL'=>'operational','PROPOSED'=>'proposed','DOWN TEMPORARILY'=>'down','CONSTRUCTION'=>'down'];

  // Group by district
  $districts = [];
  foreach ($reps as $r) {
      $districts[$r['district']][] = $r;
  }
  ksort($districts);

  foreach ($districts as $dist => $dreps):
  ?>
  <Folder>
    <name>District <?= h($dist) ?> (<?= count($dreps) ?>)</name>
    <?php foreach ($dreps as $r): ?>
    <?php if (!$r['latitude']) continue; ?>
    <Placemark>
      <name><?= h($r['callsign']) ?> - <?= number_format((float)$r['output_freq'],4) ?> MHz</name>
      <styleUrl>#<?= $style_map[$r['status']] ?? 'other' ?></styleUrl>
      <description><![CDATA[
        <table style="font-family:Arial;font-size:12px">
          <tr><td><b>Callsign:</b></td><td><?= h($r['callsign']) ?></td></tr>
          <tr><td><b>Output:</b></td><td><?= number_format((float)$r['output_freq'],4) ?> MHz</td></tr>
          <tr><td><b>Input:</b></td><td><?= number_format((float)$r['input_freq'],4) ?> MHz</td></tr>
          <tr><td><b>Type:</b></td><td><?= h($r['type']) ?></td></tr>
          <tr><td><b>Status:</b></td><td><?= h($r['status']) ?></td></tr>
          <tr><td><b>Trustee:</b></td><td><?= h($r['trustee']) ?></td></tr>
          <tr><td><b>Sponsor:</b></td><td><?= h($r['sponsor']) ?></td></tr>
          <tr><td><b>County:</b></td><td><?= h($r['county']) ?></td></tr>
          <tr><td><b>City:</b></td><td><?= h($r['city']) ?></td></tr>
          <?php if ($r['pl_tone']): ?><tr><td><b>PL Tone:</b></td><td><?= number_format((float)$r['pl_tone'],1) ?> Hz</td></tr><?php endif; ?>
          <?php if ($r['haat']): ?><tr><td><b>HAAT:</b></td><td><?= number_format((float)$r['haat'],1) ?> ft</td></tr><?php endif; ?>
          <?php if ($r['erp_watts']): ?><tr><td><b>ERP:</b></td><td><?= number_format((float)$r['erp_watts'],1) ?> W</td></tr><?php endif; ?>
          <?php if ($r['antenna_height_agl']): ?><tr><td><b>AGL:</b></td><td><?= number_format((float)$r['antenna_height_agl'],1) ?> ft</td></tr><?php endif; ?>
          <?php if ($r['internet_link']): ?><tr><td><b>Internet:</b></td><td><?= h($r['internet_link']) ?></td></tr><?php endif; ?>
          <?php if ($r['notes']): ?><tr><td><b>Notes:</b></td><td><?= h($r['notes']) ?></td></tr><?php endif; ?>
          <tr><td colspan="2"><a href="<?= BASE_PATH ?>/repeater.php?id=<?= $r['id'] ?>">View Full Record</a></td></tr>
        </table>
      ]]></description>
      <Point>
        <coordinates><?= number_format((float)$r['longitude'],6) ?>,<?= number_format((float)$r['latitude'],6) ?>,<?= $r['antenna_height_agl'] ? (float)$r['antenna_height_agl'] * 0.3048 : 0 ?></coordinates>
      </Point>
    </Placemark>
    <?php endforeach; ?>
  </Folder>
  <?php endforeach; ?>

</Document>
</kml>
