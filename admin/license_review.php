<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
$db = get_db();
$page_title = 'License Review Dashboard';
include __DIR__ . '/../includes/header.php';

// ── Expired/Cancelled licenses ────────────────────────────────
$expired = $db->query("
    SELECT r.id, r.callsign, r.trustee, r.status, r.district, r.city, r.county,
           r.contact_email, r.contact_name, r.hold_date, r.hold_deadline,
           f.licensee_name, f.license_status, f.expiry_date,
           f.email as fcc_email, f.phone as fcc_phone,
           f.street_address, f.city, f.state as fcc_state,
           DATEDIFF(CURDATE(), f.expiry_date) as days_expired,
           DATEDIFF(r.hold_deadline, CURDATE()) as days_until_deadline,
           (SELECT COUNT(*) FROM coordination_actions ca WHERE ca.repeater_id=r.id) as action_count
    FROM repeaters r
    JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED','UNCOORDINATED')
    AND (f.expiry_date < CURDATE() OR f.license_status IN ('C','T'))
    -- Exclude if a new active callsign exists with this as previous_callsign
    AND NOT EXISTS (
        SELECT 1 FROM fcc_licenses f2 
        WHERE f2.previous_callsign = UPPER(TRIM(r.trustee)) 
        AND f2.license_status = 'A'
    )
    ORDER BY days_expired DESC
")->fetchAll();

// ── Callsign changes ──────────────────────────────────────────
$cs_changes = $db->query("
    SELECT r.id, r.callsign, r.trustee, r.status, r.district, r.city,
           f.callsign as new_callsign, f.licensee_name, f.expiry_date,
           f.license_status
    FROM repeaters r
    JOIN fcc_licenses f ON f.previous_callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND f.license_status = 'A'
    AND r.status NOT IN ('DEAD','DECOORDINATED')
    ORDER BY r.district, r.callsign
")->fetchAll();

// ── Expiring within 1 year ─────────────────────────────────────
$expiring = $db->query("
    SELECT r.id, r.callsign, r.trustee, r.status, r.district, r.city,
           f.licensee_name, f.expiry_date,
           DATEDIFF(f.expiry_date, CURDATE()) as days_left
    FROM repeaters r
    JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status NOT IN ('DEAD','DECOORDINATED')
    AND f.license_status = 'A'
    AND f.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 365 DAY)
    ORDER BY days_left ASC
")->fetchAll();

// ── Already on hold ───────────────────────────────────────────
$on_hold = $db->query("
    SELECT r.id, r.callsign, r.trustee, r.status, r.district, r.city,
           r.hold_reason, r.hold_date, r.hold_deadline,
           DATEDIFF(r.hold_deadline, CURDATE()) as days_left,
           f.license_status, f.expiry_date,
           (SELECT COUNT(*) FROM coordination_actions ca WHERE ca.repeater_id=r.id) as action_count
    FROM repeaters r
    LEFT JOIN fcc_licenses f ON f.callsign = UPPER(TRIM(r.trustee))
    WHERE r.archived_at IS NULL
    AND r.status IN ('ADMIN HOLD - LICENSE EXPIRED','ADMIN HOLD - HOLDER DECEASED','TRUSTEE CHANGE REQUIRED')
    ORDER BY days_left ASC
")->fetchAll();
?>

<div class="page-title"><i class="fa fa-gavel"></i> License Review Dashboard</div>
<p style="color:var(--muted);font-size:.85rem;margin-bottom:8px">
  Review required — no automatic changes have been made. Coordinators must manually take action on each case.
</p>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:10px 16px;margin-bottom:20px;font-size:.85rem;color:#1d4ed8">
  <i class="fa fa-info-circle"></i> <strong>This is an informational dashboard only.</strong>
  All actions require coordinator review and approval. Use the action buttons to document steps taken.
</div>

<!-- Summary cards -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
  <?php foreach ([
    ['❌', 'Expired/Cancelled', count($expired), 'fef2f2','fca5a5','dc2626'],
    ['🔄', 'Callsign Changed', count($cs_changes), 'eff6ff','93c5fd','1d4ed8'],
    ['⚠️', 'Expiring <1yr', count($expiring), 'fffbeb','fcd34d','92400e'],
    ['⏸️', 'Currently On Hold', count($on_hold), 'f5f3ff','c4b5fd','7c3aed'],
  ] as [$icon,$label,$count,$bg,$border,$color]): ?>
  <div style="background:#<?=$bg?>;border:1px solid #<?=$border?>;border-radius:8px;padding:12px 20px;text-align:center;min-width:130px">
    <div style="font-size:1.8rem;font-weight:bold;color:#<?=$color?>"><?=$count?></div>
    <div style="font-size:.8rem;color:var(--muted)"><?=$icon?> <?=$label?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($on_hold)): ?>
<!-- Currently on hold -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#f5f3ff;color:#7c3aed">
    <i class="fa fa-pause-circle"></i> Currently On Administrative Hold (<?=count($on_hold)?>)
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Repeater</th><th>Trustee</th><th>Status</th><th>Hold Since</th>
      <th>Deadline</th><th>Days Left</th><th>Actions Taken</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($on_hold as $r): ?>
    <?php $urgent = $r['days_left'] !== null && $r['days_left'] <= 15; ?>
    <tr style="background:<?=$urgent?'#fef2f2':'#f5f3ff'?>">
      <td><a href="<?=BASE_PATH?>/repeater.php?id=<?=$r['id']?>"><?=h($r['callsign'])?></a><br>
        <small style="color:var(--muted)"><?=h($r['city'])?>, <?=h($r['district'])?></small></td>
      <td><strong><?=h($r['trustee'])?></strong></td>
      <td><span style="font-size:.75rem;font-weight:bold;color:#7c3aed"><?=h($r['status'])?></span></td>
      <td><?=h($r['hold_date'])?></td>
      <td><?=h($r['hold_deadline'])?></td>
      <td style="font-weight:bold;color:<?=$urgent?'#dc2626':'#7c3aed'?>">
        <?=$r['days_left'] !== null ? $r['days_left'].' days' : 'No deadline'?>
        <?=$urgent?' ⚠':''?></td>
      <td><?=$r['action_count']?> logged</td>
      <td>
        <a href="<?=BASE_PATH?>/repeater.php?id=<?=$r['id']?>" class="btn btn-secondary btn-sm">View</a>
        <a href="<?=BASE_PATH?>/admin/edit_repeater.php?id=<?=$r['id']?>" class="btn btn-primary btn-sm">Edit</a>
        <a href="<?=BASE_PATH?>/admin/generate_letter.php?id=<?=$r['id']?>" class="btn btn-secondary btn-sm"><i class="fa fa-file-pdf"></i> Letter</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!empty($expired)): ?>
<!-- Expired licenses needing review -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#fef2f2;color:#dc2626">
    <i class="fa fa-triangle-exclamation"></i> Expired / Cancelled Licenses Requiring Review (<?=count($expired)?>)
  </div>
  <p style="padding:8px 16px;font-size:.82rem;color:var(--muted);margin:0;border-bottom:1px solid var(--border)">
    <i class="fa fa-info-circle"></i> Recommended action: Place on Administrative Hold, notify trustee, allow 60 days to resolve.
  </p>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Repeater</th><th>Trustee</th><th>Name</th><th>Status</th>
      <th>Expired</th><th>Days Ago</th><th>Contact</th><th>District</th>
      <th>Recommended Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($expired as $r): ?>
    <?php
      $very_old = $r['days_expired'] > 1825; // 5+ years
      $email = $r['contact_email'] ?: $r['fcc_email'];
    ?>
    <tr style="background:<?=$very_old?'#fef2f2':'#fff8f8'?>">
      <td>
        <a href="<?=BASE_PATH?>/repeater.php?id=<?=$r['id']?>" style="font-weight:bold">
          <?=h($r['callsign'])?>
        </a><br>
        <small style="color:var(--muted)"><?=h($r['city'])?>, <?=h($r['county'])?></small>
      </td>
      <td><strong><?=h($r['trustee'])?></strong></td>
      <td><?=h($r['licensee_name'])?></td>
      <td>
        <span style="color:#dc2626;font-weight:bold;font-size:.75rem">
          <?=$r['license_status']==='C'?'CANCELLED':'EXPIRED'?>
        </span>
      </td>
      <td><?=h($r['expiry_date'])?></td>
      <td style="color:#dc2626;font-weight:bold">
        <?=number_format($r['days_expired'])?>
        <?=$very_old?' <span style="font-size:.7rem">(5+ yrs)</span>':''?>
      </td>
      <td style="font-size:.8rem">
        <?php if ($email): ?>
          <a href="mailto:<?=h($email)?>"><?=h($email)?></a>
        <?php else: ?>
          <span style="color:#dc2626">No email</span>
        <?php endif; ?>
      </td>
      <td><?=h($r['district'])?></td>
      <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <a href="<?=BASE_PATH?>/admin/edit_repeater.php?id=<?=$r['id']?>"
            class="btn btn-warning btn-sm" style="font-size:.72rem">
            <i class="fa fa-pause"></i> Place on Hold
          </a>
          <?php if ($r['days_expired'] > 3650): ?>
          <a href="<?=BASE_PATH?>/admin/edit_repeater.php?id=<?=$r['id']?>"
            class="btn btn-danger btn-sm" style="font-size:.72rem">
            <i class="fa fa-ban"></i> De-coordinate
          </a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!empty($cs_changes)): ?>
