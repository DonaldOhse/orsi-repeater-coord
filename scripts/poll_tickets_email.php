<?php
/**
 * ORSI Ticket Email Poller
 * Connects via POP3, retrieves emails, parses ticket numbers,
 * adds replies to the ticket system, then deletes from server.
 * Run via cron every 5 minutes.
 */
require_once __DIR__ . '/../includes/config.php';
$db = get_db();

define('POP3_HOST', 'ssl://mail.yourdomain.com');
define('POP3_PORT', 995);
define('POP3_USER', 'tickets@yourdomain.com');
define('POP3_PASS', 'YOUR_POP3_PASSWORD');

function log_msg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
}

function pop3_cmd($sock, string $cmd): string {
    fputs($sock, $cmd . "\r\n");
    return fgets($sock, 1024);
}

function pop3_multiline($sock): string {
    $data = '';
    while (true) {
        $line = fgets($sock, 4096);
        if (rtrim($line) === '.') break;
        $data .= $line;
    }
    return $data;
}

log_msg("=== ORSI Ticket Email Poller Started ===");

// Connect
$pop = @fsockopen(POP3_HOST, POP3_PORT, $errno, $errstr, 15);
if (!$pop) {
    log_msg("ERROR: Connection failed - $errstr");
    exit(1);
}
stream_set_timeout($pop, 15);

$greeting = fgets($pop);
if (!str_starts_with($greeting, '+OK')) {
    log_msg("ERROR: Bad greeting - $greeting");
    fclose($pop);
    exit(1);
}

// Login
$resp = pop3_cmd($pop, "USER " . POP3_USER);
if (!str_starts_with($resp, '+OK')) { log_msg("ERROR: USER failed"); fclose($pop); exit(1); }

$resp = pop3_cmd($pop, "PASS " . POP3_PASS);
if (!str_starts_with($resp, '+OK')) { log_msg("ERROR: PASS failed"); fclose($pop); exit(1); }

// Get message count
$stat = pop3_cmd($pop, "STAT");
preg_match('/\+OK (\d+)/', $stat, $m);
$count = (int)($m[1] ?? 0);
log_msg("Found {$count} message(s)");

$processed = 0;
$errors = 0;

