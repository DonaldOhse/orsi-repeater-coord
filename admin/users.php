<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
$db = get_db();

// ── Add/Update User ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $uid  = (int)($_POST['user_id'] ?? 0);
    $uname = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email']      ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $call  = strtoupper(trim($_POST['callsign'] ?? ''));
    $role  = in_array($_POST['role']??'', ['admin','coordinator','viewer']) ? $_POST['role'] : 'viewer';
    $active = isset($_POST['active']) ? 1 : 0;
    $pw    = $_POST['password'] ?? '';

    $errors = [];
    if (!$uname)  $errors[] = 'Username required.';
    if (!$uid && !$pw) $errors[] = 'Password required for new users.';
    $district = in_array($_POST['district'] ?? '', ['NE','NW','OKC','SE','SW','TUL']) ? ($_POST['district'] ?? null) : null;

    if (!$errors) {
        if ($uid) {
            if ($pw) {
                $db->prepare("UPDATE users SET username=?,email=?,first_name=?,last_name=?,callsign=?,district=?,role=?,active=?,password=? WHERE id=?")
                   ->execute([$uname,$email,$first_name,$last_name,$call,$district,$role,$active,password_hash($pw,PASSWORD_BCRYPT),$uid]);
            } else {
                $db->prepare("UPDATE users SET username=?,email=?,first_name=?,last_name=?,callsign=?,district=?,role=?,active=? WHERE id=?")
                   ->execute([$uname,$email,$first_name,$last_name,$call,$district,$role,$active,$uid]);
            }
            flash('success','User updated.');
        } else {
            $db->prepare("INSERT INTO users (username,email,first_name,last_name,callsign,district,role,active,password) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$uname,$email,$first_name,$last_name,$call,$district,$role,$active,password_hash($pw,PASSWORD_BCRYPT)]);
            flash('success','User added.');
        }
        header('Location: ' . BASE_PATH . '/admin/users.php'); exit;
    }
}

// ── Delete User ───────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($del_id !== (int)$user['id']) {
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$del_id]);
        flash('success','User deleted.');
    } else {
        flash('danger','Cannot delete your own account.');
    }
    header('Location: ' . BASE_PATH . '/admin/users.php'); exit;
}

// ── Load for edit ─────────────────────────────────────────────────
$edit_user = [];
if (!empty($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $edit_user = $s->fetch() ?: [];
}

$users = $db->query("SELECT * FROM users ORDER BY role, username")->fetchAll();
$page_title = 'User Management';
include __DIR__ . '/../includes/header.php';

function uv(array $u, string $k): string { return htmlspecialchars((string)($u[$k]??''), ENT_QUOTES, 'UTF-8'); }
function us(array $u, string $k, string $v): string { return ($u[$k]??'')===$v?'selected':''; }
?>

<div class="page-title"><i class="fa fa-users"></i> User Management</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start">

<!-- Add/Edit Form -->
<div class="card">
  <div class="card-header"><i class="fa fa-<?= $edit_user?'pen':'user-plus' ?>"></i> <?= $edit_user ? 'Edit User' : 'Add User' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="user_id" value="<?= uv($edit_user,'id') ?>">
      <div class="form-group" style="margin-bottom:12px">
        <label>Username *</label>
        <input type="text" name="username" value="<?= uv($edit_user,'username') ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Password <?= $edit_user ? '(leave blank to keep)' : '*' ?></label>
        <input type="password" name="password" <?= $edit_user?'':'required' ?> autocomplete="new-password">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Callsign</label>
        <input type="text" name="callsign" value="<?= uv($edit_user,'callsign') ?>" style="text-transform:uppercase" maxlength="15">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Email</label>
        <input type="email" name="email" value="<?= uv($edit_user,'email') ?>" maxlength="150">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>First Name</label>
        <input type="text" name="first_name" value="<?= uv($edit_user,'first_name') ?>" maxlength="50">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Last Name</label>
        <input type="text" name="last_name" value="<?= uv($edit_user,'last_name') ?>" maxlength="50">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>District <small style="font-weight:400;color:var(--muted)">(coordinator district assignment)</small></label>
        <select name="district">
          <option value="">- No District -</option>
          <?php foreach (['NE','NW','OKC','SE','SW','TUL'] as $dist): ?>
          <option value="<?= $dist ?>" <?= ($edit_user['district']??'')===$dist?'selected':'' ?>><?= $dist ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Role</label>
        <select name="role">
          <option value="viewer"      <?= us($edit_user,'role','viewer') ?>>Viewer (read-only)</option>
          <option value="coordinator" <?= us($edit_user,'role','coordinator') ?>>Coordinator (add/edit)</option>
          <option value="admin"       <?= us($edit_user,'role','admin') ?>>Admin (full access)</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:18px">
        <label class="form-check"><input type="checkbox" name="active" value="1" <?= !$edit_user || !empty($edit_user['active']) ? 'checked' : '' ?>> Active</label>
      </div>
      <div class="form-actions">
        <button type="submit" name="save_user" value="1" class="btn btn-success"><i class="fa fa-save"></i> <?= $edit_user?'Update':'Add User' ?></button>
        <?php if ($edit_user): ?><a href="<?= BASE_PATH ?>/admin/users.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Users Table -->
<div class="card">
  <div class="card-header"><i class="fa fa-list"></i> Users (<?= count($users) ?>)</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Callsign</th><th>District</th><th>Email</th><th>Role</th><th>Active</th><th>Last Login</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <?php if ($u['first_name'] || $u['last_name']): ?>
            <strong><?= h(trim($u['first_name'].' '.$u['last_name'])) ?></strong><br>
            <small class="text-muted"><?= h($u['username']) ?></small>
          <?php else: ?>
            <strong><?= h($u['username']) ?></strong>
          <?php endif; ?>
        </td>
        <td><?= h($u['callsign']) ?></td>
        <td><?= $u['district'] ? '<span class="district-badge">'.h($u['district']).'</span>' : '<span class="text-muted">-</span>' ?></td>
        <td><?= h($u['email']) ?></td>
        <td>
          <?php
          $rc = ['admin'=>'danger','coordinator'=>'warning','viewer'=>'info'];
          $cls = $rc[$u['role']] ?? 'info';
          ?><span class="badge" style="background:var(--<?= $cls ?>);color:#fff"><?= h($u['role']) ?></span>
        </td>
        <td><?= $u['active'] ? '<span class="bool-yes"><i class="fa fa-check"></i></span>' : '<span class="bool-no"><i class="fa fa-times"></i></span>' ?></td>
        <td style="font-size:.8rem;color:var(--muted)"><?= $u['last_login'] ? substr($u['last_login'],0,10) : 'Never' ?></td>
        <td>
          <a href="<?= BASE_PATH ?>/admin/users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-warning"><i class="fa fa-pen"></i></a>
          <?php if ((int)$u['id'] !== (int)$user['id']): ?>
          <a href="<?= BASE_PATH ?>/admin/users.php?delete=<?= $u['id'] ?>" class="btn btn-sm btn-danger"
             data-confirm="Delete user '<?= h($u['username']) ?>'?"><?= '<i class="fa fa-trash"></i>' ?></a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
