<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
$db = get_db();

$msg = null;

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['template_key'])) {
    $key     = trim($_POST['template_key']);
    $subject = trim($_POST['subject']);
    $body    = trim($_POST['body']);
    if ($key && $subject && $body) {
        $db->prepare("UPDATE email_templates SET subject=?, body=?, updated_by=? WHERE template_key=?")
           ->execute([$subject, $body, $_SESSION['user_id'], $key]);
        $msg = ['type'=>'success', 'text'=>'Template saved successfully.'];
    }
}

// Load all templates
$templates = $db->query("SELECT * FROM email_templates ORDER BY id")->fetchAll();

// Load selected template
$sel_key = $_GET['key'] ?? $_POST['template_key'] ?? ($templates[0]['template_key'] ?? '');
$sel = null;
foreach ($templates as $t) {
    if ($t['template_key'] === $sel_key) { $sel = $t; break; }
}

$page_title = 'Email Templates';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-envelope-open-text"></i> Email Templates</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom:16px">
  <i class="fa fa-circle-check"></i> <?= h($msg['text']) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start">

  <!-- Template list -->
  <div class="card">
    <div class="card-header"><i class="fa fa-list"></i> Templates</div>
    <?php foreach ($templates as $t): ?>
    <a href="?key=<?= urlencode($t['template_key']) ?>"
       style="display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;<?= $sel_key===$t['template_key']?'background:var(--primary);color:#fff':'' ?>">
      <div style="font-weight:600;<?= $sel_key===$t['template_key']?'color:#fff':'' ?>"><?= h($t['description']) ?></div>
      <div style="font-size:.75rem;<?= $sel_key===$t['template_key']?'color:rgba(255,255,255,.7)':'color:var(--muted)' ?>"><?= h($t['template_key']) ?></div>
      <?php if ($t['updated_at']): ?>
      <div style="font-size:.7rem;<?= $sel_key===$t['template_key']?'color:rgba(255,255,255,.6)':'color:var(--muted)' ?>">Updated: <?= substr($t['updated_at'],0,10) ?></div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Edit form -->
  <?php if ($sel): ?>
  <div>
    <div class="card" style="margin-bottom:12px">
      <div class="card-header"><i class="fa fa-circle-info"></i> Available Variables</div>
      <div class="card-body" style="font-size:.82rem;color:var(--muted)">
        <?php foreach (explode(',', $sel['variables']) as $v): ?>
        <code style="background:var(--bg);padding:2px 6px;border-radius:4px;margin:2px;display:inline-block"><?= h(trim($v)) ?></code>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="post">
      <input type="hidden" name="template_key" value="<?= h($sel['template_key']) ?>">
      <div class="card">
        <div class="card-header"><i class="fa fa-pen"></i> Edit: <?= h($sel['description']) ?></div>
        <div class="card-body">
          <div class="form-group">
            <label>Subject Line</label>
            <input type="text" name="subject" value="<?= h($_POST['subject'] ?? $sel['subject']) ?>" style="width:100%">
          </div>
          <div class="form-group">
            <label>Email Body</label>
            <textarea name="body" rows="20" style="width:100%;font-family:monospace;font-size:.85rem;resize:vertical"><?= h($_POST['body'] ?? $sel['body']) ?></textarea>
          </div>
          <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary">
              <i class="fa fa-save"></i> Save Template
            </button>
            <a href="?key=<?= urlencode($sel['template_key']) ?>" class="btn btn-secondary">
              <i class="fa fa-rotate-left"></i> Reset
            </a>
          </div>
        </div>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
