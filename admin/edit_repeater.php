<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db = get_db();

$id  = (int)($_GET['id'] ?? 0);
$rep = [];
if ($id) {
    $s = $db->prepare("SELECT * FROM repeaters WHERE archived_at IS NULL AND id = ?");
    $s->execute([$id]);
    $rep = $s->fetch() ?: [];
    if (!$rep) { flash('danger','Repeater not found.'); header('Location: ' . BASE_PATH . '/index.php'); exit; }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'district'         => trim($_POST['district']         ?? ''),
        'type'             => trim($_POST['type']             ?? 'REPEATER'),
        'mixed_mode'       => isset($_POST['mixed_mode']) ? 1 : 0,
        'mixed_mode_types' => !empty($_POST['mixed_mode_types']) ? implode(',', $_POST['mixed_mode_types']) : null,
        'status'           => trim($_POST['status']           ?? 'PROPOSED'),
        'private'          => isset($_POST['private'])          ? 1 : 0,
        'output_freq'      => trim($_POST['output_freq']      ?? ''),
        'input_freq'       => trim($_POST['input_freq']       ?? ''),
        'callsign'         => strtoupper(trim($_POST['callsign'] ?? '')),
        'trustee'          => strtoupper(trim($_POST['trustee']  ?? '')),
        'sponsor'          => trim($_POST['sponsor']          ?? ''),
        'county'           => strtoupper(trim($_POST['county']   ?? '')),
        'city'             => trim($_POST['city']             ?? ''),
        'pl_tone'          => trim($_POST['pl_tone'] ?? '') !== '' ? (float)$_POST['pl_tone'] : null,
        'tone_type'        => trim($_POST['tone_type']        ?? 'CARRIER'),
        'dcs_code'         => trim($_POST['dcs_code']         ?? '') ?: null,
        'tsq_tone'         => trim($_POST['tsq_tone'] ?? '') !== '' ? (float)$_POST['tsq_tone'] : null,
        'dmr_color_code'      => trim($_POST['dmr_color_code'] ?? '') !== '' ? (int)$_POST['dmr_color_code'] : null,
        'dmr_talk_group'      => trim($_POST['dmr_talk_group']   ?? '') ?: null,
        'dmr_time_slot'       => trim($_POST['dmr_time_slot'] ?? '') !== '' ? (int)$_POST['dmr_time_slot'] : null,
        'dmr_ts1_talk_groups' => trim($_POST['dmr_ts1_talk_groups'] ?? '') ?: null,
        'dmr_ts2_talk_groups' => trim($_POST['dmr_ts2_talk_groups'] ?? '') ?: null,
        'dmr_network'         => trim($_POST['dmr_network'] ?? '') ?: null,
        'dstar_module'     => strtoupper(trim($_POST['dstar_module'] ?? '')) ?: null,
        'fusion_room'      => trim($_POST['fusion_room']      ?? '') ?: null,
        'p25_nac'          => trim($_POST['p25_nac']          ?? '') ?: null,
        'open_system'      => isset($_POST['open_system'])      ? 1 : 0,
        'autopatch'        => isset($_POST['autopatch'])        ? 1 : 0,
        'closed_autopatch' => isset($_POST['closed_autopatch']) ? 1 : 0,
        'skywarn'          => isset($_POST['skywarn'])          ? 1 : 0,
        'linked'           => isset($_POST['linked'])           ? 1 : 0,
        'backup_power'     => isset($_POST['backup_power'])     ? 1 : 0,
        'allstar'          => isset($_POST['allstar'])          ? 1 : 0,
        'allstar_node'     => trim($_POST['allstar_node']      ?? '') ?: null,
        'echolink'         => isset($_POST['echolink'])         ? 1 : 0,
        'echolink_node'    => trim($_POST['echolink_node']     ?? '') ?: null,
        'internet_link'    => trim($_POST['internet_link']    ?? ''),
        'date_coordinated' => trim($_POST['date_coordinated'] ?? '') ?: null,
        'last_update'      => date('Y-m-d'), // always set to today on save
        'url'              => trim($_POST['url']              ?? ''),
        'notes'            => trim($_POST['notes']            ?? ''),
        'internal_notes'   => trim($_POST['internal_notes']   ?? '') ?: null,
        'contact_name'     => trim($_POST['contact_name']     ?? '') ?: null,
        'contact_address'  => trim($_POST['contact_address']  ?? '') ?: null,
        'contact_email'    => trim($_POST['contact_email']    ?? '') ?: null,
        'contact_phone'    => trim($_POST['contact_phone']    ?? '') ?: null,
        'contact_city'     => trim($_POST['contact_city']     ?? '') ?: null,
        'contact_state'    => strtoupper(trim($_POST['contact_state'] ?? '')) ?: null,
        'contact_zip'      => trim($_POST['contact_zip']      ?? '') ?: null,
        'latitude'         => trim($_POST['latitude']         ?? '') ?: null,
        'longitude'        => trim($_POST['longitude']        ?? '') ?: null,
        'antenna_height_agl' => trim($_POST['antenna_height_agl'] ?? '') !== '' ? (float)$_POST['antenna_height_agl'] : null,
        'tower_height'       => trim($_POST['tower_height']       ?? '') !== '' ? (float)$_POST['tower_height']       : null,
        'haat'               => trim($_POST['haat']               ?? '') !== '' ? (float)$_POST['haat']               : null,
        'tx_power_watts'     => trim($_POST['tx_power_watts']     ?? '') !== '' ? (float)$_POST['tx_power_watts']     : null,
        'feedline_loss_db'   => trim($_POST['feedline_loss_db']   ?? '') !== '' ? (float)$_POST['feedline_loss_db']   : null,
        'antenna_gain_dbd'   => trim($_POST['antenna_gain_dbd']   ?? '') !== '' ? (float)$_POST['antenna_gain_dbd']   : null,
        'erp_watts'          => trim($_POST['erp_watts']          ?? '') !== '' ? (float)$_POST['erp_watts']          : null,
    ];

    if (!$fields['output_freq'] || !is_numeric($fields['output_freq'])) $errors[] = 'Output frequency is required and must be numeric.';
    if (!$fields['input_freq']  || !is_numeric($fields['input_freq']))  $errors[] = 'Input frequency is required and must be numeric.';
    if (!$fields['callsign'])   $errors[] = 'Callsign is required.';
    if (!$fields['district'])   $errors[] = 'District is required.';
    if ($fields['allstar'] && !$fields['allstar_node']) $errors[] = 'AllStar node number is required when AllStar is checked.';
    // Validate coordinates for Oklahoma
    if ($fields['latitude'] !== null) {
        $lat = (float)$fields['latitude'];
        if ($lat < 33.0 || $lat > 37.5) $errors[] = 'Latitude appears out of range for Oklahoma (33.0 to 37.5). Got: ' . $lat;
    }
    if ($fields['longitude'] !== null) {
        $lon = (float)$fields['longitude'];
        if ($lon > 0 && $lon > 94.0 && $lon < 103.5) {
            $errors[] = 'Longitude must be negative for Oklahoma (e.g. -97.464, not 97.464). Got: ' . $lon;
        } elseif ($lon > -94.0 || $lon < -103.5) {
            $errors[] = 'Longitude appears out of range for Oklahoma (-103.5 to -94.0). Got: ' . $lon;
        }
    }

    if (!$errors) {
        $cols = array_keys($fields);
        if ($id) {
            $old = $rep;
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            $db->prepare("UPDATE repeaters SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
            audit('UPDATE', 'repeaters', $id, $old, $fields);
            flash('success', 'Repeater updated successfully.');
        } else {
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $db->prepare("INSERT INTO repeaters (" . implode(',',$cols) . ") VALUES ($ph)")->execute(array_values($fields));
            $new_id = (int)$db->lastInsertId();
            audit('INSERT', 'repeaters', $new_id, null, $fields);
            flash('success', 'Repeater added successfully.');
            header("Location: " . BASE_PATH . "/repeater.php?id=$new_id"); exit;
        }
        header("Location: " . BASE_PATH . "/repeater.php?id=$id"); exit;
    }
    $rep = array_merge($rep, $fields);
}