for ($i = 1; $i <= $count; $i++) {
    // Retrieve message
    $resp = pop3_cmd($pop, "RETR $i");
    if (!str_starts_with($resp, '+OK')) {
        log_msg("ERROR: Could not retrieve message $i");
        $errors++;
        continue;
    }
    $raw = pop3_multiline($pop);

    // Parse email using mailparse for proper MIME handling
    $parsed = mailparse_msg_create();
    mailparse_msg_parse($parsed, $raw);
    $structure = mailparse_msg_get_structure($parsed);

    // Extract headers from root part
    $root_info = mailparse_msg_get_part_data($parsed);
    $subject = isset($root_info['headers']['subject']) ?
        mb_decode_mimeheader($root_info['headers']['subject']) : '';
    $from    = $root_info['headers']['from'] ?? '';

    // Extract sender email
    preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $from, $m);
    $sender_email = strtolower($m[0] ?? '');

    // Extract text body and attachments
    $body = '';
    $attachments = [];

    foreach ($structure as $part_id) {
        $part = mailparse_msg_get_part($parsed, $part_id);
        $part_data = mailparse_msg_get_part_data($part);
        $mime_type = strtolower($part_data['content-type'] ?? 'text/plain');
        $disposition = strtolower($part_data['content-disposition'] ?? '');
        $charset = $part_data['charset'] ?? 'UTF-8';
        $encoding = strtolower($part_data['transfer-encoding'] ?? '');

        // Extract part body
        $part_body = substr($raw, $part_data['starting-pos-body'],
            $part_data['ending-pos-body'] - $part_data['starting-pos-body']);

        // Decode transfer encoding
        if ($encoding === 'quoted-printable') {
            $part_body = quoted_printable_decode($part_body);
        } elseif ($encoding === 'base64') {
            // Clean base64 - remove whitespace and decode properly
            $part_body = base64_decode(preg_replace('/\s+/', '', $part_body), true);
            if ($part_body === false) {
                log_msg("  WARNING: base64 decode failed for part");
                $part_body = '';
            }
        }

        // Convert charset to UTF-8
        if ($charset && strtoupper($charset) !== 'UTF-8') {
            $part_body = @iconv($charset, 'UTF-8//IGNORE', $part_body) ?: $part_body;
        }

        if ($disposition === 'attachment' || isset($part_data['content-name'])) {
            // It's an attachment
            $filename = $part_data['content-name'] ??
                        $part_data['disposition-filename'] ??
                        "attachment_{$part_id}";
            $attachments[] = [
                'filename' => $filename,
                'mime_type' => $mime_type,
                'size' => strlen($part_body),
                'data' => $part_body,
            ];
            log_msg("  Attachment: {$filename} ({$mime_type}, " . strlen($part_body) . " bytes)");
        } elseif (str_starts_with($mime_type, 'text/plain') && empty($body)) {
            $body = $part_body;
        } elseif (str_starts_with($mime_type, 'text/html') && empty($body)) {
            $body = strip_tags($part_body);
            $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        }
    }

    mailparse_msg_free($parsed);

    // Save attachments
    $attachment_notes = '';
    $saved_attachments = [];
    if (!empty($attachments)) {
        $upload_dir = '/var/www/w5dro.com/repeater_coord/uploads/tickets/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        foreach ($attachments as $att) {
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $att['filename']);
            $saved_as = date('Ymd_His') . '_' . $safe_name;
            $save_path = $upload_dir . $saved_as;
            file_put_contents($save_path, $att['data']);
            $attachment_notes .= "\n[Attachment: {$att['filename']} (" . round($att['size']/1024,1) . " KB)]";
            $saved_attachments[] = [
                'filename' => $att['filename'],
                'saved_as' => $saved_as,
                'mime_type' => $att['mime_type'],
                'size' => $att['size'],
            ];
            log_msg("  Saved: {$save_path}");
        }
    }

    // Clean up body
    $body = preg_replace('/\r\n|\r/', "\n", $body);
    $body = preg_replace('/\n>.*$/ms', '', $body);
    $body = preg_replace('/\n--\s*\n.*$/ms', '', $body);
    $body = preg_replace('/On .+ wrote:.*$/ms', '', $body);
    $body = preg_replace('/CAUTION:.*$/ms', '', $body);
    $body = preg_replace('/\n{3,}/', "\n\n", $body);
    $body = trim($body);
    if ($attachment_notes) $body .= "\n\n" . trim($attachment_notes);
    // Normalize line endings
    $body = preg_replace('/\r\n|\r/', "\n", $body);
    // Remove SpamAssassin analysis headers embedded in body
    $body = preg_replace('/Content analysis details:.*?X-Spam-Flag:[^\n]*\n/s', '', $body);
    // Remove MIME boundaries like --_000_DS7PR15MB5858... and --[hex]
    $body = preg_replace('/^boundary="[^"]*"\n/mi', '', $body);
    $body = preg_replace('/^--_[^\n]*\n/m', '', $body);
    $body = preg_replace('/^--[0-9a-zA-Z_]+[^\n]*\n/m', '', $body);
    // Remove Content-Type and encoding headers
    $body = preg_replace('/^Content-Type:[^\n]*\n/mi', '', $body);
    $body = preg_replace('/^Content-Transfer-Encoding:[^\n]*\n/mi', '', $body);
    $body = preg_replace('/^MIME-Version:[^\n]*\n/mi', '', $body);
    $body = preg_replace('/^X-[^\n]*\n/mi', '', $body);
    // Strip HTML if present
    if (preg_match('/<html|<body|<div|<p|<br|<span|<table/i', $body)) {
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        $body = preg_replace('/[ \t]+/', ' ', $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
    }
    // Cut off at quoted text markers
    $body = preg_replace('/\n>.*$/ms', '', $body);
    $body = preg_replace('/\n--\s*\n.*$/ms', '', $body); // signature
    $body = preg_replace('/On .+ wrote:.*$/ms', '', $body); // gmail quote
    $body = preg_replace('/CAUTION:.*$/ms', '', $body); // corporate disclaimers
    $body = trim($body);

    log_msg("Message $i: From={$sender_email} Subject={$subject}");

    // Skip automated/system emails
    $skip_senders = ['cpanel@', 'mailer-daemon@', 'postmaster@', 'no-reply@', 'noreply@', 'donotreply@'];
    $skip = false;
    foreach ($skip_senders as $skip_sender) {
        if (str_contains(strtolower($sender_email), $skip_sender)) {
            log_msg("  Skipping automated email from {$sender_email}");
            $skip = true;
            break;
        }
    }
    // Also decode base64 subjects
    if (preg_match('/=\?UTF-8\?B\?(.+?)\?=/i', $subject, $sm)) {
        $subject = base64_decode($sm[1]);
    }
    if ($skip) {
        pop3_cmd($pop, "DELE $i");
        log_msg("  Deleted automated email");
        continue;
    }

    // Find ticket number in subject - e.g. [ORSI-2026-1234]
    preg_match('/\[?(ORSI-\d{4}-\d{4})\]?/i', $subject, $m);
    $ticket_num = strtoupper($m[1] ?? '');

    if (!$ticket_num) {
        log_msg("  No ticket number found in subject - creating new ticket");
        // Create a new ticket from this email
        $new_num = 'ORSI-' . date('Y') . '-' . str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO support_tickets 
            (ticket_num, subject, category, submitter_name, submitter_email, status)
            VALUES (?,?,?,?,?,?)")
           ->execute([$new_num, $subject ?: 'No Subject', 'GENERAL', $from, $sender_email, 'OPEN']);
        $ticket_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?,NULL,?)")
           ->execute([$ticket_id, $body ?: '(empty message)']);
        $last_msg_id = $db->lastInsertId();
        foreach ($saved_attachments as $att) {
            $db->prepare("INSERT INTO ticket_attachments (ticket_id, message_id, filename, saved_as, mime_type, file_size) VALUES (?,?,?,?,?,?)")
               ->execute([$ticket_id, $last_msg_id, $att['filename'], $att['saved_as'], $att['mime_type'], $att['size']]);
        }
        log_msg("  Created new ticket {$new_num}");

        // Notify admins
        $admins = $db->query("SELECT email FROM users WHERE role='admin' AND active=1 AND email!=''")->fetchAll();
        foreach ($admins as $admin) {
            orsi_mail($admin['email'], "New Ticket {$new_num} via email: {$subject}",
                "A new ticket was created from an incoming email.\n\nFrom: {$from}\nSubject: {$subject}\n\nMessage:\n{$body}\n\nView at: " . ORG_URL . "/admin/tickets.php");
        }
    } else {
        // Find existing ticket
        $ticket = $db->prepare("SELECT * FROM support_tickets WHERE ticket_num=?");
        $ticket->execute([$ticket_num]);
        $ticket = $ticket->fetch();

        if (!$ticket) {
            log_msg("  Ticket {$ticket_num} not found in database");
            $errors++;
        } else {
            // Verify sender is the original submitter (security check)
            if ($sender_email && strtolower($ticket['submitter_email']) !== $sender_email) {
                log_msg("  WARNING: Reply from {$sender_email} but ticket submitted by {$ticket['submitter_email']} - adding with note");
                $body = "[Reply from different email: {$sender_email}]\n\n" . $body;
            }

            // Add reply to ticket
            $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_internal, message, email_sent_to) VALUES (?,NULL,0,?,?)")
               ->execute([$ticket['id'], $body ?: '(empty reply)', $sender_email]);
        $last_msg_id = $db->lastInsertId();
        // Log attachments to database
        foreach ($saved_attachments as $att) {
            $db->prepare("INSERT INTO ticket_attachments (ticket_id, message_id, filename, saved_as, mime_type, file_size) VALUES (?,?,?,?,?,?)")
               ->execute([$ticket['id'], $last_msg_id, $att['filename'], $att['saved_as'], $att['mime_type'], $att['size']]);
        }

            // Update ticket status to OPEN if it was resolved
            if (in_array($ticket['status'], ['RESOLVED','CLOSED'])) {
                $db->prepare("UPDATE support_tickets SET status='OPEN', resolved_at=NULL WHERE id=?")
                   ->execute([$ticket['id']]);
                log_msg("  Ticket {$ticket_num} reopened");
            }

            // Notify assigned coordinator and admins
            $notify = [];
            $admins = $db->query("SELECT email, callsign FROM users WHERE role='admin' AND active=1 AND email!=''")->fetchAll();
            foreach ($admins as $a) $notify[] = $a['email'];
            if ($ticket['assigned_to']) {
                $coord = $db->prepare("SELECT email FROM users WHERE id=?");
                $coord->execute([$ticket['assigned_to']]);
                $crow = $coord->fetch();
                if ($crow && !in_array($crow['email'], $notify)) $notify[] = $crow['email'];
            }
            foreach ($notify as $email) {
                orsi_mail($email,
                    "Re: [{$ticket_num}] {$ticket['subject']} — New Reply",
                    "A reply was received for ticket {$ticket_num}.\n\nFrom: {$from}\n\nMessage:\n{$body}\n\nView at: " . ORG_URL . "/admin/tickets.php?id={$ticket['id']}");
            }
            log_msg("  Reply added to ticket {$ticket_num}");
            $processed++;
        }
    }

    // Delete message from server (POP3 - saves disk space)
    $resp = pop3_cmd($pop, "DELE $i");
    log_msg("  Deleted from server: " . trim($resp));
}

// Quit
pop3_cmd($pop, "QUIT");
fclose($pop);

log_msg("Done! Processed: {$processed}, Errors: {$errors}");
log_msg("=== ORSI Ticket Email Poller Complete ===");
