<?php
require_once __DIR__ . '/includes/config.php';
require_login();

$db  = get_db();
$err = '';
$ok  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $err = 'All fields are required.';
    } elseif (strlen($new) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $err = 'New passwords do not match.';
    } else {
        $user = $db->prepare("SELECT password FROM users WHERE id=?");
        $user->execute([$_SESSION['user_id']]);
        $u = $user->fetch();
        if (!$u || !password_verify($current, $u['password'])) {
            $err = 'Current password is incorrect.';
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            audit('PASSWORD_CHANGE', 'users', $_SESSION['user_id'], null, ['changed'=>true]);
            $ok = true;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-title"><i class="fa fa-key"></i> Change Password</div>

<div class="card" style="max-width:480px;margin:0 auto">
  <div class="card-header"><i class="fa fa-lock"></i> Update Your Password</div>
  <div style="padding:20px">

    <?php if ($ok): ?>
      <div class="alert alert-success">
        <i class="fa fa-check-circle"></i> Password changed successfully!
      </div>
      <a href="<?= BASE_PATH ?>/" class="btn btn-primary">Back to Database</a>

    <?php else: ?>
      <?php if ($err): ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= h($err) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label>Current Password</label>
          <input type="password" name="current_password" required autofocus>
        </div>
        <div class="form-group">
          <label>New Password</label>
          <input type="password" name="new_password" required minlength="8"
            placeholder="Minimum 8 characters">
        </div>
        <div class="form-group">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password" required minlength="8">
        </div>
        <div style="display:flex;gap:10px;margin-top:20px">
          <a href="<?= BASE_PATH ?>/" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">
            <i class="fa fa-key"></i> Change Password
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
