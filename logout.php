<?php
require_once __DIR__ . '/includes/config.php';
start_session();
audit('LOGOUT', 'users', (int)($_SESSION['user_id'] ?? 0));
session_destroy();
header('Location: ' . BASE_PATH . '/login.php');
exit;
