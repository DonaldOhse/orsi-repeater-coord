<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();
$is_admin = is_logged_in() && $_SESSION['user']['role'] === 'admin';
$is_coord = is_logged_in() && in_array($_SESSION['user']['role'], ['admin','coordinator']);
$page_title = 'Help & Training Guide';
include __DIR__ . '/includes/header.php';
?>
<style>
.help-nav { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:24px; }
.help-nav a { background:var(--primary); color:#fff; padding:6px 14px; border-radius:20px; font-size:.8rem; text-decoration:none; }
.help-nav a:hover { background:var(--primary-m); }
.help-section { margin-bottom:40px; }
.help-section h2 { color:var(--primary); font-size:1.2rem; padding-bottom:8px; border-bottom:2px solid var(--primary-m); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.help-section h3 { color:var(--primary); font-size:1rem; margin:16px 0 8px; }
.help-card { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:16px; margin-bottom:12px; box-shadow:var(--shadow); }
.help-card h4 { color:var(--primary-m); margin-bottom:8px; font-size:.95rem; display:flex; align-items:center; gap:6px; }
.help-card p { color:var(--muted); font-size:.875rem; line-height:1.6; margin-bottom:8px; }
.help-card ul { color:var(--muted); font-size:.875rem; line-height:1.8; padding-left:20px; }
.step { display:flex; gap:12px; margin-bottom:12px; align-items:flex-start; }
.step-num { background:var(--primary-m); color:#fff; width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:bold; flex-shrink:0; margin-top:2px; }
.step-txt { font-size:.875rem; color:var(--text); line-height:1.6; }
.tip { background:#eff6ff; border-left:4px solid var(--info); padding:10px 14px; border-radius:0 var(--radius) var(--radius) 0; font-size:.85rem; color:#1e40af; margin:10px 0; }
.warn { background:#fffbeb; border-left:4px solid var(--warning); padding:10px 14px; border-radius:0 var(--radius) var(--radius) 0; font-size:.85rem; color:#92400e; margin:10px 0; }
.badge-role { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.7rem; font-weight:bold; vertical-align:middle; }
.badge-public { background:#e0f2fe; color:#0369a1; }
.badge-coord { background:#fef3c7; color:#92400e; }
.badge-admin { background:#fee2e2; color:#b91c1c; }
.toc { background:#f8fafc; border:1px solid var(--border); border-radius:var(--radius); padding:16px 20px; margin-bottom:24px; }
.toc h3 { margin-bottom:10px; color:var(--primary); font-size:.9rem; }
.toc ol { margin:0; padding-left:20px; }
.toc li { font-size:.85rem; margin-bottom:4px; }
.toc a { color:var(--primary-m); }
.print-btn { float:right; }
@media print { .site-header, .help-nav, .print-btn, .main-nav { display:none!important; } }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
  <div class="page-title" style="margin:0"><i class="fa fa-graduation-cap"></i> Help &amp; Training Guide</div>
  <div style="display:flex;gap:8px">
    <a href="<?= BASE_PATH ?>/help.php?pdf=1" class="btn btn-secondary print-btn" target="_blank">
      <i class="fa fa-file-pdf"></i> Download PDF
    </a>
    <button onclick="window.print()" class="btn btn-secondary">
      <i class="fa fa-print"></i> Print
    </button>
  </div>
</div>

<div class="toc">
  <h3><i class="fa fa-list"></i> Table of Contents</h3>
  <ol>
    <li><a href="#overview">System Overview</a></li>
    <li><a href="#public">Public Features</a></li>
    <li><a href="#mobile">Mobile App</a></li>
    <li><a href="#login">Logging In</a></li>
    <li><a href="#coordinator">Coordinator Tools</a></li>
    <?php if ($is_admin): ?>
    <li><a href="#admin">Admin Tools</a></li>
    <li><a href="#email">Email System</a></li>
    <li><a href="#maintenance">System Maintenance</a></li>
    <?php endif; ?>
    <li><a href="#glossary">Glossary</a></li>
  </ol>
</div>

<!-- Quick Nav -->
<div class="help-nav">
  <a href="#overview"><i class="fa fa-info-circle"></i> Overview</a>
  <a href="#public"><i class="fa fa-globe"></i> Public</a>
  <a href="#mobile"><i class="fa fa-mobile"></i> Mobile App</a>
  <a href="#login"><i class="fa fa-sign-in-alt"></i> Login</a>
  <a href="#coordinator"><i class="fa fa-user-tie"></i> Coordinator</a>
  <?php if ($is_admin): ?>
  <a href="#admin"><i class="fa fa-gear"></i> Admin</a>
  <a href="#email"><i class="fa fa-envelope"></i> Email</a>
  <a href="#maintenance"><i class="fa fa-wrench"></i> Maintenance</a>
  <?php endif; ?>
  <a href="#glossary"><i class="fa fa-book"></i> Glossary</a>
</div>

<!-- ── 1. OVERVIEW ─────────────────────────────────────────── -->
<div class="help-section" id="overview">
  <h2><i class="fa fa-info-circle"></i> 1. System Overview</h2>
  <div class="help-card">
    <h4><i class="fa fa-tower-broadcast"></i> What is the ORSI Coordination System?</h4>
    <p>The Oklahoma Repeater Society, Inc. (ORSI) Coordination System is the official database of coordinated amateur radio repeaters in Oklahoma. It is the primary source of repeater data used by other directories and applications.</p>
    <ul>
      <li><strong><?= number_format((int)$db->query("SELECT COUNT(*) FROM repeaters WHERE archived_at IS NULL")->fetchColumn()) ?></strong> repeaters in the database</li>
      <li>Covers all amateur radio bands — 10m, 6m, 2m, 1.25m, 70cm, 33cm, 23cm</li>
      <li>Organized by district: OKC, TUL, NW, NE, SW, SE</li>
      <li>Accessible via web browser and Android mobile app</li>
      <li>REST API available for third-party integrations</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-users"></i> User Roles</h4>
    <ul>
      <li><span class="badge-role badge-public">PUBLIC</span> — Anyone can browse repeaters, view maps, submit update requests</li>
      <li><span class="badge-role badge-coord">COORDINATOR</span> — Can review requests, manage repeaters in their district, access coordinator tools</li>
      <li><span class="badge-role badge-admin">ADMIN</span> — Full access to all features including user management, system settings, and email templates</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-map-marker-alt"></i> Districts</h4>
    <ul>
      <li><strong>OKC</strong> — Central Oklahoma / Oklahoma City area</li>
      <li><strong>TUL</strong> — Northeast Oklahoma / Tulsa area</li>
      <li><strong>NW</strong> — Northwest Oklahoma</li>
      <li><strong>NE</strong> — Northeast Oklahoma (outside Tulsa)</li>
      <li><strong>SW</strong> — Southwest Oklahoma</li>
      <li><strong>SE</strong> — Southeast Oklahoma</li>
    </ul>
  </div>
</div>

<!-- ── 2. PUBLIC FEATURES ──────────────────────────────────── -->
<div class="help-section" id="public">
  <h2><i class="fa fa-globe"></i> 2. Public Features <span class="badge-role badge-public">PUBLIC</span></h2>

  <div class="help-card">
    <h4><i class="fa fa-list"></i> Repeater Directory (index.php)</h4>
    <p>The main repeater listing page. Anyone can browse without logging in.</p>
    <ul>
      <li><strong>Search</strong> — Search by callsign, city, county, sponsor, or notes</li>
      <li><strong>Band Filter</strong> — Filter by amateur band (2m, 70cm, etc.)</li>
      <li><strong>Status Filter</strong> — Show only Operational, Down, Proposed, etc.</li>
      <li><strong>District/County Filter</strong> — Filter by geographic area</li>
      <li><strong>Sort</strong> — Click any column header to sort</li>
      <li><strong>Export</strong> — Download as CSV or Google Earth KML</li>
    </ul>
    <div class="tip"><i class="fa fa-lightbulb"></i> Tip: The list defaults to showing OPERATIONAL repeaters. Change the Status filter to "All" to see everything.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-map"></i> Interactive Map (map.php)</h4>
    <p>Satellite imagery map showing all repeaters with tower icons color-coded by status.</p>
    <ul>
      <li>Green = Operational, Orange = Down Temporarily, Blue = Construction, Purple = Proposed, Gray = Unknown</li>
      <li>Click any tower to see repeater details</li>
      <li>Markers cluster together when zoomed out — click a cluster to zoom in</li>
      <li>Coverage circles show estimated signal range when HAAT/ERP data is available</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-tower-broadcast"></i> Repeater Detail Page (repeater.php)</h4>
    <p>Full details for a single repeater including map, coverage area, and on-air confirmations.</p>
    <ul>
      <li>Satellite map with coverage circle overlay</li>
      <li>Confirmation markers showing where operators have heard the repeater (green=HT, blue=Mobile, gold=Base, red X=Cannot Hear)</li>
      <li>On-air confirmation count with progress bar</li>
      <li>"Submit Information Update" button for trustees to report changes</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-pen-to-square"></i> Submit Update Request (update_request.php)</h4>
    <p>Any operator or trustee can submit corrections to repeater data.</p>
    <div class="step"><div class="step-num">1</div><div class="step-txt">Find the repeater and click "Submit Information Update"</div></div>
    <div class="step"><div class="step-num">2</div><div class="step-txt">Enter your name, callsign, and email address</div></div>
    <div class="step"><div class="step-num">3</div><div class="step-txt">Select your relationship (Trustee, Club Officer, Regular User)</div></div>
    <div class="step"><div class="step-num">4</div><div class="step-txt">If you are the Trustee or Club Officer, your contact info will auto-fill the contact fields</div></div>
    <div class="step"><div class="step-num">5</div><div class="step-txt">Describe the changes needed and submit</div></div>
    <div class="step"><div class="step-num">6</div><div class="step-txt">A coordinator will review and apply the changes</div></div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-file-invoice"></i> New Coordination Request (request.php)</h4>
    <p>Submit a request for a new repeater coordination.</p>
    <ul>
      <li>Fill in all technical details: frequency, offset, tone, location, HAAT, ERP</li>
      <li>NOPC (Notice of Proposed Coordination) is automatically sent to neighboring state coordinators if within 75 miles of the state border</li>
      <li>Coordinators have 30 days to respond to NOPC notifications</li>
    </ul>
    <div class="warn"><i class="fa fa-triangle-exclamation"></i> A coordination request does not guarantee coordination. A coordinator must review and approve it.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-star"></i> Most Wanted Page (most_wanted.php)</h4>
    <p>Public page showing the top 25 repeaters needing information updates, with a database health gauge.</p>
    <ul>
      <li>Embeddable via iframe on any website</li>
      <li>Shows priority score based on missing data</li>
      <li>Direct links to submit information for each repeater</li>
    </ul>
  </div>
</div>

<!-- ── 3. MOBILE APP ───────────────────────────────────────── -->
<div class="help-section" id="mobile">
  <h2><i class="fa fa-mobile"></i> 3. Mobile App <span class="badge-role badge-public">PUBLIC</span></h2>

  <div class="help-card">
    <h4><i class="fa fa-download"></i> Installation</h4>
    <p>The Oklahoma Repeaters app is available for Android on the Google Play Store.</p>
    <ul>
      <li>Search "Oklahoma Repeater Society" on Google Play</li>
      <li>Or visit: <a href="https://play.google.com/store/apps/details?id=com.donaldohse.oklahomarepeatersociety" target="_blank">play.google.com</a></li>
      <li>iOS version not currently available</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-list"></i> Repeater List Screen</h4>
    <ul>
      <li>Automatically sorted by nearest repeater to your location</li>
      <li>Filter by band (2m, 70cm, 6m, etc.) and status</li>
      <li>Search by callsign, city, or county</li>
      <li>Tap any repeater to view full details</li>
      <li>Pull down to refresh</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-map-marker-alt"></i> Nearby Screen</h4>
    <ul>
      <li>Interactive map centered on your GPS location</li>
      <li>Select radius: 10, 25, 50, 75, or 100 miles</li>
      <li>Color-coded tower icons by status</li>
      <li>List below map shows repeaters in current map view sorted by distance</li>
      <li>Tap any list item to view repeater details</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-check-circle"></i> I Heard This Repeater</h4>
    <p>Crowdsourced on-air confirmation system.</p>
    <div class="step"><div class="step-num">1</div><div class="step-txt">Open a repeater detail page in the app</div></div>
    <div class="step"><div class="step-num">2</div><div class="step-txt">Tap the green "I Heard This Repeater!" button</div></div>
    <div class="step"><div class="step-num">3</div><div class="step-txt">Enter your callsign (saved for future use)</div></div>
    <div class="step"><div class="step-num">4</div><div class="step-txt">Select radio type: HT, Mobile, or Base</div></div>
    <div class="step"><div class="step-num">5</div><div class="step-txt">Set signal report using the S1-S9 slider (optional)</div></div>
    <div class="step"><div class="step-num">6</div><div class="step-txt">Tap Confirm — your GPS location is recorded</div></div>
    <div class="tip"><i class="fa fa-lightbulb"></i> When 2 or more unique operators confirm a repeater within 120 days, it is automatically marked OPERATIONAL. Confirmation markers appear on the map showing where people heard it from.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-volume-mute"></i> I Cannot Hear This Repeater</h4>
    <p>Report a repeater that is not responding.</p>
    <div class="step"><div class="step-num">1</div><div class="step-txt">Tap the red "I Cannot Hear This Repeater" button</div></div>
    <div class="step"><div class="step-num">2</div><div class="step-txt">Enter your callsign and radio type</div></div>
    <div class="step"><div class="step-num">3</div><div class="step-txt">Tap Submit Report</div></div>
    <div class="warn"><i class="fa fa-triangle-exclamation"></i> When 3 or more unique operators report a repeater as unheard, the district coordinator and repeater trustee are automatically notified by email.</div>
  </div>
</div>

<!-- ── 4. LOGIN ────────────────────────────────────────────── -->
<div class="help-section" id="login">
  <h2><i class="fa fa-sign-in-alt"></i> 4. Logging In</h2>

  <div class="help-card">
    <h4><i class="fa fa-key"></i> How to Log In</h4>
    <div class="step"><div class="step-num">1</div><div class="step-txt">Click "Log In" in the top right corner</div></div>
    <div class="step"><div class="step-num">2</div><div class="step-txt">Enter your username (usually your callsign) and password</div></div>
    <div class="step"><div class="step-num">3</div><div class="step-txt">Click Log In</div></div>
    <div class="tip"><i class="fa fa-lightbulb"></i> Contact the system administrator if you need an account or have forgotten your password.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-lock"></i> Changing Your Password</h4>
    <div class="step"><div class="step-num">1</div><div class="step-txt">Log in to your account</div></div>
    <div class="step"><div class="step-num">2</div><div class="step-txt">Click the 🔑 key icon next to the Log Out button</div></div>
    <div class="step"><div class="step-num">3</div><div class="step-txt">Enter your current password, then your new password twice</div></div>
    <div class="step"><div class="step-num">4</div><div class="step-txt">Click Change Password</div></div>
    <div class="tip"><i class="fa fa-lightbulb"></i> Password must be at least 8 characters.</div>
  </div>
</div>

<!-- ── 5. COORDINATOR TOOLS ────────────────────────────────── -->
<div class="help-section" id="coordinator">
  <h2><i class="fa fa-user-tie"></i> 5. Coordinator Tools <span class="badge-role badge-coord">COORDINATOR</span></h2>

  <div class="help-card">
    <h4><i class="fa fa-triangle-exclamation"></i> Conflict Scanner (conflicts.php)</h4>
    <p>Detects frequency and proximity conflicts between coordinated repeaters.</p>
    <ul>
      <li>Click "Run Conflict Scan" to scan all repeaters against coordination rules</li>
      <li>Co-channel conflicts: same output frequency within minimum separation distance</li>
      <li>Adjacent channel conflicts: nearby frequencies within interference range</li>
      <li>Resolve a conflict by clicking "Mark Resolved" and adding a resolution note</li>
      <li>Resolved conflicts are preserved across subsequent scans</li>
    </ul>
    <div class="tip"><i class="fa fa-lightbulb"></i> Run the conflict scanner after adding or modifying repeaters to check for new conflicts.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-inbox"></i> Coordination Requests (admin/requests.php)</h4>
    <p>Review and process new repeater coordination requests.</p>
    <div class="step"><div class="step-num">1</div><div class="step-txt">Click "Requests" in the navigation bar (badge shows pending count)</div></div>
    <div class="step"><div class="step-num">2</div><div class="step-txt">Review the technical details of the request</div></div>
    <div class="step"><div class="step-num">3</div><div class="step-txt">Check for conflicts with existing repeaters</div></div>
    <div class="step"><div class="step-num">4</div><div class="step-txt">Send NOPC if within 75 miles of state border</div></div>
    <div class="step"><div class="step-num">5</div><div class="step-txt">Approve (creates repeater record) or Deny with explanation</div></div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-pen-to-square"></i> Update Requests (admin/update_requests.php)</h4>
    <p>Review and apply submitted repeater information updates.</p>
    <ul>
      <li>Badge in nav shows number of pending updates</li>
      <li>Each request shows what changed (old value → new value)</li>
      <li>Click "Apply" to update the database, or "Reject" with explanation</li>
      <li>Submitter is automatically emailed when request is processed</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-pencil"></i> Edit Repeater (admin/edit_repeater.php)</h4>
    <p>Edit any repeater's information directly.</p>
    <ul>
      <li>Access from the repeater detail page or the main list</li>
      <li>Update frequency, tone, status, location, contact info, technical details</li>
      <li>All changes are logged in the audit trail</li>
      <li>Archive button moves repeater to archive (not permanently deleted)</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-volume-xmark"></i> Cannot Hear Review (admin/cant_hear_review.php)</h4>
    <p>Review repeaters that have been reported as unheard by multiple operators.</p>
    <ul>
      <li>Shows all repeaters with cannot-hear reports</li>
      <li><strong>Mark Unknown</strong> — Changes status to UNKNOWN and clears reports</li>
      <li><strong>Confirm OK</strong> — Marks as OPERATIONAL (you verified it's working) and clears reports</li>
      <li><strong>Dismiss</strong> — Clears the reports without changing status</li>
    </ul>
    <div class="warn"><i class="fa fa-triangle-exclamation"></i> The system automatically emails the coordinator and trustee when 3 reports are received. A coordinator must investigate before changing the status.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-comments"></i> Coordinator Chat (admin/chat.php)</h4>
    <p>Internal messaging system for coordinators.</p>
    <ul>
      <li>Post messages visible to all coordinators and admins</li>
      <li>Discuss pending requests, conflicts, or coordination issues</li>
      <li>Messages are persistent — not deleted automatically</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-sliders"></i> Coordination Rules (admin/rules.php)</h4>
    <p>Configure the technical rules used for conflict detection.</p>
    <ul>
      <li>Define minimum separation distances per band</li>
      <li>Set co-channel and adjacent-channel separation requirements</li>
      <li>Configure NOPC notification rules and neighboring state contacts</li>
    </ul>
  </div>
</div>

<!-- ── 6. ADMIN TOOLS ──────────────────────────────────────── -->
<?php if ($is_admin): ?>
<div class="help-section" id="admin">
  <h2><i class="fa fa-gear"></i> 6. Admin Tools <span class="badge-role badge-admin">ADMIN</span></h2>

  <div class="help-card">
    <h4><i class="fa fa-users"></i> User Management (admin/users.php)</h4>
    <ul>
      <li>Create new coordinator or admin accounts</li>
      <li>Assign coordinators to districts</li>
      <li>Enable/disable accounts</li>
      <li>Reset passwords</li>
    </ul>
    <div class="tip"><i class="fa fa-lightbulb"></i> Each district should have at least one assigned coordinator. Unassigned coordinators will not receive district-specific notifications.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-file-import"></i> Import (admin/import.php)</h4>
    <p>Bulk import repeaters from a CSV file.</p>
    <ul>
      <li>Download the CSV template for the correct column format</li>
      <li>Required fields: callsign, output frequency, input frequency, status</li>
      <li>Duplicate detection prevents importing existing callsigns</li>
      <li>Review import preview before committing</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-rotate"></i> Annual Renewals (admin/send_renewals.php)</h4>
    <p>Manages the annual renewal email system.</p>
    <ul>
      <li>Automatically runs daily at 8am via cron job</li>
      <li>Sends renewal emails to trustees whose last renewal was 11+ months ago</li>
      <li>Moves repeaters to UNKNOWN if no renewal in 5+ years</li>
      <li>Sends dead notices to trustees of DEAD repeaters annually</li>
      <li>Auto-archives DEAD repeaters with no response after 90 days</li>
      <li><strong>Dry Run</strong> button shows what would be sent without actually sending</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-bell"></i> Nudge Stale (admin/nudge_stale.php)</h4>
    <p>Send reminder emails to trustees of PROPOSED, DEAD, or UNKNOWN repeaters.</p>
    <ul>
      <li>Looks up trustee contact info via QRZ.com</li>
      <li>Sends a reminder to update their repeater status</li>
      <li>Use when trustees haven't responded to automatic renewal notices</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-box-archive"></i> Archive (admin/archive.php)</h4>
    <p>View and manage archived (removed) repeaters.</p>
    <ul>
      <li>Archived repeaters are hidden from public listings and API</li>
      <li><strong>Restore</strong> — Returns repeater to active database</li>
      <li><strong>Purge</strong> — Permanently deletes the repeater (cannot be undone)</li>
    </ul>
    <div class="warn"><i class="fa fa-triangle-exclamation"></i> Purge is permanent. Only purge if you are certain the record is not needed.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-clock-rotate-left"></i> Audit Log (admin/audit_log.php)</h4>
    <p>Complete history of all changes made to the database.</p>
    <ul>
      <li>Shows who made each change and when</li>
      <li>Records old and new values for all edits</li>
      <li>Filter by action type, user, or date range</li>
      <li>Useful for tracking down unauthorized changes or errors</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-satellite-dish"></i> Bulk Contact (admin/bulk_contact.php)</h4>
    <p>Manage contact information for multiple repeaters at once.</p>
    <ul>
      <li>QRZ.com lookup to auto-fill trustee contact information</li>
      <li>Update contact name, email, address for multiple repeaters</li>
      <li>Useful for updating records after import</li>
    </ul>
  </div>
</div>

<!-- ── 7. EMAIL SYSTEM ─────────────────────────────────────── -->
<div class="help-section" id="email">
  <h2><i class="fa fa-envelope"></i> 7. Email System <span class="badge-role badge-admin">ADMIN</span></h2>

  <div class="help-card">
    <h4><i class="fa fa-envelope-open-text"></i> Email Templates (admin/email_templates.php)</h4>
    <p>Customize all automated email messages.</p>
    <ul>
      <li>Renewal notice, renewal reminder, renewal confirmation</li>
      <li>NOPC notification and reminder</li>
      <li>Coordination approved/denied</li>
      <li>Use placeholders like <code>{{callsign}}</code>, <code>{{trustee}}</code>, <code>{{renewal_link}}</code></li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-envelope"></i> Test Email (admin/test_email.php)</h4>
    <p>Send a test email to verify the email system is working.</p>
    <ul>
      <li>Choose from renewal, NOPC, or update notification templates</li>
      <li>Sends to the admin's email address</li>
      <li>Use after making changes to email configuration</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-gear"></i> Email Settings (admin/settings.php)</h4>
    <ul>
      <li><strong>Email Enabled</strong> — Master on/off switch for all emails</li>
      <li><strong>Test Mode</strong> — Redirects all emails to a test address</li>
      <li><strong>Test Address</strong> — Where emails go when test mode is on</li>
    </ul>
    <div class="tip"><i class="fa fa-lightbulb"></i> Always use Test Mode when making changes to email templates or testing new features.</div>
  </div>
</div>

<!-- ── 8. MAINTENANCE ──────────────────────────────────────── -->
<div class="help-section" id="maintenance">
  <h2><i class="fa fa-wrench"></i> 8. System Maintenance <span class="badge-role badge-admin">ADMIN</span></h2>

  <div class="help-card">
    <h4><i class="fa fa-stethoscope"></i> System Check (admin/system_check.php)</h4>
    <p>Comprehensive health check of all system components.</p>
    <ul>
      <li>Database connectivity and table integrity</li>
      <li>Email system (Postfix, DKIM, SPF)</li>
      <li>File permissions and cron jobs</li>
      <li>API endpoint functionality</li>
      <li>Data quality metrics</li>
    </ul>
    <div class="tip"><i class="fa fa-lightbulb"></i> Run the system check after any server maintenance or configuration changes.</div>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-terminal"></i> Test Scripts</h4>
    <p>Two bash scripts are available for automated testing:</p>
    <ul>
      <li><code>scripts/site_test.sh</code> — Checks all pages load correctly, database integrity, email system, cron jobs, and data quality</li>
      <li><code>scripts/functional_test.sh</code> — Creates a test repeater and runs through the complete workflow including confirmations, cant-hear reports, archive/restore</li>
    </ul>
    <p>Run with: <code>sudo bash /var/www/w5dro.com/repeater_coord/scripts/site_test.sh</code></p>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-rotate"></i> Cron Jobs</h4>
    <ul>
      <li>Renewal emails run daily at <strong>8:00 AM</strong> from root's crontab</li>
      <li>Log file: <code>/var/log/orsi_renewals.log</code></li>
      <li>Check recent activity with: <code>orsi-renewals</code> command</li>
    </ul>
  </div>

  <div class="help-card">
    <h4><i class="fa fa-database"></i> Database Backup</h4>
    <ul>
      <li>Database: <code>ok_repeater_coord</code> on localhost</li>
      <li>Backup command: <code>mysqldump -u repeater_user -p ok_repeater_coord > backup.sql</code></li>
      <li>Run backups before major changes</li>
    </ul>
  </div>
</div>
<?php endif; ?>

<!-- ── 9. GLOSSARY ─────────────────────────────────────────── -->
<div class="help-section" id="glossary">
  <h2><i class="fa fa-book"></i> <?= $is_admin ? '9' : ($is_coord ? '6' : '5') ?>. Glossary</h2>

  <div class="help-card">
    <table class="table">
      <thead><tr><th>Term</th><th>Definition</th></tr></thead>
      <tbody>
        <tr><td><strong>Coordination</strong></td><td>Official recognition of a repeater's frequency assignment to minimize interference</td></tr>
        <tr><td><strong>NOPC</strong></td><td>Notice of Proposed Coordination — sent to neighboring state coordinators when a new repeater is proposed near a state border</td></tr>
        <tr><td><strong>HAAT</strong></td><td>Height Above Average Terrain — used to calculate coverage radius</td></tr>
        <tr><td><strong>ERP</strong></td><td>Effective Radiated Power — transmitter output minus feedline loss plus antenna gain</td></tr>
        <tr><td><strong>PL / CTCSS</strong></td><td>Private Line / Continuous Tone Coded Squelch System — subaudible tone required to access the repeater</td></tr>
        <tr><td><strong>DCS</strong></td><td>Digital Coded Squelch — digital equivalent of CTCSS</td></tr>
        <tr><td><strong>Trustee</strong></td><td>The licensed amateur responsible for the repeater</td></tr>
        <tr><td><strong>On-Air Confirmation</strong></td><td>Report from an operator confirming they can hear a repeater on the air</td></tr>
        <tr><td><strong>Dead Notice</strong></td><td>Annual email sent to trustee of a DEAD repeater asking if they plan to restore service</td></tr>
        <tr><td><strong>Archive</strong></td><td>Removing a repeater from the active database while preserving the record for potential restoration</td></tr>
        <tr><td><strong>Co-channel</strong></td><td>Two repeaters using the same output frequency</td></tr>
        <tr><td><strong>Adjacent channel</strong></td><td>Two repeaters using frequencies close enough to potentially interfere</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div style="text-align:center;padding:24px;color:var(--muted);font-size:.8rem;border-top:1px solid var(--border);margin-top:24px">
  Oklahoma Repeater Society, Inc. &mdash; Help &amp; Training Guide &mdash; <?= date('Y') ?><br>
  <a href="https://oklahomarepeatersociety.org" target="_blank">oklahomarepeatersociety.org</a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
