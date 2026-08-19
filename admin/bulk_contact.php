<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
$db = get_db();

$msg = null;
$preview = [];

// QRZ lookup function
function qrz_lookup(string $callsign): ?array {
    // Get session key
    $login_url = "https://xmldata.qrz.com/xml/current/?username=" . urlencode(QRZ_USERNAME) .
                  "&password=" . urlencode(QRZ_PASSWORD) .
                  "&agent=ORSI-Coord-1.0";
    $xml = @simplexml_load_file($login_url);
    if (!$xml) return null;
    $session = $xml->Session ?? null;
    if (!$session || empty($session->Key)) return null;
    $key = (string)$session->Key;

    // Look up callsign
    $lookup_url = "https://xmldata.qrz.com/xml/current/?s={$key}&callsign=" . urlencode($callsign);
    $xml2 = @simplexml_load_file($lookup_url);
    if (!$xml2) return null;
    $c = $xml2->Callsign ?? null;
    if (!$c) return null;

    return [
        'name'    => trim((string)$c->fname . ' ' . (string)$c->name),
        'email'   => (string)$c->email,
        'address' => (string)$c->addr1,
        'city'    => (string)$c->addr2,
        'state'   => (string)$c->state,
        'zip'     => (string)$c->zip,
        'country' => (string)$c->country,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_by = $_POST['search_by'] ?? 'callsign';
    $search_val = strtoupper(trim($_POST['search_val'] ?? ''));
        $action = $_POST['action'] ?? 'preview';

    // QRZ lookup
    if ($action === 'qrz_lookup') {
        $lookup_call = strtoupper(trim($_POST['qrz_call'] ?? $search_val));
        $qrz = qrz_lookup($lookup_call);
        if ($qrz) {
            $_POST['contact_name']    = $qrz['name'];
            $_POST['contact_email']   = $qrz['email'];
            $_POST['contact_address'] = $qrz['address'];
            $_POST['contact_city']    = $qrz['city'];
            $_POST['contact_state']   = $qrz['state'];
            $_POST['contact_zip']     = $qrz['zip'];
            $msg = ['type'=>'success', 'text'=>"QRZ lookup for {$lookup_call} successful! Review fields below then click Apply."];
        } else {
            $msg = ['type'=>'danger', 'text'=>"QRZ lookup failed for {$lookup_call}. Check the callsign or try again."];
        }
        $action = 'preview';
    }

    $allowed_search = ['callsign', 'trustee'];
    if (!in_array($search_by, $allowed_search) || !$search_val) {
        $msg = ['type'=>'danger', 'text'=>'Invalid search.'];
    } else {
        // Fields to update - only what's provided
        $fields = ['trustee','contact_name','contact_email','contact_phone',
                   'contact_address','contact_city','contact_state','contact_zip',
                   'sponsor','url','internal_notes'];

        $set_parts = [];
        $vals = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f]) && $_POST[$f] !== '~~skip~~') {
                $set_parts[] = "$f = ?";
                $vals[] = trim($_POST[$f]) === '' ? null : trim($_POST[$f]);
            }
        }

        // Get matching repeaters for preview
        $stmt = $db->prepare("SELECT id, callsign, trustee, contact_name, contact_email, url, last_update FROM repeaters WHERE archived_at IS NULL AND $search_by = ?");
        $stmt->execute([$search_val]);
        $preview = $stmt->fetchAll();

        if ($action === 'update' && $set_parts && $preview) {
            // UPDATE without touching last_update
            $update_vals = $vals;
            $update_vals[] = $search_val;
            $db->prepare("UPDATE repeaters SET " . implode(', ', $set_parts) . " WHERE $search_by = ? ")
               ->execute($update_vals);
            $count = count($preview);
            $msg = ['type'=>'success', 'text'=>"Updated $count repeater(s) for $search_by = $search_val. last_update was NOT changed."];

            // Refresh preview
            $stmt->execute([$search_val]);
            $preview = $stmt->fetchAll();
        }
    }
}

$page_title = 'Bulk Contact Update';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-address-card"></i> Bulk Contact Update
  <span style="font-size:.75rem;color:var(--muted);font-weight:400;margin-left:8px">- does not modify last_update</span>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom:16px">
  <i class="fa fa-<?= $msg['type']==='success'?'circle-check':'circle-xmark' ?>"></i>
  <?= h($msg['text']) ?>