$page_title = $id ? 'Edit Repeater' : 'Add Repeater';
$districts = ['NE','NW','OKC','SE','SW','TUL'];
$types     = ['REPEATER','D-STAR','DMR','FUSION','P-25','ATV'];
$statuses  = ['OPERATIONAL','PROPOSED','CONSTRUCTION','DOWN TEMPORARILY','DEAD','DECOORDINATED','UNCOORDINATED','UNKNOWN','ADMIN HOLD - LICENSE EXPIRED','ADMIN HOLD - HOLDER DECEASED','TRUSTEE CHANGE REQUIRED'];
$ctcss_tones = ['67.0','69.3','71.9','74.4','77.0','79.7','82.5','85.4','88.5','91.5','94.8','97.4','100.0','103.5','107.2','110.9','114.8','118.8','123.0','127.3','131.8','136.5','141.3','146.2','151.4','156.7','162.2','167.9','173.8','179.9','186.2','192.8','203.5','210.7','218.1','225.7','233.6','241.8','250.3','254.1'];
$dcs_codes = ['023','025','026','031','032','036','043','047','051','053','054','065','071','072','073','074','114','115','116','122','125','131','132','134','143','145','152','155','156','162','165','172','174','205','212','223','225','226','243','244','245','246','251','252','255','261','263','265','266','271','274','306','311','315','325','331','332','343','346','351','356','364','365','371','411','412','413','423','431','432','445','446','452','454','455','462','464','465','466','503','506','516','523','526','532','546','565','606','612','624','627','631','632','654','662','664','703','712','723','731','732','734','743','754'];

