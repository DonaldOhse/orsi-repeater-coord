<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db  = get_db();
$me  = current_user();
$page_title = 'Support Tickets';

// ── Handle actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['ticket_id'] ?? 0);

    // Post a reply
    if ($action === 'reply' && $tid) {
        $msg      = trim($_POST['message'] ?? '');
        $internal = !empty($_POST['internal']) ? 1 : 0;
        $new_status = $_POST['new_status'] ?? null;
        if ($msg) {
            $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_internal, message) VALUES (?,?,?,?)")
               ->execute([$tid, $me['id'], $internal, $msg]);
            if ($new_status) {
                $resolved = $new_status === 'RESOLVED' ? date('Y-m-d H:i:s') : null;
                $db->prepare("UPDATE support_tickets SET status=?, resolved_at=? WHERE id=?")
                   ->execute([$new_status, $resolved, $tid]);
            }
            // Email submitter unless internal note
            if (!$internal) {
                $t = $db->prepare("SELECT * FROM support_tickets WHERE id=?");
                $t->execute([$tid]);
                $t = $t->fetch();
                if ($t) {
                    $body = "Dear {$t['submitter_name']},\n\n"
                          . "A coordinator has replied to your inquiry (Ticket: {$t['ticket_num']}).\n\n"
                          . "SUBJECT: {$t['subject']}\n\n"
                          . "REPLY:\n{$msg}\n\n"
                          . ($new_status === 'RESOLVED' ? "This ticket has been marked as RESOLVED.\n\n" : "")
                          . "To reply, simply reply to this email.\n"
                          . "Reference your ticket number: {$t['ticket_num']}\n\n"
                          . "73,\nOklahoma Repeater Society, Inc.\n" . ORG_URL;
                    $sent = orsi_ticket_mail($t['submitter_email'],
                        "Re: [{$t['ticket_num']}] {$t['subject']}", $body);
                    // Log who got the email
                    if ($sent) {
                        $db->prepare("UPDATE ticket_messages SET email_sent_to=? WHERE ticket_id=? ORDER BY id DESC LIMIT 1")
                           ->execute([$t['submitter_email'], $tid]);
                    }
                }
            }
            flash('success', 'Reply posted' . ($internal ? ' (internal note)' : ' and email sent'));
        }
        header("Location: " . BASE_PATH . "/admin/tickets.php?id={$tid}");
        exit;
    }

    // Assign ticket
    if ($action === 'assign' && $tid) {
        $assign_to = (int)($_POST['assign_to'] ?? 0) ?: null;
        $district  = $_POST['district'] ?? null;
        $db->prepare("UPDATE support_tickets SET assigned_to=?, district=? WHERE id=?")
           ->execute([$assign_to, $district ?: null, $tid]);
        flash('success', 'Ticket assigned');
        header("Location: " . BASE_PATH . "/admin/tickets.php?id={$tid}");
        exit;
    }

    // Update status
    if ($action === 'status' && $tid) {
        $status   = $_POST['status'] ?? 'OPEN';
        $resolved = $status === 'RESOLVED' ? date('Y-m-d H:i:s') : null;
        $db->prepare("UPDATE support_tickets SET status=?, resolved_at=? WHERE id=?")
           ->execute([$status, $resolved, $tid]);
        flash('success', 'Status updated');
        header("Location: " . BASE_PATH . "/admin/tickets.php?id={$tid}");
        exit;
    }
}

