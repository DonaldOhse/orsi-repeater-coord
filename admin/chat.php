<?php
require_once __DIR__ . '/../includes/config.php';
require_role('coordinator');
$db   = get_db();
$user = current_user();

// ── Handle AJAX requests ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    // Post new message
    if ($action === 'post') {
        $msg      = trim($_POST['message'] ?? '');
        $reply_to = (int)($_POST['reply_to'] ?? 0) ?: null;
        if (!$msg) { echo json_encode(['error' => 'Empty message']); exit; }
        if (strlen($msg) > 2000) { echo json_encode(['error' => 'Message too long']); exit; }
        $db->prepare("INSERT INTO coordinator_chat (user_id, message, reply_to) VALUES (?,?,?)")
           ->execute([$user['id'], $msg, $reply_to]);
        $new_id = $db->lastInsertId();

        // Parse @mentions and send email notifications
        if (preg_match_all('/@([A-Za-z0-9_]+)/', $msg, $matches)) {
            $mentioned = array_unique($matches[1]);
            foreach ($mentioned as $mention) {
                // Look up user by callsign or username (case insensitive)
                $muser = $db->prepare("SELECT id, callsign, email, username FROM users
                    WHERE active=1 AND email != '' AND (LOWER(callsign)=LOWER(?) OR LOWER(username)=LOWER(?))
                    LIMIT 1");
                $muser->execute([$mention, $mention]);
                $mrow = $muser->fetch();
                if ($mrow && $mrow['id'] != $user['id']) {
                    $sender = $user['callsign'] ?: $user['username'];
                    $chat_url = ORG_URL . BASE_PATH . '/admin/chat.php';
                    $subject = "You were mentioned in ORSI Coordinator Chat by {$sender}";
                    $body = "Hi {$mrow['callsign']},

"
                        . "{$sender} mentioned you in the ORSI Coordinator Chat:

"
                        . "---
{$msg}
---

"
                        . "View the chat here:
{$chat_url}

"
                        . "73,
ORSI Coordination System";
                    $mail_result = orsi_mail($mrow['email'], $subject, $body);
                    if (!$mail_result) {
                        error_log("ORSI Chat @mention email FAILED to {$mrow['email']} for mention @{$mention}");
                    } else {
                        error_log("ORSI Chat @mention email sent to {$mrow['email']} for mention @{$mention}");
                    }
                }
            }
        }

        echo json_encode(['ok' => true, 'id' => $new_id]);
        exit;
    }

    // Delete message (admin or own message)
    if ($action === 'delete') {
        $mid = (int)($_POST['mid'] ?? 0);
        $msg = $db->prepare("SELECT user_id FROM coordinator_chat WHERE id=?");
        $msg->execute([$mid]);
        $row = $msg->fetch();
        if ($row && ($row['user_id'] == $user['id'] || $user['role'] === 'admin')) {
            $db->prepare("DELETE FROM coordinator_chat WHERE id=?")->execute([$mid]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Not allowed']);
        }
        exit;
    }

    // Poll for new messages
    if ($action === 'poll') {
        $since = (int)($_POST['since'] ?? 0);
        $stmt  = $db->prepare("
            SELECT c.*, u.callsign, u.username, u.role, u.first_name,
                   r.message AS reply_text, ru.callsign AS reply_call
            FROM coordinator_chat c
            JOIN users u ON u.id = c.user_id
            LEFT JOIN coordinator_chat r  ON r.id  = c.reply_to
            LEFT JOIN users ru ON ru.id = r.user_id
            WHERE c.id > ?
            ORDER BY c.created_at ASC
            LIMIT 50
        ");
        $stmt->execute([$since]);
        echo json_encode($stmt->fetchAll());
        exit;
    }
}

// ── Load messages with pagination ────────────────────────────
$before_id = isset($_GET['before']) ? (int)$_GET['before'] : 0;
$per_page = 100;
$where_clause = $before_id ? "AND c.id < $before_id" : "";
$messages = $db->query("
    SELECT c.*, u.callsign, u.username, u.role, u.first_name,
           r.message AS reply_text, ru.callsign AS reply_call
    FROM coordinator_chat c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN coordinator_chat r  ON r.id  = c.reply_to
    LEFT JOIN users ru ON ru.id = r.user_id
    WHERE 1=1 $where_clause
    ORDER BY c.created_at DESC
    LIMIT $per_page
")->fetchAll();
$messages = array_reverse($messages);
$last_id  = $messages ? (int)end($messages)['id'] : 0;
$first_id = $messages ? (int)$messages[0]['id'] : 0;
$total_msgs = (int)$db->query("SELECT COUNT(*) FROM coordinator_chat")->fetchColumn();
$has_earlier = $first_id > 1;

// Get all users for online status display
$users = $db->query("SELECT id, callsign, username, role, district FROM users WHERE active=1 ORDER BY role, callsign")->fetchAll();

$page_title = 'Coordinator Chat';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title"><i class="fa fa-comments"></i> Coordinator Chat</div>

<div style="display:grid;grid-template-columns:1fr 220px;gap:16px;height:calc(100vh - 180px);min-height:500px">

  <!-- Chat window -->
  <div class="card" style="display:flex;flex-direction:column;overflow:hidden">
    <div class="card-header" style="flex-shrink:0">
      <i class="fa fa-message"></i> Internal Coordinator Messages
      <span class="text-muted" style="font-size:.75rem;margin-left:8px">Only visible to coordinators and admins</span>
    </div>

    <!-- Messages -->
    <?php if ($has_earlier): ?>
    <div style="text-align:center;padding:8px;background:#f8fafc;border-bottom:1px solid var(--border)">
      <a href="?before=<?= $first_id ?>" class="btn btn-secondary btn-sm">
        <i class="fa fa-clock-rotate-left"></i> Load Earlier Messages
      </a>
      <span style="color:var(--muted);font-size:.8rem;margin-left:8px">
        Showing <?= count($messages) ?> of <?= number_format($total_msgs) ?> total messages
      </span>
    </div>
    <?php endif; ?>
    <div id="chat-messages" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px">
      <?php foreach ($messages as $m): ?>
      <?php
        $is_me = $m['user_id'] == $user['id'];
        $role_color = $m['role'] === 'admin' ? '#dc2626' : '#2563a8';
      ?>
      <div class="chat-msg" id="msg-<?= $m['id'] ?>" style="display:flex;flex-direction:column;align-items:<?= $is_me?'flex-end':'flex-start' ?>">
        <?php if ($m['reply_to']): ?>
        <div style="font-size:.72rem;color:var(--muted);background:#f0f4f8;padding:3px 8px;border-radius:3px;border-left:2px solid var(--border);margin-bottom:3px;max-width:80%">
          <strong><?= h($m['reply_call']) ?>:</strong> <?= h(substr($m['reply_text'],0,60)) ?><?= strlen($m['reply_text'])>60?'…':'' ?>
        </div>
        <?php endif; ?>
        <div style="max-width:80%">
          <div style="font-size:.72rem;color:var(--muted);margin-bottom:2px;<?= $is_me?'text-align:right':'' ?>">
            <strong style="color:<?= $role_color ?>"><?= h($m['first_name'] ? $m['first_name'].' ('.$m['callsign'].')' : ($m['callsign'] ?: $m['username'])) ?></strong>
            <?= $m['district'] ? '<span class="district-badge" style="font-size:.65rem">'.h($m['district']).'</span>' : '' ?>
            <span style="margin-left:4px"><?= date('M j g:ia', strtotime($m['created_at'])) ?></span>
          </div>
          <div style="background:<?= $is_me?'var(--primary-m)':'var(--white)' ?>;color:<?= $is_me?'#fff':'var(--text)' ?>;padding:8px 12px;border-radius:<?= $is_me?'12px 12px 3px 12px':'12px 12px 12px 3px' ?>;border:1px solid <?= $is_me?'transparent':'var(--border)' ?>;font-size:.88rem;word-break:break-word" class="chat-bubble">
            <?= preg_replace('/@([A-Za-z0-9_]+)/', '<span style="background:#fef3c7;color:#92400e;font-weight:bold;padding:0 3px;border-radius:3px">@$1</span>', nl2br(h($m['message']))) ?>
          </div>
          <div style="font-size:.68rem;margin-top:3px;display:flex;gap:8px;<?= $is_me?'justify-content:flex-end':'' ?>">
            <a href="#" onclick="setReply(<?= $m['id'] ?>, '<?= h(addslashes($m['callsign'])) ?>', '<?= h(addslashes(substr($m['message'],0,60))) ?>'); return false;" style="color:var(--muted)"><i class="fa fa-reply"></i> Reply</a>
            <?php if ($is_me || $user['role']==='admin'): ?>
            <a href="#" onclick="deleteMsg(<?= $m['id'] ?>); return false;" style="color:var(--danger)"><i class="fa fa-trash"></i></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$messages): ?>
      <div style="text-align:center;color:var(--muted);padding:40px">
        <i class="fa fa-comments" style="font-size:2rem;display:block;margin-bottom:10px"></i>
        No messages yet. Start the conversation!
      </div>
      <?php endif; ?>
    </div>

    <!-- Reply preview -->
    <div id="reply-bar" style="display:none;padding:6px 16px;background:#eff6ff;border-top:1px solid var(--border);font-size:.8rem;display:none;align-items:center;justify-content:space-between">
      <span><i class="fa fa-reply"></i> Replying to <strong id="reply-name"></strong>: <em id="reply-preview"></em></span>
      <a href="#" onclick="clearReply(); return false;" style="color:var(--danger)"><i class="fa fa-times"></i></a>
    </div>

    <!-- Input -->
    <div style="padding:12px 16px;border-top:1px solid var(--border);background:#f8fafc;flex-shrink:0">
      <div style="display:flex;gap:8px;align-items:flex-end">
        <textarea id="chat-input" rows="2" placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
          style="flex:1;resize:none;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);font-size:.88rem;font-family:inherit"
          onkeydown="handleKey(event)"></textarea>
        <button onclick="sendMessage()" class="btn btn-primary" style="height:52px;padding:0 16px"><i class="fa fa-paper-plane"></i></button>
      </div>
      <div style="font-size:.72rem;color:var(--muted);margin-top:4px">Enter to send &bull; Shift+Enter for new line</div>
    </div>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="card">
      <div class="card-header"><i class="fa fa-users"></i> Team</div>
      <div class="card-body" style="padding:10px">
        <?php foreach ($users as $u): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border)">
          <div style="width:8px;height:8px;border-radius:50%;background:#cbd5e1;flex-shrink:0"></div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.82rem;color:<?= $u['role']==='admin'?'#dc2626':'var(--primary)' ?>"><?= h($u['callsign'] ?: $u['username']) ?></div>
            <div style="font-size:.7rem;color:var(--muted)"><?= h($u['role']) ?><?= $u['district'] ?? 'Admin'?' · '.h($u['district'] ?? 'Admin'):'' ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="fa fa-circle-info"></i> Quick Links</div>
      <div class="card-body" style="padding:10px;display:flex;flex-direction:column;gap:6px">
        <a href="<?= BASE_PATH ?>/conflicts.php" class="btn btn-sm btn-warning" style="text-align:center"><i class="fa fa-triangle-exclamation"></i> Conflicts</a>
        <a href="<?= BASE_PATH ?>/admin/requests.php" class="btn btn-sm btn-primary" style="text-align:center"><i class="fa fa-inbox"></i> New Requests</a>
        <a href="<?= BASE_PATH ?>/admin/update_requests.php" class="btn btn-sm btn-secondary" style="text-align:center"><i class="fa fa-pen"></i> Updates</a>
      </div>
    </div>
  </div>

</div>

<script>
let lastId = <?= $last_id ?>;
let replyTo = null;
const myId = <?= $user['id'] ?>;
const myCall = '<?= h($user['callsign'] ?: $user['username']) ?>';

// Scroll to bottom on load
window.addEventListener('load', () => {
  const box = document.getElementById('chat-messages');
  box.scrollTop = box.scrollHeight;
});

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function sendMessage() {
  const input = document.getElementById('chat-input');
  const msg = input.value.trim();
  if (!msg) return;

  const data = new FormData();
  data.append('action', 'post');
  data.append('message', msg);
  if (replyTo) data.append('reply_to', replyTo);

  fetch('', { method:'POST', body:data })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        input.value = '';
        clearReply();
        poll();
      }
    });
}

