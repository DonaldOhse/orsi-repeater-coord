<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$db = get_db();

// QRZ lookup function (reuse from bulk_contact.php)
function qrz_lookup_contact(string $callsign): ?array {
    $login_url = "https://xmldata.qrz.com/xml/current/?username=" . urlencode(QRZ_USERNAME) .
                 "&password=" . urlencode(QRZ_PASSWORD) . "&agent=ORSI-Coord";
    $ctx = stream_context_create(['http'=>['timeout'=>8]]);
    $login_xml = @simplexml_load_file($login_url, "SimpleXMLElement", LIBXML_NOCDATA, "", false);
    if (!$login_xml) return null;
    $key = (string)($login_xml->Session->Key ?? '');
    if (!$key) return null;

    $lookup_url = "https://xmldata.qrz.com/xml/current/?s={$key}&callsign=" . urlencode($callsign);
    $xml = @simplexml_load_file($lookup_url, "SimpleXMLElement", LIBXML_NOCDATA, "", false);
    if (!$xml || !isset($xml->Callsign)) return null;
    $c = $xml->Callsign;
    $fname = (string)($c->fname ?? '');
    $lname = (string)($c->name ?? '');
    $name = trim("$fname $lname");
    return [
        'name'    => $name,
        'email'   => (string)($c->email ?? ''),
        'address' => (string)($c->addr1 ?? ''),
        'city'    => (string)($c->addr2 ?? ''),
        'state'   => (string)($c->state ?? ''),
        'zip'     => (string)($c->zip ?? ''),
    ];
}

// Handle apply single contact
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_id'])) {
    $rep_id      = (int)$_POST['apply_id'];
    $name        = trim($_POST['contact_name'] ?? '');
    $email       = trim($_POST['contact_email'] ?? '');
    $address     = trim($_POST['contact_address'] ?? '');
    $city        = trim($_POST['contact_city'] ?? '');
    $state       = trim($_POST['contact_state'] ?? '');
    $zip         = trim($_POST['contact_zip'] ?? '');

    $db->prepare("UPDATE repeaters SET contact_name=?, contact_email=?, contact_address=?, contact_city=?, contact_state=?, contact_zip=?, last_update=CURDATE() WHERE id=?")
       ->execute([$name ?: null, $email ?: null, $address ?: null, $city ?: null, $state ?: null, $zip ?: null, $rep_id]);
    
    audit('BULK_QRZ_CONTACT', 'repeaters', $rep_id, null, ['contact_name'=>$name, 'contact_email'=>$email]);
    echo json_encode(['success'=>true]);
    exit;
}

