<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();

$id = (int)($_GET['id'] ?? 0);

// Single repeater export
if ($id) {
    $r = $db->prepare("SELECT * FROM repeaters WHERE id = ?");
    $r->execute([$id]);
    $rep = $r->fetch();
    if (!$rep || !$rep['latitude']) {
        flash('danger', 'Repeater not found or missing coordinates.');
        header('Location: ' . BASE_PATH . '/index.php'); exit;
    }

    // Generate .qth file (site location)
    $qth = $rep['callsign'] . "\n";
    $qth .= number_format((float)$rep['latitude'], 6) . "\n";
    $lon = abs((float)$rep['longitude']); // SPLAT uses positive west longitude
    $qth .= number_format($lon, 6) . "\n";
    $agl = $rep['antenna_height_agl'] ?? 0;
    $qth .= number_format((float)$agl, 1) . "\n"; // antenna height in feet

    // Generate .lrp file (RF parameters)
    $erp_dbw = $rep['erp_watts'] ? 10 * log10((float)$rep['erp_watts']) : 0;
    $freq_mhz = (float)$rep['output_freq'];
    $lrp  = "15.0\n";          // Earth dielectric constant
    $lrp .= "0.005\n";         // Earth conductivity
    $lrp .= "301.0\n";         // Atmospheric bending constant
    $lrp .= number_format($freq_mhz, 3) . "\n"; // Frequency in MHz
    $lrp .= "5\n";             // Radio climate (5=Continental Temperate)
    $lrp .= "0\n";             // Polarization (0=Horizontal, 1=Vertical)
    $lrp .= "0.5\n";           // Fraction of situations
    $lrp .= "0.5\n";           // Fraction of time
    $lrp .= number_format((float)($rep['erp_watts'] ?? 1), 3) . "\n"; // ERP in watts
    $lrp .= "-" . number_format(abs($erp_dbw), 1) . "\n"; // Receiver sensitivity

    // Send as ZIP
    $zip_file = tempnam(sys_get_temp_dir(), 'splat_');
    $zip = new ZipArchive();
    $zip->open($zip_file, ZipArchive::CREATE);
    $zip->addFromString($rep['callsign'] . '.qth', $qth);
    $zip->addFromString($rep['callsign'] . '.lrp', $lrp);
    // Add a readme
    $readme = "SPLAT! Input Files for " . $rep['callsign'] . "\n";
    $readme .= "Generated: " . date('Y-m-d H:i') . "\n\n";
    $readme .= "Files included:\n";
    $readme .= "  " . $rep['callsign'] . ".qth - Site location file\n";
    $readme .= "  " . $rep['callsign'] . ".lrp - RF parameters file\n\n";
    $readme .= "Usage:\n";
    $readme .= "  splat -t " . $rep['callsign'] . ".qth -L 20.0 -o coverage.ppm\n\n";
    $readme .= "Repeater Data:\n";
    $readme .= "  Callsign:  " . $rep['callsign'] . "\n";
    $readme .= "  Frequency: " . $rep['output_freq'] . " MHz\n";
    $readme .= "  Latitude:  " . $rep['latitude'] . "\n";
    $readme .= "  Longitude: " . $rep['longitude'] . "\n";
    $readme .= "  AGL:       " . ($rep['antenna_height_agl'] ?? 'N/A') . " ft\n";
    $readme .= "  HAAT:      " . ($rep['haat'] ?? 'N/A') . " ft\n";
    $readme .= "  TX Power:  " . ($rep['tx_power_watts'] ?? 'N/A') . " W\n";
    $readme .= "  ERP:       " . ($rep['erp_watts'] ?? 'N/A') . " W\n";
    $zip->addFromString('README.txt', $readme);
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="splat_' . $rep['callsign'] . '.zip"');
    header('Content-Length: ' . filesize($zip_file));
    readfile($zip_file);
    unlink($zip_file);
    exit;
}

// Show list of repeaters with RF data for bulk export
$page_title = 'SPLAT! Export';
$reps = $db->query("SELECT id, callsign, output_freq, city, county, district, latitude, longitude, haat, erp_watts, tx_power_watts, antenna_height_agl FROM repeaters WHERE latitude IS NOT NULL ORDER BY output_freq")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-file-export"></i> SPLAT! File Export</div>

<div class="alert alert-info">
  <i class="fa fa-circle-info"></i>
  <strong>SPLAT!</strong> is a free RF propagation analysis tool for Linux.
  Download individual <code>.qth</code> and <code>.lrp</code> files per repeater, or export all as a bulk ZIP.
  <a href="https://www.qsl.net/kd2bd/splat.html" target="_blank">Learn more about SPLAT!</a>
</div>

<div style="margin-bottom:16px;display:flex;gap:10px">
  <a href="<?= BASE_PATH ?>/admin/splat_export.php?bulk=1" class="btn btn-primary"><i class="fa fa-download"></i> Export All as Bulk ZIP</a>
  <a href="<?= BASE_PATH ?>/kml_export.php" class="btn btn-secondary"><i class="fa fa-map"></i> Export KML for Google Earth</a>
</div>

<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> Repeaters (<?= count($reps) ?>) - Click to download individual SPLAT! files</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Callsign</th><th>Freq</th><th>Location</th>
        <th>AGL (ft)</th><th>HAAT (ft)</th><th>TX (W)</th><th>ERP (W)</th>
        <th>Coords</th><th>Export</th>
      </tr></thead>
      <tbody>
      <?php foreach ($reps as $r): ?>
      <tr>
        <td><a href="<?= BASE_PATH ?>/repeater.php?id=<?= $r['id'] ?>" class="callsign-link"><?= h($r['callsign']) ?></a></td>
        <td><span class="freq"><?= number_format((float)$r['output_freq'],4) ?></span></td>
        <td><?= h($r['city']) ?>, <?= h($r['county']) ?></td>
        <td><?= $r['antenna_height_agl'] ? number_format((float)$r['antenna_height_agl'],0) : '<span class="text-muted">-</span>' ?></td>
        <td><?= $r['haat'] ? '<strong>'.number_format((float)$r['haat'],0).'</strong>' : '<span class="text-muted">-</span>' ?></td>
        <td><?= $r['tx_power_watts'] ? number_format((float)$r['tx_power_watts'],0) : '<span class="text-muted">-</span>' ?></td>
        <td><?= $r['erp_watts'] ? number_format((float)$r['erp_watts'],1) : '<span class="text-muted">-</span>' ?></td>
        <td style="font-size:.75rem"><?= $r['latitude'] ? number_format((float)$r['latitude'],4).', '.number_format((float)$r['longitude'],4) : '<span class="text-muted">No coords</span>' ?></td>
        <td>
          <a href="<?= BASE_PATH ?>/admin/splat_export.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary" title="Download SPLAT! files">
            <i class="fa fa-download"></i> SPLAT!
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