// ── View single ticket ─────────────────────────────────────────
$view_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($view_id) {
    $ticket = $db->prepare("SELECT t.*, r.callsign as rep_callsign, r.output_freq as rep_freq,
        u.callsign as assigned_call, u.username as assigned_user
        FROM support_tickets t
        LEFT JOIN repeaters r ON r.id = t.repeater_id
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.id=?");
    $ticket->execute([$view_id]);
    $ticket = $ticket->fetch();

    $messages = $db->prepare("SELECT m.*, u.callsign, u.username, u.role
        FROM ticket_messages m
        LEFT JOIN users u ON u.id = m.user_id
        WHERE m.ticket_id=? ORDER BY m.created_at ASC");
    $messages->execute([$view_id]);
    $messages = $messages->fetchAll();

    // Get attachments for this ticket
    $attachments = $db->prepare("SELECT * FROM ticket_attachments WHERE ticket_id=? ORDER BY uploaded_at ASC");
    $attachments->execute([$view_id]);
    $attachments = $attachments->fetchAll();

    $coordinators = $db->query("SELECT id, callsign, username, district FROM users 
        WHERE active=1 AND role IN ('coordinator','admin') ORDER BY district, callsign")->fetchAll();

    include __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
  <div class="page-title" style="margin:0">
    <i class="fa fa-ticket"></i> Ticket <?=h($ticket['ticket_num'])?>
    <?php
    $sc = ['OPEN'=>'success','IN_PROGRESS'=>'warning','WAITING'=>'info','RESOLVED'=>'secondary','CLOSED'=>'secondary'];
    $cls = $sc[$ticket['status']] ?? 'secondary';
    ?>
    <span class="badge badge-<?=$cls?>" style="font-size:.7rem;vertical-align:middle"><?=h($ticket['status'])?></span>
  </div>
  <a href="<?=BASE_PATH?>/admin/tickets.php" class="btn btn-secondary btn-sm">← Back to Tickets</a>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start">
<div>
  <!-- Messages thread -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><i class="fa fa-comments"></i> <?=h($ticket['subject'])?></div>
    <div style="padding:16px">
      <div style="margin-bottom:8px;font-size:.82rem;color:var(--muted)">
        From: <strong><?=h($ticket['submitter_name'])?></strong>
        <?=$ticket['submitter_call']?' ('.h($ticket['submitter_call']).')':''?>
        &lt;<a href="mailto:<?=h($ticket['submitter_email'])?>"><?=h($ticket['submitter_email'])?></a>&gt;
        <?=$ticket['submitter_phone']?' · '.h($ticket['submitter_phone']):''?>
      </div>
      <?php if ($ticket['rep_callsign']): ?>
      <div style="font-size:.82rem;color:var(--muted);margin-bottom:8px">
        Related repeater: <a href="<?=BASE_PATH?>/repeater.php?id=<?=$ticket['repeater_id']?>"><?=h($ticket['rep_callsign'])?></a>
        (<?=number_format((float)$ticket['rep_freq'],4)?> MHz)
      </div>
      <?php endif; ?>
    </div>

    <?php foreach ($messages as $m): ?>
    <?php $is_staff = !is_null($m['user_id']); ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border);
      background:<?=$m['is_internal']?'#fffbeb':($is_staff?'#f0f4f8':'#ffffff')?>">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:.78rem;color:var(--muted)">
        <span>
          <?php if ($m['is_internal']): ?>
            <span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:.7rem">🔒 INTERNAL NOTE</span>
          <?php elseif ($is_staff): ?>
            <span style="background:#dbeafe;color:#1d4ed8;padding:1px 6px;border-radius:3px;font-size:.7rem">STAFF</span>
          <?php else: ?>
            <span style="background:#f0fdf4;color:#15803d;padding:1px 6px;border-radius:3px;font-size:.7rem">SUBMITTER</span>
          <?php endif; ?>
          <strong style="margin-left:6px"><?=$is_staff ? h($m['callsign']??$m['username']) : h($ticket['submitter_name'])?></strong>
        </span>
        <span><?=date('M j, Y g:i A', strtotime($m['created_at']))?></span>
      </div>
      <div style="white-space:pre-wrap;font-size:.875rem"><?=h($m['message'])?></div>
      <?php if (!$m['is_internal'] && $m['user_id'] && !empty($m['email_sent_to'])): ?>
      <div style="font-size:.72rem;color:#15803d;margin-top:4px">
        <i class="fa fa-envelope"></i> Emailed to: <?=h($m['email_sent_to'])?>
      </div>
      <?php elseif (!$m['is_internal'] && $m['user_id'] && empty($m['email_sent_to'])): ?>
      <div style="font-size:.72rem;color:#aaa;margin-top:4px">
        <i class="fa fa-envelope"></i> Email not logged
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Attachments -->
    <?php if (!empty($attachments)): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border);background:#f8fafc">
      <div style="font-size:.8rem;font-weight:600;color:var(--muted);margin-bottom:8px">
        <i class="fa fa-paperclip"></i> ATTACHMENTS (<?=count($attachments)?>)
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php foreach ($attachments as $att):
          $is_image = str_starts_with($att['mime_type'] ?? '', 'image/');
          $icon = $is_image ? 'fa-image' : 'fa-file';
          $size = $att['file_size'] ? round($att['file_size']/1024, 1) . ' KB' : '';
        ?>
        <div style="border:1px solid var(--border);border-radius:6px;padding:8px;background:#fff;max-width:200px">
          <?php if ($is_image): ?>
          <?php $token = substr(md5($att['id'] . 'orsi_attach_' . date('Ymd')), 0, 12); ?>
          <a href="<?=BASE_PATH?>/admin/ticket_attachment.php?id=<?=$att['id']?>&token=<?=$token?>" target="_blank">
            <img src="<?=BASE_PATH?>/admin/ticket_attachment.php?id=<?=$att['id']?>&token=<?=$token?>"
              style="max-width:100%;max-height:120px;display:block;border-radius:4px;margin-bottom:6px">
          </a>
          <?php else: ?>
          <div style="text-align:center;padding:16px 0;color:var(--muted)">
            <i class="fa <?=$icon?>" style="font-size:2rem"></i>
          </div>
          <?php endif; ?>
          <div style="font-size:.75rem;word-break:break-all"><?=h($att['filename'])?></div>
          <div style="font-size:.7rem;color:var(--muted)"><?=$size?></div>
          <a href="<?=BASE_PATH?>/admin/ticket_attachment.php?id=<?=$att['id']?>"
            class="btn btn-secondary btn-sm" style="width:100%;margin-top:6px;font-size:.75rem"
            <?=$is_image?'target="_blank"':'download'?>>
            <i class="fa fa-download"></i> <?=$is_image?'View':'Download'?>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Reply form -->
    <div style="padding:16px;border-top:2px solid var(--primary)">
      <form method="post">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="ticket_id" value="<?=$view_id?>">
        <div class="form-group">
          <label>Reply</label>
          <textarea name="message" rows="4" placeholder="Type your reply..." required></textarea>
        </div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer">
            <input type="checkbox" name="internal" value="1">
            🔒 Internal note (not emailed to submitter)
          </label>
          <select name="new_status" style="padding:6px;border:1px solid var(--border);border-radius:4px;font-size:.85rem">
            <option value="">Keep current status</option>
            <option value="IN_PROGRESS">Mark In Progress</option>
            <option value="WAITING">Mark Waiting on Submitter</option>
            <option value="RESOLVED">Mark Resolved</option>
            <option value="CLOSED">Mark Closed</option>
          </select>
          <button type="submit" class="btn btn-primary">
            <i class="fa fa-paper-plane"></i> Send Reply
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Sidebar -->
<div>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><i class="fa fa-info-circle"></i> Ticket Details</div>
    <div style="padding:12px">
      <table style="width:100%;font-size:.82rem;border-collapse:collapse">
        <?php foreach ([
          ['Ticket #', $ticket['ticket_num']],
          ['Category', $ticket['category']],
          ['Priority', $ticket['priority']],
          ['District', $ticket['district'] ?: 'Unassigned'],
          ['Assigned To', $ticket['assigned_call'] ?: 'Unassigned'],
          ['Created', date('M j, Y g:i A', strtotime($ticket['created_at']))],
          ['Updated', date('M j, Y g:i A', strtotime($ticket['updated_at']))],
          ['Resolved', $ticket['resolved_at'] ? date('M j, Y', strtotime($ticket['resolved_at'])) : '—'],
        ] as [$l,$v]): ?>
        <tr><td style="padding:4px 0;color:var(--muted);width:80px"><?=$l?></td>
            <td style="padding:4px 0;font-weight:500"><?=h($v)?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <!-- Assign -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><i class="fa fa-user-tie"></i> Assign</div>
    <div style="padding:12px">
      <form method="post">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="ticket_id" value="<?=$view_id?>">
        <div class="form-group" style="margin-bottom:8px">
          <label style="font-size:.8rem">District</label>
          <select name="district">
            <option value="">— Any —</option>
            <?php foreach (['OKC','TUL','NW','NE','SW','SE'] as $d): ?>
            <option value="<?=$d?>" <?=$ticket['district']===$d?'selected':''?>><?=$d?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:8px">
          <label style="font-size:.8rem">Assign To</label>
          <select name="assign_to">
            <option value="">— Unassigned —</option>
            <?php foreach ($coordinators as $c): ?>
            <option value="<?=$c['id']?>" <?=$ticket['assigned_to']==$c['id']?'selected':''?>>
              <?=h($c['callsign']??$c['username'])?> (<?=h($c['district']??'Admin')?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">Update Assignment</button>
      </form>
    </div>
  </div>

  <!-- Quick status -->
  <div class="card">
    <div class="card-header"><i class="fa fa-tag"></i> Quick Status</div>
    <div style="padding:12px;display:flex;flex-direction:column;gap:6px">
      <?php foreach (['OPEN','IN_PROGRESS','WAITING','RESOLVED','CLOSED'] as $s): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="ticket_id" value="<?=$view_id?>">
        <input type="hidden" name="status" value="<?=$s?>">
        <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;text-align:left"
          <?=$ticket['status']===$s?'disabled':''?>>
          <?=$ticket['status']===$s?'✓ ':''?><?=$s?>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Ticket list ────────────────────────────────────────────────
$filter_status   = $_GET['status'] ?? 'OPEN';
$filter_district = $_GET['district'] ?? '';
$filter_cat      = $_GET['category'] ?? '';

$where = ['1=1'];
$params = [];
if ($filter_status && $filter_status !== 'ALL') { $where[] = 't.status=?'; $params[] = $filter_status; }
if ($filter_district) { $where[] = 't.district=?'; $params[] = $filter_district; }
if ($filter_cat) { $where[] = 't.category=?'; $params[] = $filter_cat; }

$tickets = $db->prepare("
    SELECT t.*, 
           u.callsign as assigned_call,
           (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id=t.id) as msg_count,
           (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id=t.id AND m.is_internal=0) as public_count
    FROM support_tickets t
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.created_at DESC
    LIMIT 100");
$tickets->execute($params);
$tickets = $tickets->fetchAll();

// Summary counts
$counts = $db->query("SELECT status, COUNT(*) as cnt FROM support_tickets GROUP BY status")->fetchAll();
$cnt = ['OPEN'=>0,'IN_PROGRESS'=>0,'WAITING'=>0,'RESOLVED'=>0,'CLOSED'=>0];
foreach ($counts as $c) $cnt[$c['status']] = $c['cnt'];

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
  <div class="page-title" style="margin:0"><i class="fa fa-ticket"></i> Support Tickets</div>
</div>

<!-- Status counts -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach ([
    ['ALL','All',$cnt['OPEN']+$cnt['IN_PROGRESS']+$cnt['WAITING']+$cnt['RESOLVED']+$cnt['CLOSED'] ?? 0,'f1f5f9','64748b'],
    ['OPEN','Open',$cnt['OPEN']??0,'fef2f2','dc2626'],
    ['IN_PROGRESS','In Progress',$cnt['IN_PROGRESS']??0,'fffbeb','92400e'],
    ['WAITING','Waiting',$cnt['WAITING']??0,'eff6ff','1d4ed8'],
    ['RESOLVED','Resolved',$cnt['RESOLVED']??0,'f0fdf4','15803d'],
    ['CLOSED','Closed',$cnt['CLOSED']??0,'f1f5f9','64748b'],
  ] as [$s,$l,$c,$bg,$col]): ?>
  <a href="?status=<?=$s?><?=$filter_district?'&district='.$filter_district:''?>"
    style="background:#<?=$bg?>;color:#<?=$col?>;padding:6px 14px;border-radius:20px;
    font-size:.82rem;text-decoration:none;font-weight:<?=$filter_status===$s?'bold':'normal'?>;
    border:2px solid <?=$filter_status===$s?'#'.$col:'transparent'?>">
    <?=$l?> (<?=$c?>)
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <select onchange="location='?status=<?=$filter_status?>&district='+this.value+'&category=<?=$filter_cat?>'"
    style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:.85rem">
    <option value="">All Districts</option>
    <?php foreach (['OKC','TUL','NW','NE','SW','SE'] as $d): ?>
    <option value="<?=$d?>" <?=$filter_district===$d?'selected':''?>><?=$d?></option>
    <?php endforeach; ?>
  </select>
  <select onchange="location='?status=<?=$filter_status?>&district=<?=$filter_district?>&category='+this.value"
    style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:.85rem">
    <option value="">All Categories</option>
    <?php foreach (['GENERAL','COORDINATION','RENEWAL','TECHNICAL','COMPLAINT','OTHER'] as $c): ?>
    <option value="<?=$c?>" <?=$filter_cat===$c?'selected':''?>><?=$c?></option>
    <?php endforeach; ?>
  </select>
</div>

<?php if (empty($tickets)): ?>
<div class="card"><div style="padding:40px;text-align:center;color:var(--muted)">
  <i class="fa fa-inbox" style="font-size:2rem"></i><br><br>No tickets found.
</div></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Ticket</th><th>Subject</th><th>From</th><th>Category</th>
      <th>District</th><th>Assigned</th><th>Messages</th><th>Created</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
    <?php
      $sc = ['OPEN'=>'danger','IN_PROGRESS'=>'warning','WAITING'=>'info','RESOLVED'=>'success','CLOSED'=>'secondary'];
      $cls = $sc[$t['status']] ?? 'secondary';
    ?>
    <tr>
      <td><a href="?id=<?=$t['id']?>" style="font-family:monospace;font-weight:bold"><?=h($t['ticket_num'])?></a></td>
      <td><a href="?id=<?=$t['id']?>"><?=h($t['subject'])?></a></td>
      <td><?=h($t['submitter_name'])?><?=$t['submitter_call']?' <small>('.h($t['submitter_call']).')</small>':''?></td>
      <td style="font-size:.78rem"><?=h($t['category'])?></td>
      <td><?=h($t['district']??'—')?></td>
      <td><?=h($t['assigned_call']??'—')?></td>
      <td style="text-align:center"><?=$t['public_count']?></td>
      <td style="font-size:.78rem"><?=date('M j, Y', strtotime($t['created_at']))?></td>
      <td><span class="badge badge-<?=$cls?>" style="font-size:.7rem"><?=h($t['status'])?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
