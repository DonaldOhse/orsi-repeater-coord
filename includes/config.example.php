<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
define('DB_CHARSET', 'utf8mb4');
define('SITE_NAME', 'Oklahoma Repeater Coordination System');
define('SITE_SHORT', 'ORSI Coordination');
define('SITE_VERSION', '1.0.0');
define('BASE_PATH', '/repeater_coord');
// QRZ.com API
define('QRZ_USERNAME', 'w5dro');
define('QRZ_PASSWORD', 'DroLeo123');
define('QRZ_API_KEY',  '1DED-6AA5-77A6-6B8B');

// System settings helper
function get_setting(string $key, string $default = ''): string {
    try {
        $db = get_db();
        $s = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');
        $s->execute([$key]);
        $r = $s->fetch();
        return $r ? $r['setting_value'] : $default;
    } catch (Exception $e) { return $default; }
}

function orsi_mail(string $to, string $subject, string $body, string $headers = ''): bool {
    if (!get_setting('email_enabled', '1')) return false;
    if (get_setting('email_test_mode', '0')) {
        $test_addr = get_setting('email_test_address', '');
        if (!$test_addr) return false;
        $subject = '[TEST] ' . $subject;
        $body = "[TEST MODE - Original recipient: {$to}]\n\n" . $body;
        $to = $test_addr;
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.oklahomarepeatersociety.org';
        $mail->SMTPAuth   = true;
        $mail->Username = 'YOUR_SMTP_USER';
        $mail->Password = 'YOUR_SMTP_PASSWORD';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('noreply@oklahomarepeatersociety.org', 'Oklahoma Repeater Society');
        $mail->addReplyTo(MAIL_REPLY_TO, 'ORSI Coordination');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('ORSI Mail Error: ' . $mail->ErrorInfo);
        return false;
    }
}

function orsi_ticket_mail(string $to, string $subject, string $body): bool {
    if (!get_setting('email_enabled', '1')) return false;
    require_once __DIR__ . '/../vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.oklahomarepeatersociety.org';
        $mail->SMTPAuth   = true;
        $mail->Username = 'YOUR_SMTP_USER';
        $mail->Password = 'YOUR_SMTP_PASSWORD';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('noreply@oklahomarepeatersociety.org', 'Oklahoma Repeater Society');
        $mail->addReplyTo('tickets@oklahomarepeatersociety.org', 'ORSI Support Tickets');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('ORSI Ticket Mail Error: ' . $mail->ErrorInfo);
        return false;
    }
}

define('MAIL_FROM', 'Your Org <noreply@yourdomain.com>');
define('MAIL_REPLY_TO', 'coordination@yourdomain.com');
define('MAIL_TICKETS_TO', 'tickets@yourdomain.com');
define('ORG_NAME', 'Your Repeater Society');
define('ORG_URL', 'https://yourwebsite.org/repeater_coord');
define('SESSION_LIFETIME', 28800);
ini_set('session.save_path', '/tmp');
define('EARTH_RADIUS_MILES', 3958.8);

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('<div style="color:red;padding:20px;font-family:monospace;">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
    }
    return $pdo;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function is_logged_in(): bool {
    start_session();
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
}

function require_role(string $role): void {
    require_login();
    start_session();
    $roles = ['viewer' => 0, 'coordinator' => 1, 'admin' => 2];
    $user_level = $roles[$_SESSION['role'] ?? 'viewer'] ?? 0;
    $req_level  = $roles[$role] ?? 0;
    if ($user_level < $req_level) {
        http_response_code(403);
        die('<div style="color:red;padding:20px;">Access denied. Required role: ' . $role . '</div>');
    }
}