<!-- Callsign changes -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#eff6ff;color:#1d4ed8">
    <i class="fa fa-rotate"></i> Trustee Callsign Changes — Administrative Update Required (<?=count($cs_changes)?>)
  </div>
  <p style="padding:8px 16px;font-size:.82rem;color:var(--muted);margin:0;border-bottom:1px solid var(--border)">
    <i class="fa fa-info-circle"></i> These trustees obtained vanity callsigns. No technical re-coordination needed — update the trustee callsign only.
  </p>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Repeater</th><th>Current (Old) Callsign</th><th>New Callsign</th>
      <th>Name</th><th>New Expiry</th><th>District</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($cs_changes as $r): ?>
    <tr style="background:#eff6ff">
      <td><a href="<?=BASE_PATH?>/repeater.php?id=<?=$r['id']?>" style="font-weight:bold"><?=h($r['callsign'])?></a><br>
        <small><?=h($r['city'])?>, <?=h($r['district'])?></small></td>
      <td><strong style="color:#dc2626"><?=h($r['trustee'])?></strong><br>
        <small style="color:#dc2626"><i class="fa fa-times-circle"></i> Old callsign</small></td>
      <td><strong style="color:#15803d"><?=h($r['new_callsign'])?></strong><br>
        <small style="color:#15803d"><i class="fa fa-check-circle"></i> Current callsign</small></td>
      <td><?=h($r['licensee_name'])?></td>
      <td><?=h($r['expiry_date'])?></td>
      <td><?=h($r['district'])?></td>
      <td>
        <a href="<?=BASE_PATH?>/admin/edit_repeater.php?id=<?=$r['id']?>"
          class="btn btn-primary btn-sm">
          <i class="fa fa-pencil"></i> Update Trustee to <?=h($r['new_callsign'])?>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!empty($expiring)): ?>