function fval(array $rep, string $k, mixed $default=''): string {
    return htmlspecialchars((string)($rep[$k] ?? $default), ENT_QUOTES, 'UTF-8');
}
function fsel(array $rep, string $k, string $val): string {
    return ($rep[$k] ?? '') === $val ? 'selected' : '';
}
function fchk(array $rep, string $k): string {
    return !empty($rep[$k]) ? 'checked' : '';
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
  <a href="<?= $id ? BASE_PATH.'/repeater.php?id='.$id : BASE_PATH.'/index.php' ?>" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
  <div class="page-title" style="margin:0;border:none;padding:0"><i class="fa fa-<?= $id?'pen':'plus' ?>"></i> <?= $page_title ?></div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" id="repForm">

  <!-- Frequency & Identity -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header"><i class="fa fa-tower-broadcast"></i> Frequency &amp; Identity</div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Output Freq (MHz) *</label>
          <input type="number" name="output_freq" value="<?= fval($rep,'output_freq') ?>" step="0.0001" placeholder="146.520" required>
        </div>
        <div class="form-group">
          <label>Input Freq (MHz) *</label>
          <input type="number" name="input_freq" value="<?= fval($rep,'input_freq') ?>" step="0.0001" placeholder="146.920" required>
        </div>
        <div class="form-group">
          <label>Callsign *</label>
          <input type="text" name="callsign" value="<?= fval($rep,'callsign') ?>" maxlength="20" style="text-transform:uppercase" required>
        </div>
        <div class="form-group">
          <label>Trustee</label>
          <input type="text" name="trustee" value="<?= fval($rep,'trustee') ?>" maxlength="20" style="text-transform:uppercase">
        </div>
        <div class="form-group">
          <label>Sponsor / Club</label>
          <input type="text" name="sponsor" value="<?= fval($rep,'sponsor') ?>" maxlength="100">
        </div>
        <div class="form-group">
          <label>Type *</label>
          <select name="type" id="typeSelect" onchange="updateAccessFields()">
            <?php foreach ($types as $t): ?><option value="<?= h($t) ?>" <?= fsel($rep,'type',$t) ?>><?= h($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Internet Link</label>
          <input type="text" name="internet_link" value="<?= fval($rep,'internet_link') ?>" maxlength="50" placeholder="IRLP, EchoLink, WiresX…">
        </div>
      <div class="form-group" style="grid-column:1/-1;margin-top:4px">
        <label class="form-check" style="margin-bottom:8px;font-weight:600">
          <input type="checkbox" name="mixed_mode" value="1" <?= fchk($rep,'mixed_mode') ?> onchange="toggleMixedMode(this)">
          Mixed Mode - supports multiple digital/analog modes simultaneously
        </label>
        <div id="mixedModeField" style="<?= !empty($rep['mixed_mode']) ? '' : 'display:none' ?>padding:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius);margin-top:6px">
          <label style="font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:8px">Select all supported modes:</label>
          <div style="display:flex;flex-wrap:wrap;gap:16px">
            <?php foreach (['FM','DMR','D-STAR','FUSION','P-25','NXDN','ATV'] as $mode): ?>
            <label class="form-check">
              <input type="checkbox" name="mixed_mode_types[]" value="<?= $mode ?>"
                <?= (str_contains($rep['mixed_mode_types'] ?? '', $mode)) ? 'checked' : '' ?>
                onchange="updateAccessFields()">
              <?= $mode ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>

  <!-- Access Codes -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header"><i class="fa fa-key"></i> Access Codes</div>
    <div class="card-body">

      <!-- Primary / FM Analog access - always shown for REPEATER/ATV, or when FM is in mixed modes -->
      <div id="analogFields">
        <h4 style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">FM / Analog Access</h4>
        <div class="form-grid">
          <div class="form-group">
            <label>Tone Type</label>
            <select name="tone_type" id="toneTypeSelect" onchange="updateToneFields()">
              <option value="CARRIER" <?= fsel($rep,'tone_type','CARRIER') ?>>Carrier Squelch (No Tone)</option>
              <option value="CTCSS"   <?= fsel($rep,'tone_type','CTCSS')   ?>>CTCSS / PL Tone</option>
              <option value="TSQ"     <?= fsel($rep,'tone_type','TSQ')     ?>>TSQ (Tone Squelch)</option>
              <option value="DCS"     <?= fsel($rep,'tone_type','DCS')     ?>>DCS (Digital Coded Squelch)</option>
            </select>
          </div>
          <div class="form-group" id="ctcssField" style="display:none">
            <label>CTCSS / PL Tone (Hz)</label>
            <select name="pl_tone">
              <option value="">- Select Tone -</option>
              <?php foreach ($ctcss_tones as $t): ?>
              <option value="<?= $t ?>" <?= (string)($rep['pl_tone'] ?? '') == $t ? 'selected' : '' ?>><?= $t ?> Hz</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="tsqField" style="display:none">
            <label>TSQ Tone (Hz)</label>
            <select name="tsq_tone">
              <option value="">- Select Tone -</option>
              <?php foreach ($ctcss_tones as $t): ?>
              <option value="<?= $t ?>" <?= (string)($rep['tsq_tone'] ?? '') == $t ? 'selected' : '' ?>><?= $t ?> Hz</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="dcsField" style="display:none">
            <label>DCS Code</label>
            <select name="dcs_code">
              <option value="">- Select Code -</option>
              <?php foreach ($dcs_codes as $c): ?>
              <option value="<?= $c ?>" <?= ($rep['dcs_code'] ?? '') == $c ? 'selected' : '' ?>>D<?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <!-- DMR fields -->
      <div id="dmrFields" style="display:none">
        <h4 style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 10px">DMR Access</h4>
        <div class="form-grid">
          <div class="form-group">
            <label>Color Code (0–15)</label>
            <input type="number" name="dmr_color_code" value="<?= fval($rep,'dmr_color_code') ?>" min="0" max="15" placeholder="0">
          </div>
          <div class="form-group">
            <label>DMR Network</label>
            <input type="text" name="dmr_network" value="<?= fval($rep,'dmr_network') ?>" placeholder="e.g. BrandMeister, DMR-MARC">
          </div>
        </div>
        <div style="background:#f0f4f8;border-radius:6px;padding:12px;margin-top:8px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div>
              <h5 style="font-size:.8rem;font-weight:700;color:var(--primary);margin:0 0 8px">
                <i class="fa fa-1"></i> Time Slot 1
              </h5>
              <div class="form-group">
                <label style="font-size:.8rem">Talk Groups (comma separated)</label>
                <input type="text" name="dmr_ts1_talk_groups"
                  value="<?= fval($rep,'dmr_ts1_talk_groups') ?>"
                  placeholder="e.g. 3100, 31408, 31084">
                <small style="color:var(--muted)">List all talk groups active on TS1</small>
              </div>
            </div>
            <div>
              <h5 style="font-size:.8rem;font-weight:700;color:var(--primary);margin:0 0 8px">
                <i class="fa fa-2"></i> Time Slot 2
              </h5>
              <div class="form-group">
                <label style="font-size:.8rem">Talk Groups (comma separated)</label>
                <input type="text" name="dmr_ts2_talk_groups"
                  value="<?= fval($rep,'dmr_ts2_talk_groups') ?>"
                  placeholder="e.g. 9, 9990, Local">
                <small style="color:var(--muted)">List all talk groups active on TS2</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- D-STAR fields -->
      <div id="dstarFields" style="display:none">
        <h4 style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 10px">D-STAR Access</h4>
        <div class="form-grid">
          <div class="form-group">
            <label>D-STAR Module</label>
            <select name="dstar_module">
              <option value="">- Select -</option>
              <option value="A" <?= ($rep['dstar_module'] ?? '') === 'A' ? 'selected' : '' ?>>Module A (1.2 GHz)</option>
              <option value="B" <?= ($rep['dstar_module'] ?? '') === 'B' ? 'selected' : '' ?>>Module B (440 MHz)</option>
              <option value="C" <?= ($rep['dstar_module'] ?? '') === 'C' ? 'selected' : '' ?>>Module C (144 MHz)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Fusion fields -->
      <div id="fusionFields" style="display:none">
        <h4 style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 10px">Fusion / C4FM Access</h4>
        <div class="form-grid">
          <div class="form-group">
            <label>Wires-X Room / Reflector</label>
            <input type="text" name="fusion_room" value="<?= fval($rep,'fusion_room') ?>" placeholder="e.g. 12345">
          </div>
        </div>
      </div>

      <!-- P25 fields -->
      <div id="p25Fields" style="display:none">
        <h4 style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 10px">P-25 Access</h4>
        <div class="form-grid">
          <div class="form-group">
            <label>NAC (Network Access Code)</label>
            <input type="text" name="p25_nac" value="<?= fval($rep,'p25_nac') ?>" placeholder="e.g. 293 (hex)">
          </div>
        </div>
      </div>

    </div>
  </div>
  <!-- Location -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header"><i class="fa fa-map-marker-alt"></i> Location &amp; Coordination</div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>District *</label>
          <select name="district">
            <option value="">- Select -</option>
            <?php foreach ($districts as $d): ?><option value="<?= h($d) ?>" <?= fsel($rep,'district',$d) ?>><?= h($d) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Status *</label>
          <select name="status">
            <?php foreach ($statuses as $st): ?><option value="<?= h($st) ?>" <?= fsel($rep,'status',$st) ?>><?= h($st) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>County</label>
          <input type="text" name="county" value="<?= fval($rep,'county') ?>" maxlength="50" style="text-transform:uppercase">
        </div>
        <div class="form-group">
          <label>City</label>
          <input type="text" name="city" value="<?= fval($rep,'city') ?>" maxlength="60">
        </div>
        <div class="form-group">
          <label>Latitude (decimal)</label>
          <input type="number" name="latitude" value="<?= fval($rep,'latitude') ?>" step="0.000001" placeholder="35.4675">
        </div>
        <div class="form-group">
          <label>Longitude (decimal)</label>
          <input type="number" name="longitude" value="<?= fval($rep,'longitude') ?>" step="0.000001" placeholder="-97.5164">
        </div>
        <div class="form-group">
          <label>Date Coordinated</label>
          <input type="date" name="date_coordinated" value="<?= fval($rep,'date_coordinated') ?>">
        </div>
        <div class="form-group">
          <label>Last Update</label>
          <input type="date" name="last_update" value="<?= fval($rep,'last_update') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Website URL</label>
          <input type="url" name="url" value="<?= fval($rep,'url') ?>" maxlength="255" placeholder="https://…">
        </div>
      </div>
    </div>
  </div>

  <!-- Features -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header"><i class="fa fa-sliders"></i> Features</div>
    <div class="card-body">
      <div style="background:#fef9c3;border:1px solid #d97706;border-radius:4px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px">
        <label class="form-check" style="font-weight:700;color:#92400e">
          <input type="checkbox" name="private" value="1" <?= fchk($rep,'private') ?>>
          <i class="fa fa-lock"></i> Private - Hide from public listing and map
        </label>
        <span style="font-size:.78rem;color:#92400e">Coordinators and admins can still see this repeater.</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:16px">
        <label class="form-check"><input type="checkbox" name="open_system"      value="1" <?= fchk($rep,'open_system') ?>> Open System</label>
        <label class="form-check"><input type="checkbox" name="autopatch"        value="1" <?= fchk($rep,'autopatch') ?>> Auto-Patch</label>
        <label class="form-check"><input type="checkbox" name="closed_autopatch" value="1" <?= fchk($rep,'closed_autopatch') ?>> Closed Auto-Patch</label>
        <label class="form-check"><input type="checkbox" name="skywarn"          value="1" <?= fchk($rep,'skywarn') ?>> SKYWARN</label>
        <label class="form-check"><input type="checkbox" name="linked"           value="1" <?= fchk($rep,'linked') ?>> Linked</label>
        <label class="form-check"><input type="checkbox" name="backup_power"     value="1" <?= fchk($rep,'backup_power') ?>> Backup Power</label>
        <label class="form-check"><input type="checkbox" name="allstar"     value="1" <?= fchk($rep,'allstar') ?> onchange="toggleAllstar(this)"> AllStar</label>
        <label class="form-check"><input type="checkbox" name="echolink"   value="1" <?= fchk($rep,'echolink') ?> onchange="toggleEcholink(this)"> EchoLink</label>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:12px">
        <div id="allstarNodeField" style="<?= !empty($rep['allstar']) ? '' : 'display:none' ?>">
          <div class="form-group">
            <label>AllStar Node Number</label>
            <input type="text" name="allstar_node" value="<?= fval($rep,'allstar_node') ?>" maxlength="10" placeholder="e.g. 12345" style="width:200px">
          </div>
        </div>
        <div id="echolinkNodeField" style="<?= !empty($rep['echolink']) ? '' : 'display:none' ?>">
          <div class="form-group">
            <label>EchoLink Node Number</label>
            <input type="text" name="echolink_node" value="<?= fval($rep,'echolink_node') ?>" maxlength="10" placeholder="e.g. 12345" style="width:200px">
          </div>
        </div>
      </div>
    </div>
  </div>


  <!-- RF Parameters -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header" style="background:#f0f4f8"><i class="fa fa-broadcast-tower"></i> RF Parameters &amp; HAAT</div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Antenna Height AGL (ft)</label>
          <input type="number" name="antenna_height_agl" id="agl" value="<?= fval($rep,'antenna_height_agl') ?>" step="0.1" placeholder="e.g. 120" onchange="calcERP()">
        </div>
        <div class="form-group">
          <label>Tower / Structure Height (ft)</label>
          <input type="number" name="tower_height" value="<?= fval($rep,'tower_height') ?>" step="0.1" placeholder="e.g. 150">
        </div>
        <div class="form-group">
          <label>HAAT (ft) <span style="font-size:.75rem;color:var(--muted)">(Height Above Avg Terrain)</span></label>
          <input type="number" name="haat" id="haat" value="<?= fval($rep,'haat') ?>" step="0.1" placeholder="e.g. 340">
          <?php if ($rep['latitude'] && $rep['longitude']): ?>
          <small><a href="#" onclick="estimateHAAT(); return false;"><i class="fa fa-calculator"></i> Estimate from terrain</a></small>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label>TX Power Output (watts)</label>
          <input type="number" name="tx_power_watts" id="txpwr" value="<?= fval($rep,'tx_power_watts') ?>" step="0.1" placeholder="e.g. 50" onchange="calcERP()">
        </div>
        <div class="form-group">
          <label>Feedline Loss (dB)</label>
          <input type="number" name="feedline_loss_db" id="feedloss" value="<?= fval($rep,'feedline_loss_db') ?>" step="0.1" placeholder="e.g. 2.5" onchange="calcERP()">
        </div>
        <div class="form-group">
          <label>Antenna Gain (dBd)</label>
          <input type="number" name="antenna_gain_dbd" id="antgain" value="<?= fval($rep,'antenna_gain_dbd') ?>" step="0.1" placeholder="e.g. 6.0" onchange="calcERP()">
        </div>
        <div class="form-group">
          <label>ERP (watts) <span style="font-size:.75rem;color:var(--muted)">Auto-calculated</span></label>
          <input type="number" name="erp_watts" id="erp" value="<?= fval($rep,'erp_watts') ?>" step="0.001" placeholder="Auto-calculated">
          <small style="color:var(--muted)">ERP = TX Power &times; 10^((Gain &minus; Loss) / 10)</small>
        </div>
        <div class="form-group">
          <label>Est. Coverage Radius</label>
          <div id="coverage_est" style="padding:7px 10px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:var(--radius);font-size:.85rem;color:var(--muted)">Enter HAAT and ERP to calculate</div>
        </div>
      </div>
      <?php if ($id): ?>
      <div style="margin-top:12px;display:flex;gap:8px">
        <a href="<?= BASE_PATH ?>/admin/splat_export.php?id=<?= $id ?>" class="btn btn-secondary btn-sm"><i class="fa fa-file-export"></i> Export SPLAT! Files</a>
        <a href="<?= BASE_PATH ?>/kml_export.php?id=<?= $id ?>" class="btn btn-secondary btn-sm"><i class="fa fa-map"></i> Export KML</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <!-- Notes -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header"><i class="fa fa-sticky-note"></i> Public Notes</div>
    <div class="card-body">
      <div class="form-group">
        <textarea name="notes" rows="4" style="width:100%;resize:vertical"><?= fval($rep,'notes') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Contact Information -->
  <div class="card" style="margin-bottom:14px;border-left:4px solid #dc2626">
    <div class="card-header" style="background:#fef2f2;color:#991b1b"><i class="fa fa-lock"></i> Contact Information <small style="font-weight:400">(Coordinators &amp; Admins only - never shown publicly)</small></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Contact Name</label>
          <input type="text" name="contact_name" value="<?= fval($rep,'contact_name') ?>" maxlength="100" placeholder="Full name of trustee/contact">
        </div>
        <div class="form-group">
          <label>Phone</label>
          <input type="tel" name="contact_phone" value="<?= fval($rep,'contact_phone') ?>" maxlength="20" placeholder="e.g. 405-555-1234">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="contact_email" value="<?= fval($rep,'contact_email') ?>" maxlength="150" placeholder="email@example.com">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Street Address</label>
          <input type="text" name="contact_address" value="<?= fval($rep,'contact_address') ?>" maxlength="255" placeholder="123 Main St">
        </div>
        <div class="form-group">
          <label>City</label>
          <input type="text" name="contact_city" value="<?= fval($rep,'contact_city') ?>" maxlength="60" placeholder="Oklahoma City">
        </div>
        <div class="form-group">
          <label>State</label>
          <input type="text" name="contact_state" value="<?= fval($rep,'contact_state') ?>" maxlength="2" placeholder="OK" style="text-transform:uppercase;max-width:80px">
        </div>
        <div class="form-group">
          <label>ZIP Code</label>
          <input type="text" name="contact_zip" value="<?= fval($rep,'contact_zip') ?>" maxlength="10" placeholder="74000">
        </div>
      </div>
    </div>
  </div>

  <!-- Internal Notes -->
  <div class="card" style="margin-bottom:14px;border-left:4px solid #d97706">
    <div class="card-header" style="background:#fffbeb;color:#92400e"><i class="fa fa-lock"></i> Internal Notes <small style="font-weight:400">(Coordinators &amp; Admins only - not shown publicly)</small></div>
    <div class="card-body">
      <div class="form-group">
        <textarea name="internal_notes" rows="4" style="width:100%;resize:vertical;background:#fffbeb"><?= fval($rep,'internal_notes') ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> <?= $id ? 'Save Changes' : 'Add Repeater' ?></button>
    <a href="<?= $id ? BASE_PATH.'/repeater.php?id='.$id : BASE_PATH.'/index.php' ?>" class="btn btn-secondary">Cancel</a>
    <?php if ($id && $user['role']==='admin'): ?>
    <a href="<?= BASE_PATH ?>/admin/delete_repeater.php?id=<?= $id ?>" class="btn btn-danger" style="margin-left:auto"
       data-confirm="Permanently delete this repeater? This cannot be undone.">
      <i class="fa fa-trash"></i> Delete
    </a>
    <?php endif; ?>
  </div>

</form>

<script>
function toggleMixedMode(cb) {
  document.getElementById('mixedModeField').style.display = cb.checked ? '' : 'none';
  updateAccessFields();
}

function getActiveModes() {
  const type = document.getElementById('typeSelect').value;
  const mixed = document.querySelector('[name=mixed_mode]')?.checked;
  const modes = new Set();

  // Always add primary type
  modes.add(type);

  // Add mixed mode types if checked
  if (mixed) {
    document.querySelectorAll('[name="mixed_mode_types[]"]').forEach(cb => {
      if (cb.checked) modes.add(cb.value);
    });
  }
  return modes;
}

function updateAccessFields() {
  const modes = getActiveModes();

  // FM/Analog shown for REPEATER, ATV, or FM in mixed modes
  const showAnalog = modes.has('REPEATER') || modes.has('ATV') || modes.has('FM');
  document.getElementById('analogFields').style.display = showAnalog ? '' : 'none';

  // Add section headers only when multiple modes active
  const multiMode = modes.size > 1 || (modes.has('REPEATER') && document.querySelector('[name=mixed_mode]')?.checked);
  document.querySelectorAll('.access-section-header').forEach(h => {
    h.style.display = multiMode ? '' : 'none';
  });

  document.getElementById('dmrFields').style.display    = modes.has('DMR')    ? '' : 'none';
  document.getElementById('dstarFields').style.display  = modes.has('D-STAR') ? '' : 'none';
  document.getElementById('fusionFields').style.display = modes.has('FUSION') ? '' : 'none';
  document.getElementById('p25Fields').style.display    = modes.has('P-25')   ? '' : 'none';
}

function updateToneFields() {
  const tone = document.getElementById('toneTypeSelect').value;
  document.getElementById('ctcssField').style.display = tone === 'CTCSS' ? '' : 'none';
  document.getElementById('tsqField').style.display   = tone === 'TSQ'   ? '' : 'none';
  document.getElementById('dcsField').style.display   = tone === 'DCS'   ? '' : 'none';
}

function toggleAllstar(cb) {
  document.getElementById('allstarNodeField').style.display = cb.checked ? '' : 'none';
  if (!cb.checked) document.querySelector('[name=allstar_node]').value = '';
}

function toggleEcholink(cb) {
  document.getElementById('echolinkNodeField').style.display = cb.checked ? '' : 'none';
  if (!cb.checked) document.querySelector('[name=echolink_node]').value = '';
}

updateAccessFields();
updateToneFields();

document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