</div>
<?php endif; ?>

<form method="post" id="main_form">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <!-- Search -->
    <div class="card">
      <div class="card-header"><i class="fa fa-search"></i> Find Repeaters</div>
      <div class="card-body">
        <div class="form-group">
          <label>Search By</label>
          <select name="search_by">
            <option value="callsign" <?= ($_POST['search_by']??'callsign')==='callsign'?'selected':'' ?>>Callsign</option>
            <option value="trustee"  <?= ($_POST['search_by']??'')==='trustee'?'selected':'' ?>>Trustee</option>
          </select>
        </div>
        <div class="form-group">
          <label>Value</label>
          <input type="text" name="search_val" value="<?= h($_POST['search_val'] ?? '') ?>"
            placeholder="e.g. W5MWC" style="text-transform:uppercase">
        </div>
      </div>
    </div>

    <!-- Contact fields -->
    <div class="card">
      <div class="card-header"><i class="fa fa-pen"></i> New Values <span style="font-size:.75rem;font-weight:400;color:var(--muted)">(leave blank to clear, or type ~~skip~~ to keep existing)</span></div>
      <div class="card-body">
        <?php
        $form_fields = [
            'trustee'         => 'Trustee Callsign',
            'contact_name'    => 'Contact Name',
            'contact_email'   => 'Contact Email',
            'contact_phone'   => 'Contact Phone',
            'contact_address' => 'Address',
            'contact_city'    => 'City',
            'contact_state'   => 'State',
            'contact_zip'     => 'ZIP',
            'sponsor'         => 'Sponsor / Club',
            'url'             => 'Website URL',
            'internal_notes'  => 'Internal Notes',
        ];
        foreach ($form_fields as $fname => $flabel):
        ?>
        <div class="form-group" style="margin-bottom:8px">
          <label style="font-size:.8rem"><?= $flabel ?></label>
          <input type="text" name="<?= $fname ?>" value="<?= h($_POST[$fname] ?? '~~skip~~') ?>"
            style="font-size:.85rem" <?= $fname==='contact_state'?'maxlength="2"':'' ?>>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- QRZ Lookup -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-satellite-dish"></i> QRZ.com Lookup - Auto-fill Contact Info</div>
    <div class="card-body" style="display:flex;gap:10px;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1">
        <label>Lookup Callsign on QRZ</label>
        <input type="text" name="qrz_call" form="main_form"
          value="<?= h($_POST['qrz_call'] ?? $_POST['search_val'] ?? '') ?>"
          placeholder="e.g. W5DRO" style="text-transform:uppercase">
      </div>
      <button type="submit" name="action" value="qrz_lookup" form="main_form" class="btn btn-primary">
        <i class="fa fa-satellite-dish"></i> Lookup on QRZ
      </button>
    </div>
  </div>

  <!-- Preview / Update buttons -->
  <div style="display:flex;gap:10px;margin:16px 0">
    <button type="submit" name="action" value="preview" class="btn btn-secondary">
      <i class="fa fa-eye"></i> Preview Matches
    </button>
    <?php if ($preview): ?>
    <button type="submit" name="action" value="update" class="btn btn-warning"
      onclick="return confirm('Update <?= count($preview) ?> repeater(s)? last_update will NOT be changed.')">
      <i class="fa fa-save"></i> Apply to <?= count($preview) ?> Repeater(s)
    </button>
    <?php endif; ?>
  </div>
</form>

<!-- Preview table -->
<?php if ($preview): ?>
<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> Matching Repeaters (<?= count($preview) ?>)</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>ID</th><th>Callsign</th><th>Trustee</th><th>Contact</th><th>Email</th><th>URL</th><th>Last Update</th></tr></thead>
      <tbody>
      <?php foreach ($preview as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><a href="<?= BASE_PATH ?>/repeater.php?id=<?= $r['id'] ?>"><?= h($r['callsign']) ?></a></td>
        <td><?= h($r['trustee']) ?></td>
        <td><?= h($r['contact_name'] ?: '-') ?></td>
        <td><?= h($r['contact_email'] ?: '-') ?></td>
        <td><?= $r['url'] ? '<a href="'.h($r['url']).'" target="_blank">'.h($r['url']).'</a>' : '-' ?></td>
        <td style="color:var(--muted);font-size:.8rem"><?= $r['last_update'] ?: '-' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