function setReply(id, call, preview) {
  replyTo = id;
  document.getElementById('reply-bar').style.display = 'flex';
  document.getElementById('reply-name').textContent = call;
  document.getElementById('reply-preview').textContent = preview + (preview.length >= 60 ? '…' : '');
  document.getElementById('chat-input').focus();
}

function clearReply() {
  replyTo = null;
  document.getElementById('reply-bar').style.display = 'none';
}

function deleteMsg(id) {
  if (!confirm('Delete this message?')) return;
  const data = new FormData();
  data.append('action', 'delete');
  data.append('mid', id);
  fetch('', { method:'POST', body:data })
    .then(r => r.json())
    .then(d => { if (d.ok) { const el = document.getElementById('msg-'+id); if(el) el.remove(); }});
}

function formatMessage(text) {
  // Escape HTML first
  let safe = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  // Highlight @mentions
  safe = safe.replace(/@([A-Za-z0-9_]+)/g, '<span style="background:#fef3c7;color:#92400e;font-weight:bold;padding:0 3px;border-radius:3px">@$1</span>');
  // Convert newlines
  safe = safe.replace(/\n/g,'<br>');
  return safe;
}

function appendMessage(m) {
  const box = document.getElementById('chat-messages');
  const isMe = m.user_id == myId;
  const roleColor = m.role === 'admin' ? '#dc2626' : '#2563a8';
  const date = new Date(m.created_at.replace(' ','T'));
  const time = date.toLocaleString('en-US', {month:'short',day:'numeric',hour:'numeric',minute:'2-digit',hour12:true});

  let replyHtml = '';
  if (m.reply_to && m.reply_text) {
    replyHtml = `<div style="font-size:.72rem;color:var(--muted);background:#f0f4f8;padding:3px 8px;border-radius:3px;border-left:2px solid var(--border);margin-bottom:3px;max-width:80%">
      <strong>${m.reply_call||''}:</strong> ${m.reply_text.substring(0,60)}${m.reply_text.length>60?'…':''}
    </div>`;
  }

  const deleteBtn = (m.user_id == myId || '<?= $user['role'] ?>' === 'admin')
    ? `<a href="#" onclick="deleteMsg(${m.id}); return false;" style="color:var(--danger)"><i class="fa fa-trash"></i></a>` : '';

  const html = `<div class="chat-msg" id="msg-${m.id}" style="display:flex;flex-direction:column;align-items:${isMe?'flex-end':'flex-start'}">
    ${replyHtml}
    <div style="max-width:80%">
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:2px;${isMe?'text-align:right':''}">
        <strong style="color:${roleColor}">${m.first_name ? m.first_name+' ('+m.callsign+')' : (m.callsign||m.username)}</strong>
        ${m.district?`<span class="district-badge" style="font-size:.65rem">${m.district}</span>`:''}
        <span style="margin-left:4px">${time}</span>
      </div>
      <div style="background:${isMe?'var(--primary-m)':'var(--white)'};color:${isMe?'#fff':'var(--text)'};padding:8px 12px;border-radius:${isMe?'12px 12px 3px 12px':'12px 12px 12px 3px'};border:1px solid ${isMe?'transparent':'var(--border)'};font-size:.88rem;word-break:break-word">
        ${formatMessage(m.message)}
      </div>
      <div style="font-size:.68rem;margin-top:3px;display:flex;gap:8px;${isMe?'justify-content:flex-end':''}">
        <a href="#" onclick="setReply(${m.id},'${(m.callsign||m.username).replace(/'/g,"\\'")}','${m.message.substring(0,60).replace(/'/g,"\\'")}'); return false;" style="color:var(--muted)"><i class="fa fa-reply"></i> Reply</a>
        ${deleteBtn}
      </div>
    </div>
  </div>`;

  const wasAtBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 50;
  box.insertAdjacentHTML('beforeend', html);
  if (wasAtBottom) box.scrollTop = box.scrollHeight;
}

function poll() {
  const data = new FormData();
  data.append('action', 'poll');
  data.append('since', lastId);
  fetch('', { method:'POST', body:data })
    .then(r => r.json())
    .then(msgs => {
      msgs.forEach(m => {
        if (!document.getElementById('msg-'+m.id)) {
          appendMessage(m);
          lastId = Math.max(lastId, m.id);
        }
      });
    });
}

// Poll every 10 seconds
setInterval(poll, 10000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