// Get repeaters missing contact info
$repeaters = $db->query("
    SELECT id, callsign, trustee, output_freq, city, status, contact_name, contact_email
    FROM repeaters
    WHERE (contact_email IS NULL OR contact_email = '')
    AND trustee IS NOT NULL AND trustee != ''
    AND status NOT IN ('DEAD','DECOORDINATED')
    ORDER BY status ASC, callsign ASC
    LIMIT 200
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title">
    <i class="fa fa-satellite-dish"></i> Bulk QRZ Contact Lookup
</div>

<div class="alert alert-info">
    <i class="fa fa-info-circle"></i>
    Showing <?= count($repeaters) ?> repeaters missing contact email. Click <strong>Lookup QRZ</strong> to fetch contact info, then <strong>Apply</strong> to save.
</div>

<div class="card">
  <div class="card-header">
    <i class="fa fa-list"></i> Repeaters Missing Contact Info
    <span style="margin-left:auto;font-size:.8rem;color:var(--muted)">QRZ lookup uses trustee callsign</span>
  </div>
  <table class="table" id="rep-table">
    <thead>
      <tr>
        <th>Callsign</th>
        <th>Trustee</th>
        <th>Freq</th>
        <th>City</th>
        <th>Status</th>
        <th>QRZ Result</th>
        <th style="width:160px">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($repeaters as $r): ?>
    <tr id="row-<?= $r['id'] ?>">
      <td><strong><?= h($r['callsign']) ?></strong></td>
      <td><code><?= h($r['trustee']) ?></code></td>
      <td><?= number_format((float)$r['output_freq'],4) ?></td>
      <td><?= h($r['city']) ?></td>
      <td><span class="badge badge-<?= $r['status']==='OPERATIONAL'?'success':'secondary' ?>"><?= $r['status'] ?></span></td>
      <td id="result-<?= $r['id'] ?>">
        <span style="color:var(--muted);font-size:.8rem">Not looked up</span>
      </td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="lookupQRZ(<?= $r['id'] ?>, '<?= h($r['trustee']) ?>')">
          <i class="fa fa-satellite-dish"></i> Lookup
        </button>
        <button class="btn btn-sm btn-success" id="apply-<?= $r['id'] ?>" style="display:none" onclick="applyContact(<?= $r['id'] ?>)">
          <i class="fa fa-check"></i> Apply
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="margin:16px 0;display:flex;gap:10px;align-items:center">
  <button class="btn btn-primary" onclick="lookupAll()">
    <i class="fa fa-satellite-dish"></i> Lookup All (slow)
  </button>
  <span id="progress" style="color:var(--muted);font-size:.85rem"></span>
</div>

<script>
const qrzData = {};

async function lookupQRZ(id, callsign) {
  const resultEl = document.getElementById('result-' + id);
  resultEl.innerHTML = '<span style="color:var(--muted)"><i class="fa fa-spinner fa-spin"></i> Looking up ' + callsign + '...</span>';
  
  try {
    const res = await fetch('<?= BASE_PATH ?>/admin/bulk_qrz_contacts.php?ajax=1&id=' + id + '&callsign=' + encodeURIComponent(callsign));
    const data = await res.json();
    
    if (data.success && data.name) {
      qrzData[id] = data;
      resultEl.innerHTML = `
        <div style="font-size:.8rem">
          <strong>${data.name}</strong><br>
          <a href="mailto:${data.email}" style="color:var(--primary-m)">${data.email || '<em>No email</em>'}</a><br>
          <span style="color:var(--muted)">${data.city}${data.state ? ', '+data.state : ''}</span>
        </div>`;
      if (data.email) {
        document.getElementById('apply-' + id).style.display = 'inline-flex';
      }
    } else {
      resultEl.innerHTML = '<span style="color:#dc2626;font-size:.8rem"><i class="fa fa-times"></i> Not found on QRZ</span>';
    }
  } catch(e) {
    resultEl.innerHTML = '<span style="color:#dc2626;font-size:.8rem">Error</span>';
  }
}

async function applyContact(id) {
  const data = qrzData[id];
  if (!data) return;
  
  const btn = document.getElementById('apply-' + id);
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
  btn.disabled = true;

  const form = new FormData();
  form.append('apply_id', id);
  form.append('contact_name', data.name || '');
  form.append('contact_email', data.email || '');
  form.append('contact_address', data.address || '');
  form.append('contact_city', data.city || '');
  form.append('contact_state', data.state || '');
  form.append('contact_zip', data.zip || '');

  const res = await fetch('<?= BASE_PATH ?>/admin/bulk_qrz_contacts.php', {method:'POST', body:form});
  const result = await res.json();
  
  if (result.success) {
    document.getElementById('row-' + id).style.background = '#f0fdf4';
    document.getElementById('row-' + id).style.opacity = '0.6';
    btn.innerHTML = '<i class="fa fa-check"></i> Applied';
    btn.style.background = '#16a34a';
  }
}

async function lookupAll() {
  const rows = document.querySelectorAll('#rep-table tbody tr');
  const btn = event.target;
  btn.disabled = true;
  
  for (let i = 0; i < rows.length; i++) {
    const id = rows[i].id.replace('row-', '');
    const callsign = rows[i].querySelector('code').textContent;
    document.getElementById('progress').textContent = `Looking up ${i+1} of ${rows.length}: ${callsign}`;
    await lookupQRZ(id, callsign);
    await new Promise(r => setTimeout(r, 500)); // rate limit
  }
  document.getElementById('progress').textContent = 'Done!';
  btn.disabled = false;
}
</script>

<?php
// Handle AJAX QRZ lookup
if (isset($_GET['ajax'])) {
    $callsign = strtoupper(trim($_GET['callsign'] ?? ''));
    $data = qrz_lookup_contact($callsign);
    if ($data) {
        echo json_encode(['success'=>true] + $data);
    } else {
        echo json_encode(['success'=>false]);
    }
    exit;
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
