<?php
require_once __DIR__ . '/includes/config.php';
start_session();

if (is_logged_in()) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db   = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        if ($u && password_verify($password, $u['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $u['id'];
            $_SESSION['username'] = $u['username'];
            $_SESSION['role']     = $u['role'];
            $_SESSION['callsign'] = $u['callsign'];
            $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
            audit('LOGIN', 'users', $u['id']);
            header('Location: ' . BASE_PATH . '/index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please enter username and password.';
    }
}

$page_title = 'Log In';
include __DIR__ . '/includes/header.php';
?>

<div class="login-wrap">
  <h2><i class="fa fa-tower-broadcast"></i><br><?= SITE_SHORT ?></h2>
  <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <div class="form-group" style="margin-bottom:14px">
      <label>Username</label>
      <input type="text" name="username" value="<?= h($_POST['username'] ?? '') ?>" autofocus autocomplete="username">
    </div>
    <div class="form-group" style="margin-bottom:18px">
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%"><i class="fa fa-sign-in-alt"></i> Log In</button>
  </form>
  <div style="margin-top:20px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px;font-size:.82rem;text-align:left">
    <strong style="color:#15803d"><i class="fa fa-info-circle"></i> This login is for ORSI Coordinators only.</strong><br><br>
    <strong>Are you a repeater trustee or user?</strong> You do not need a login to:<br>
    <ul style="margin:8px 0 0 16px;padding:0">
      <li><a href="<?=BASE_PATH?>/index.php">Search the repeater database</a></li>
      <li><a href="<?=BASE_PATH?>/map.php">View the repeater map</a></li>
      <li><a href="<?=BASE_PATH?>/request.php">Submit a coordination request</a></li>
      <li><a href="<?=BASE_PATH?>/contact.php">Submit a repeater update or question</a></li>
    </ul>
  </div>
    
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
