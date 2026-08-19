<?php
require_once __DIR__ . '/../includes/config.php';
$db = get_db();
// Allow access if logged in OR if valid token provided
if (!is_logged_in()) {
    // Check token - simple hash of id + secret
    $token = $_GET['token'] ?? '';
    $id = (int)($_GET['id'] ?? 0);
    $expected = substr(md5($id . 'orsi_attach_' . date('Ymd')), 0, 12);
    if ($token !== $expected) {
        require_login(); // Will redirect to login
    }
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$att = $db->prepare("SELECT * FROM ticket_attachments WHERE id=?");
$att->execute([$id]);
$att = $att->fetch();
if (!$att) { http_response_code(404); exit; }

$file = '/var/www/w5dro.com/repeater_coord/uploads/tickets/' . $att['saved_as'];
if (!file_exists($file)) { http_response_code(404); echo "File not found"; exit; }

$mime = $att['mime_type'] ?: 'application/octet-stream';

// Inline display for images, download for others
if (str_starts_with($mime, 'image/')) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $att['filename'] . '"');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $att['filename'] . '"');
}
header('Content-Length: ' . filesize($file));
readfile($file);
