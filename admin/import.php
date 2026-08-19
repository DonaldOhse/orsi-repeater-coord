<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();

$results   = [];
$errors    = [];
$imported  = 0;
$skipped   = 0;
$updated   = 0;

// ── Process Upload ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $mode    = $_POST['import_mode'] ?? 'skip';   // skip | update | replace
    $has_hdr = !empty($_POST['has_header']);

    $tmp = $_FILES['csv_file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['csv','txt'])) {
        $errors[] = 'Only CSV/TXT files are supported.';
    } elseif (!is_uploaded_file($tmp)) {
        $errors[] = 'File upload failed.';
    } else {
        $handle = fopen($tmp, 'r');
        $row_num = 0;

        // Expected column order (ORSI format):
        // district, type, mixed_mode, mixed_mode_types, status, private, output_freq, input_freq,
        // callsign, trustee, sponsor, county, city, pl_tone, tone_type, dcs_code, tsq_tone,
        // dmr_color_code, dmr_talk_group, dmr_time_slot, dstar_module, fusion_room, p25_nac,
        // open_system, autopatch, closed_autopatch, skywarn, linked, backup_power,
        // allstar, allstar_node, echolink, echolink_node, internet_link, date_coordinated,
        // last_update, url, notes, latitude, longitude, location_source,
        // antenna_height_agl, tower_height, haat, tx_power_watts, feedline_loss_db,
        // antenna_gain_dbd, erp_watts, contact_name, contact_address, contact_city,
        // contact_state, contact_zip, contact_email, contact_phone

        $col_map = [
            0=>'district', 1=>'type', 2=>'mixed_mode', 3=>'mixed_mode_types',
            4=>'status', 5=>'private', 6=>'output_freq', 7=>'input_freq',
            8=>'callsign', 9=>'trustee', 10=>'sponsor', 11=>'county', 12=>'city',
            13=>'pl_tone', 14=>'tone_type', 15=>'dcs_code', 16=>'tsq_tone',
            17=>'dmr_color_code', 18=>'dmr_talk_group', 19=>'dmr_time_slot',
            20=>'dstar_module', 21=>'fusion_room', 22=>'p25_nac',
            23=>'open_system', 24=>'autopatch', 25=>'closed_autopatch',
            26=>'skywarn', 27=>'linked', 28=>'backup_power',
            29=>'allstar', 30=>'allstar_node', 31=>'echolink', 32=>'echolink_node',
            33=>'internet_link', 34=>'date_coordinated', 35=>'last_update',
            36=>'url', 37=>'notes', 38=>'latitude', 39=>'longitude',
            40=>'location_source', 41=>'antenna_height_agl', 42=>'tower_height',
            43=>'haat', 44=>'tx_power_watts', 45=>'feedline_loss_db',
            46=>'antenna_gain_dbd', 47=>'erp_watts',
            48=>'contact_name', 49=>'contact_address', 50=>'contact_city',
            51=>'contact_state', 52=>'contact_zip', 53=>'contact_email',
            54=>'contact_phone',
            55=>'internal_notes',
        ];

        $insert_stmt = $db->prepare("INSERT INTO repeaters (district,type,status,output_freq,input_freq,callsign,trustee,sponsor,county,city,pl_tone,open_system,autopatch,closed_autopatch,skywarn,linked,internet_link,date_coordinated,last_update,url,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $update_stmt = $db->prepare("UPDATE repeaters SET district=?,type=?,status=?,output_freq=?,input_freq=?,trustee=?,sponsor=?,county=?,city=?,pl_tone=?,open_system=?,autopatch=?,closed_autopatch=?,skywarn=?,linked=?,internet_link=?,date_coordinated=?,last_update=?,url=?,notes=? WHERE callsign=? AND output_freq=?");

        $check_stmt  = $db->prepare("SELECT id FROM repeaters WHERE callsign=? AND output_freq=?");

        if ($mode === 'replace') {
            $db->exec("TRUNCATE TABLE repeaters");
            $results[] = ['info','Existing records cleared (replace mode).'];
        }

        while (($row = fgetcsv($handle, 2000, ',')) !== false) {
            $row_num++;
            // Skip title row (first row if it contains "Oklahoma" or "ORSI")
            if ($row_num === 1 && isset($row[0]) && (stripos($row[0],'oklahoma')!==false || stripos($row[0],'ORSI')!==false)) continue;
            // Skip header row
            if ($has_hdr && $row_num <= 2 && isset($row[3]) && !is_numeric($row[3])) continue;

            if (count($row) < 5) { $skipped++; continue; }

            $d = [];
            foreach ($col_map as $ci => $cn) {
                $v = isset($row[$ci]) ? trim($row[$ci]) : '';
                if (in_array($cn, ['open_system','autopatch','closed_autopatch','skywarn','linked'])) {
                    $d[$cn] = ($v === '1') ? 1 : 0;
                } elseif (in_array($cn, ['output_freq','input_freq','pl_tone'])) {
                    $d[$cn] = is_numeric($v) ? (float)$v : null;
                } elseif (in_array($cn, ['date_coordinated','last_update'])) {
                    if ($v && preg_match('/(\d+)\/(\d+)\/(\d+)/', $v, $m)) {
                        $yr = strlen($m[3])==2 ? (int($m[3])<50?'20':'19').$m[3] : $m[3];
                        $d[$cn] = "$yr-".str_pad($m[1],2,'0',STR_PAD_LEFT).'-'.str_pad($m[2],2,'0',STR_PAD_LEFT);
                    } elseif ($v && preg_match('/\d{4}-\d{2}-\d{2}/', $v)) {
                        $d[$cn] = $v;
                    } else {
                        $d[$cn] = null;
                    }
                } else {
                    $d[$cn] = $v;
                }
            }

            if (!$d['output_freq'] || !$d['input_freq'] || !$d['callsign']) { $skipped++; continue; }

            try {
                if ($mode !== 'replace') {
                    $check_stmt->execute([$d['callsign'], $d['output_freq']]);
                    $exists = $check_stmt->fetchColumn();
                    if ($exists) {
                        if ($mode === 'update') {
                            $update_stmt->execute([
                                $d['district'],$d['type'],$d['status'],$d['output_freq'],$d['input_freq'],
                                $d['trustee'],$d['sponsor'],$d['county'],$d['city'],$d['pl_tone'],
                                $d['open_system'],$d['autopatch'],$d['closed_autopatch'],$d['skywarn'],
                                $d['linked'],$d['internet_link'],$d['date_coordinated'],$d['last_update'],
                                $d['url'],$d['notes'],
                                $d['callsign'],$d['output_freq']
                            ]);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }
                }
                $insert_stmt->execute([
                    $d['district'],$d['type'],$d['status'],$d['output_freq'],$d['input_freq'],
                    $d['callsign'],$d['trustee'],$d['sponsor'],$d['county'],$d['city'],
                    $d['pl_tone'],$d['open_system'],$d['autopatch'],$d['closed_autopatch'],
                    $d['skywarn'],$d['linked'],$d['internet_link'],$d['date_coordinated'],
                    $d['last_update'],$d['url'],$d['notes'],
                ]);
                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row $row_num ({$d['callsign']}): " . $e->getMessage();
            }
        }
        fclose($handle);

        audit('IMPORT','repeaters',0,null,['imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped,'mode'=>$mode]);

        if ($imported || $updated) flash('success',"Import complete: $imported inserted, $updated updated, $skipped skipped.");
        else flash('warning',"Import finished: 0 records inserted. $skipped skipped. Check for errors.");
        header('Location: ' . BASE_PATH . '/admin/import.php');
        exit;
    }
}

$total = (int)$db->query("SELECT COUNT(*) FROM repeaters")->fetchColumn();
$page_title = 'Import Repeaters';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-file-import"></i> Import Repeaters</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

<!-- Upload Form -->
<div class="card">
  <div class="card-header"><i class="fa fa-upload"></i> Upload CSV File</div>
  <div class="card-body">
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

    <form method="post" enctype="multipart/form-data">
      <div class="form-group" style="margin-bottom:14px">
        <label>CSV File *</label>
        <input type="file" name="csv_file" accept=".csv,.txt" required>
        <small class="text-muted">Max <?= ini_get('upload_max_filesize') ?>. UTF-8 or Latin-1 CSV.</small>
      </div>

      <div class="form-group" style="margin-bottom:14px">
        <label>Import Mode</label>
        <select name="import_mode">
          <option value="skip">Skip duplicates (same callsign + output freq)</option>
          <option value="update">Update existing, insert new</option>
          <option value="replace">Replace ALL records (danger!)</option>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:18px">
        <label class="form-check">
          <input type="checkbox" name="has_header" value="1" checked>
          File has header row (ORSI format: district, type, mixed_mode, status, output_freq, input_freq, callsign…)
        </label>
      </div>

      <button type="submit" class="btn btn-primary"><i class="fa fa-file-import"></i> Import</button>
    </form>
  </div>
</div>

<!-- Format Guide -->
<div>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-circle-info"></i> Expected CSV Format (ORSI)</div>
    <div class="card-body" style="font-size:.82rem">
      <p style="margin-bottom:10px">Columns must be in this order (or match ORSI export format):</p>
      <table style="width:100%;border-collapse:collapse;font-size:.8rem">
        <thead><tr style="background:#f4f6f8"><th style="padding:4px 8px;text-align:left">#</th><th style="padding:4px 8px;text-align:left">Column</th><th style="padding:4px 8px;text-align:left">Example</th></tr></thead>
        <tbody>
        <?php
                $cols = [
            ['district','NE'],['type','REPEATER'],['mixed_mode','0'],['mixed_mode_types','DMR,Fusion'],
            ['status','OPERATIONAL'],['private','0'],['output_freq','146.9400'],['input_freq','146.3400'],
            ['callsign','W5XXX'],['trustee','W5XXX'],['sponsor','Tulsa ARC'],['county','TULSA'],['city','Tulsa'],
            ['pl_tone','100.0'],['tone_type','CTCSS'],['dcs_code',''],['tsq_tone',''],
            ['dmr_color_code',''],['dmr_talk_group',''],['dmr_time_slot',''],
            ['dstar_module',''],['fusion_room',''],['p25_nac',''],
            ['open_system','1'],['autopatch','0'],['closed_autopatch','0'],
            ['skywarn','1'],['linked','0'],['backup_power','1'],
            ['allstar','0'],['allstar_node',''],['echolink','0'],['echolink_node',''],
            ['internet_link',''],['date_coordinated','2024-01-15'],['last_update','2024-03-01'],
            ['url','https://example.com'],['notes','Notes here'],
            ['latitude','35.4676'],['longitude','-97.5164'],['location_source','GPS'],
            ['antenna_height_agl','200'],['tower_height',''],['haat','350'],
            ['tx_power_watts','50'],['feedline_loss_db','1.5'],['antenna_gain_dbd','6.0'],['erp_watts',''],['contact_name','John Smith'],
            ['contact_address','123 Main St'],['contact_city','Tulsa'],['contact_state','OK'],
            ['contact_zip','74101'],['contact_email','john@example.com'],['contact_phone','918-555-1234'],
        ];
        foreach ($cols as $i => $c): ?>
        <tr style="border-bottom:1px solid #eee">
          <td style="padding:3px 8px;color:var(--muted)"><?= $i ?></td>
          <td style="padding:3px 8px;font-weight:600"><?= h($c[0]) ?></td>
          <td style="padding:3px 8px;color:var(--muted);font-family:monospace"><?= h($c[1]) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><i class="fa fa-database"></i> Current Database</div>
    <div class="card-body">
      <p><strong><?= number_format($total) ?></strong> repeaters currently in database.</p>
      <p style="margin-top:10px"><a href="<?= BASE_PATH ?>/export.php" class="btn btn-secondary btn-sm"><i class="fa fa-download"></i> Export All as CSV</a></p>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