function current_user(): array {
    start_session();
    return [
        'id'       => $_SESSION['user_id']  ?? 0,
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role']     ?? 'viewer',
        'callsign' => $_SESSION['callsign'] ?? '',
    ];
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash(string $type, string $msg): void {
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flashes(): array {
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r  = EARTH_RADIUS_MILES;
    $la = deg2rad($lat2 - $lat1);
    $lo = deg2rad($lon2 - $lon1);
    $a  = sin($la/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lo/2)**2;
    return 2 * $r * asin(sqrt($a));
}

// Border points for each neighboring state - array of lat/lon points along the OK border
// These are key points along each state's border with Oklahoma
function get_nearby_states(float $lat, float $lon, float $radius_miles = 100): array {
    $borders = [
        'TX' => [
            // Red River border - accurate OK/TX border ~33.8N to 34.0N
            [33.837,-94.043],[33.850,-94.500],[33.862,-95.000],[33.874,-95.500],
            [33.885,-96.000],[33.834,-96.500],[33.834,-97.000],[33.834,-97.500],
            [33.834,-98.000],[33.834,-98.500],[33.834,-99.000],[33.834,-99.500],
            [34.560,-99.998],[34.560,-100.000],
            // Texas panhandle eastern border ~100W from 34.56N to 36.50N
            [34.560,-100.000],[35.000,-100.000],[35.500,-100.000],
            [36.000,-100.000],[36.500,-100.000],
            // Texas panhandle northern border at 36.5N (only from -103W to -100W)
            [36.500,-103.000],[36.500,-102.000],[36.500,-101.000],[36.500,-100.000],
        ],
        'KS' => [[37.000,-94.618],[37.000,-95.000],[37.000,-96.000],[37.000,-97.000],[37.000,-98.000],[37.000,-99.000],[37.000,-100.000],[37.000,-101.000],[37.000,-102.000]],
        'MO' => [[36.497,-94.618],[36.800,-94.618],[37.000,-94.618]],
        'AR' => [[36.497,-94.618],[36.000,-94.618],[35.500,-94.618],[35.000,-94.618],[34.500,-94.618],[33.837,-94.043]],
        'CO' => [[37.000,-102.000],[36.993,-102.000],[36.500,-102.000]],
        'NM' => [[37.000,-103.000],[36.500,-103.000],[36.000,-103.000],[35.500,-103.000],[35.000,-103.000],[34.500,-103.000]],
    ];

    $nearby = [];
    foreach ($borders as $state => $points) {
        $min_dist = PHP_INT_MAX;
        foreach ($points as $point) {
            $d = haversine($lat, $lon, $point[0], $point[1]);
            if ($d < $min_dist) $min_dist = $d;
        }
        if ($min_dist <= $radius_miles) {
            $nearby[$state] = round($min_dist, 1);
        }
    }
    asort($nearby); // Sort by distance
    return $nearby;
}

function get_coordinator_email(string $district): string {
    try {
        $db = get_db();
        // First: find assigned coordinator for this district
        $stmt = $db->prepare("SELECT email FROM users WHERE district = ? AND role IN ('coordinator','admin') AND active = 1 AND email != '' ORDER BY role DESC LIMIT 1");
        $stmt->execute([$district]);
        $row = $stmt->fetch();
        if ($row && $row['email']) return $row['email'];
        // Fallback: any active admin
        $admin = $db->query("SELECT email FROM users WHERE role='admin' AND active=1 AND email != '' LIMIT 1")->fetch();
        if ($admin && $admin['email']) return $admin['email'];
        // Last resort: any active coordinator
        $coord = $db->query("SELECT email FROM users WHERE role='coordinator' AND active=1 AND email != '' LIMIT 1")->fetch();
        return $coord['email'] ?? '';
    } catch (Exception) { return ''; }
}

function get_all_coordinator_emails(string $district): array {
    // Returns array of emails: assigned coordinator + all admins
    // Used to ensure nothing falls through the cracks
    try {
        $db = get_db();
        $emails = [];
        // Assigned coordinator for district
        $stmt = $db->prepare("SELECT email FROM users WHERE district = ? AND active = 1 AND email != '' AND role IN ('coordinator','admin')");
        $stmt->execute([$district]);
        foreach ($stmt->fetchAll() as $r) $emails[] = $r['email'];
        // Always CC all admins
        $admins = $db->query("SELECT email FROM users WHERE role='admin' AND active=1 AND email != ''")->fetchAll();
        foreach ($admins as $a) {
            if (!\in_array($a['email'], $emails)) $emails[] = $a['email'];
        }
        // If still empty, something is very wrong - log it
        if (empty($emails)) {
            error_log('ORSI: No coordinator or admin emails found for district: ' . $district);
        }
        return array_unique($emails);
    } catch (Exception $e) {
        error_log('ORSI get_all_coordinator_emails error: ' . $e->getMessage());
        return [];
    }
}

function audit(string $action, string $table, int $record_id, ?array $old = null, ?array $new = null): void {
    try {
        $db   = get_db();
        $u    = current_user();
        $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare("INSERT INTO audit_log (user_id,action,table_name,record_id,old_data,new_data,ip_address) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $u['id'] ?: null, $action, $table, $record_id,
            $old ? json_encode($old) : null,
            $new  ? json_encode($new)  : null,
            $ip
        ]);
    } catch (Exception) {}
}

function get_template(PDO $db, string $key): array {
    $s = $db->prepare("SELECT subject, body FROM email_templates WHERE template_key=?");
    $s->execute([$key]);
    $t = $s->fetch();
    return $t ?: ['subject'=>'', 'body'=>''];
}

function render_template(array $tpl, array $vars): array {
    $subject = $tpl['subject'];
    $body    = $tpl['body'];
    foreach ($vars as $k => $v) {
        $subject = str_replace($k, $v, $subject);
        $body    = str_replace($k, $v, $body);
    }
    return ['subject' => $subject, 'body' => $body];
}