<!-- Expiring soon -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#fffbeb;color:#92400e">
    <i class="fa fa-clock"></i> Licenses Expiring Within 1 Year — Heads Up (<?=count($expiring)?>)
  </div>
  <p style="padding:8px 16px;font-size:.82rem;color:var(--muted);margin:0;border-bottom:1px solid var(--border)">
    <i class="fa fa-info-circle"></i> No action required yet. Consider sending courtesy renewal reminders for licenses expiring within 90 days.
  </p>
  <div class="table-wrap"><table class="data-table">
    <thead><tr>
      <th>Repeater</th><th>Trustee</th><th>Name</th>
      <th>Expires</th><th>Days Left</th><th>District</th>
    </tr></thead>
    <tbody>
    <?php foreach ($expiring as $r): ?>
    <?php $soon = $r['days_left'] <= 90; ?>
    <tr style="background:<?=$soon?'#fffbeb':'#fffff8'?>">
      <td><a href="<?=BASE_PATH?>/repeater.php?id=<?=$r['id']?>" style="font-weight:bold"><?=h($r['callsign'])?></a><br>
        <small><?=h($r['city'])?>, <?=h($r['district'])?></small></td>
      <td><strong><?=h($r['trustee'])?></strong></td>
      <td><?=h($r['licensee_name'])?></td>
      <td><?=h($r['expiry_date'])?></td>
      <td style="font-weight:bold;color:<?=$soon?'#92400e':'var(--text)'?>">
        <?=$r['days_left']?> days<?=$soon?' ⚠':''?>
      </td>
      <td><?=h($r['district'])?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (empty($expired) && empty($cs_changes) && empty($expiring) && empty($on_hold)): ?>
<div class="card">
  <div style="padding:40px;text-align:center;color:var(--muted)">
    <i class="fa fa-check-circle" style="font-size:2rem;color:var(--success)"></i><br><br>
    All trustee licenses are valid and current. No action required.
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
