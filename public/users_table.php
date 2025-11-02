<?php
require __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;

$pdo = Connection::get();
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (preg_match('#^/api/users(?:/(\d+))?$#', $requestUri, $m)) {
    header('Content-Type: application/json');
    $userId = $m[1] ?? null;

    try {
        if ($method === 'GET' && !$userId) {
            $users = $pdo->query("SELECT * FROM users ORDER BY idx DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $users]);
            exit;
        }

        if ($method === 'GET' && $userId) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE idx = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if ($method === 'POST') {
            // validate duplicate email before INSERT to return a friendly message
            $email = trim($data['Email'] ?? '');
            if ($email !== '') {
                $check = $pdo->prepare("SELECT idx FROM users WHERE Email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Email already exists']);
                    exit;
                }
            }
            $zoneAlert = is_array($data['ZoneAlert'] ?? null) ? json_encode($data['ZoneAlert']) : '[]';
            $stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, ZoneAlert) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['FirstName'] ?? '',
                $data['LastName'] ?? '',
                $email,
                $data['Timezone'] ?? 'America/New_York',
                $data['PushoverUser'] ?? '',
                $data['PushoverToken'] ?? '',
                $data['NtfyUser'] ?? '',
                $data['NtfyPassword'] ?? '',
                $data['NtfyToken'] ?? '',
                $zoneAlert
            ]);
            backupUsersTable($pdo);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        }

        if ($method === 'PUT' && $userId) {
            // validate duplicate email before UPDATE (exclude current user)
            $email = trim($data['Email'] ?? '');
            if ($email !== '') {
                $check = $pdo->prepare("SELECT idx FROM users WHERE Email = ? AND idx != ?");
                $check->execute([$email, $userId]);
                if ($check->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Email already exists']);
                    exit;
                }
            }
            $zoneAlert = is_array($data['ZoneAlert'] ?? null) ? json_encode($data['ZoneAlert']) : '[]';
            $stmt = $pdo->prepare("UPDATE users SET FirstName=?, LastName=?, Email=?, Timezone=?, PushoverUser=?, PushoverToken=?, NtfyUser=?, NtfyPassword=?, NtfyToken=?, ZoneAlert=?, UpdatedAt=CURRENT_TIMESTAMP WHERE idx=?");
            $stmt->execute([
                $data['FirstName'] ?? '',
                $data['LastName'] ?? '',
                $email,
                $data['Timezone'] ?? 'America/New_York',
                $data['PushoverUser'] ?? '',
                $data['PushoverToken'] ?? '',
                $data['NtfyUser'] ?? '',
                $data['NtfyPassword'] ?? '',
                $data['NtfyToken'] ?? '',
                $zoneAlert,
                $userId
            ]);
            backupUsersTable($pdo);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($method === 'DELETE' && $userId) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE idx = ?");
            $stmt->execute([$userId]);
            backupUsersTable($pdo);
            echo json_encode(['success' => true]);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($requestUri === '/api/zones' && $method === 'GET') {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $maxLimit = 5000;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], $maxLimit) : 500;
    $fetchAll = isset($_GET['all']) && ($_GET['all'] == '1' || $_GET['all'] === 'true');

    try {
        if ($search) {
            $stmt = $pdo->prepare("SELECT * FROM zones WHERE NAME LIKE ? OR STATE LIKE ? ORDER BY STATE, NAME LIMIT ?");
            $stmt->execute(["%{$search}%", "%{$search}%", $limit]);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $zones]);
            exit;
        }

        if ($fetchAll) {
            $zones = $pdo->query("SELECT * FROM zones ORDER BY STATE, NAME")->fetchAll(PDO::FETCH_ASSOC);
            if (count($zones) > $maxLimit) $zones = array_slice($zones, 0, $maxLimit);
            echo json_encode(['success' => true, 'data' => $zones]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM zones ORDER BY STATE, NAME LIMIT ?");
        $stmt->execute([$limit]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if (strpos($requestUri, '/api/') !== 0) {
    // Minimal UI entrypoint
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alerts - Users</title>
<link rel="stylesheet" href="">
<style>
/* updated styles: nicer colors, readable table and modal, better buttons */
body{font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial; margin:20px; background:#f4f6f8}
.header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
.nav-link{padding:8px 12px;border-radius:6px;text-decoration:none;color:#fff;background:#6c757d}
.nav-link.primary{background:#0d6efd}
.btn{padding:8px 12px;border-radius:6px;border:1px solid transparent;cursor:pointer;font-weight:600}
.btn-primary{background:#0d6efd;color:#fff}
.btn-secondary{background:#6c757d;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
table{width:100%;margin-top:16px;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
th,td{padding:12px;border-bottom:1px solid #eef2f6;text-align:left;font-size:14px}
th{background:#f8fafc;font-weight:700}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);align-items:center;justify-content:center;padding:20px;z-index:999}
.modal-content{background:#ffffff;padding:20px;width:100%;max-width:760px;border-radius:10px;box-shadow:0 12px 30px rgba(2,6,23,.2);max-height:90vh;overflow:auto}
.form-row{display:flex;gap:12px;flex-wrap:wrap}
.form-group{margin-bottom:12px;flex:1;min-width:180px}
.form-group label{display:block;font-size:13px;margin-bottom:6px;color:#222}
.form-group input, .form-group select, .form-group textarea{width:100%;padding:8px;border:1px solid #dde6ef;border-radius:6px;font-size:14px}
.select-multi{width:100%;border:1px solid #dde6ef;border-radius:6px;padding:6px;background:#fff}
.zone-search{width:100%;padding:8px;border:1px solid #dde6ef;border-radius:6px;margin-bottom:8px}
.small{font-size:13px;color:#6b7280}
.actions{display:flex;gap:8px;margin-top:12px}
.zones-cell{cursor:help;text-decoration:underline dotted}
.zone-popup{position:fixed;background:#fff;border:1px solid rgba(0,0,0,.12);padding:12px;border-radius:10px;box-shadow:0 12px 36px rgba(2,6,23,.18);max-width:640px;max-height:60vh;overflow:auto;z-index:1101;font-size:14px}
.zone-popup .copy-btn{display:inline-block;margin-top:10px;padding:8px 10px;border-radius:6px;background:#0d6efd;color:#fff;font-weight:600;cursor:pointer}
.zone-popup-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:1100;display:none}
</style>

<!-- removed early stub functions; wiring is done via event listeners later -->
</head>
<body>
<header class="header">
  <h1 style="margin:0">Users</h1>
  <nav>
    <a class="nav-link" href="/view_tables.php">View Tables</a>
    <a class="nav-link primary" href="/users_table.php" style="margin-left:8px">Users</a>
  </nav>
</header>
<button class="btn btn-primary" id="addUserBtn">Add User</button>
<table id="usersTable" style="border:0">
  <thead>
    <tr><th>ID</th><th>Name</th><th>Email</th><th>Timezone</th><th>Zones</th><th>Actions</th></tr>
  </thead>
  <tbody></tbody>
</table>

<!-- ensure closeModal is defined as a true global function before any onclick runs -->
<script>
function closeModal(){
  try {
    const m = document.getElementById('userModal');
    if (!m) return;
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
    const form = document.getElementById('userForm');
    if (form) form.reset();
  } catch (e) { console.error('closeModal error', e); }
}
// also attach to window to be extra-safe for any callers that expect window.closeModal
window.closeModal = closeModal;
</script>

<!-- modal improved: use a multi-select control for zones -->
<div id="userModal" class="modal" aria-hidden="true">
  <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <h2 id="modalTitle">User</h2>
    <form id="userForm">
      <input type="hidden" id="userId">
      <div class="form-row">
        <div class="form-group"><label>First Name<input id="firstName" required></label></div>
        <div class="form-group"><label>Last Name<input id="lastName" required></label></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Email<input id="email" type="email" required></label></div>
        <div class="form-group"><label>Timezone<input id="timezone" value="America/New_York"></label></div>
      </div>

      <div class="form-row">
        <div class="form-group"><label>Pushover User<input id="pushoverUser" placeholder="Pushover user"></label></div>
        <div class="form-group"><label>Pushover Token<input id="pushoverToken" placeholder="Pushover token"></label></div>
      </div>

      <div class="form-row">
        <div class="form-group"><label>Ntfy User<input id="ntfyUser" placeholder="Ntfy user"></label></div>
        <div class="form-group"><label>Ntfy Password<input id="ntfyPassword" type="password" placeholder="Ntfy password"></label></div>
      </div>

      <div class="form-group"><label>Ntfy Token<input id="ntfyToken" placeholder="Ntfy token"></label></div>

      <div class="form-group">
        <label style="display:block;margin-bottom:8px;font-weight:600">State</label>
        <select id="zoneStateFilter" class="zone-search"><option value="">All states</option></select>
        <label style="display:block;margin:10px 0 6px;font-weight:600">Filter zones</label>
        <input id="zoneFilter" class="zone-search" placeholder="Filter zones (state or name)">
        <label style="display:block;margin:10px 0 6px;font-weight:600">Alert Zones</label>
        <!-- multi-select so user can select many zones with ctrl/shift or by clicking multiple -->
        <select id="zoneSelect" class="select-multi" multiple size="12" aria-label="Alert zones"></select>
        <div class="small" style="margin-top:8px">Hold Ctrl (Cmd) or Shift to select multiple, or use the filter above.</div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-primary">Save</button>
        <button id="cancelUserBtn" type="button" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
let zones = [], users = [];

// simple HTML escape for attributes/cell content
function htmlEscape(s){
  return String(s === undefined || s === null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// Render users table
function renderUsers(){
  const t = document.querySelector('#usersTable tbody');
  if (!t) return;
  t.innerHTML = (users || []).map(u=>{
    let za = [];
    try { za = JSON.parse(u.ZoneAlert || '[]'); } catch(e) { za = []; }
    // Map zone IDs to zone codes (UGC) if we have zones loaded
    let zoneCodes = [];
    try {
      if (Array.isArray(za) && zones && zones.length) {
        zoneCodes = za.map(id => {
          const z = zones.find(x => x.id == id);
          return z ? (z.ZONE || z.NAME || id) : id;
        }).filter(Boolean);
      }
    } catch (e) { zoneCodes = []; }

    const zonesDisplay = (zoneCodes && zoneCodes.length) ? zoneCodes.join(', ') : (Array.isArray(za) ? za.length + ' zones' : '0 zones');
    // short display with tooltip for long lists
    const MAX_SHOW = 5;
    let shortDisplay = zonesDisplay;
    let titleAttr = '';
    if (Array.isArray(zoneCodes) && zoneCodes.length > MAX_SHOW) {
      shortDisplay = zoneCodes.slice(0, MAX_SHOW).join(', ') + ' …';
      titleAttr = zoneCodes.join(', ');
    } else {
      titleAttr = zonesDisplay;
    }

    return `<tr>
      <td>${u.idx}</td>
      <td>${(u.FirstName||'') + ' ' + (u.LastName||'')}</td>
      <td>${u.Email || ''}</td>
      <td>${u.Timezone || ''}</td>
      <td class="zones-cell" data-full="${htmlEscape(titleAttr)}">${htmlEscape(shortDisplay)}</td>
      <td>
        <button class="btn btn-primary btn-edit" data-id="${u.idx}">Edit</button>
        <button class="btn btn-danger btn-delete" data-id="${u.idx}">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

// Render zones into the multi-select (supports normalized zone objects)
function renderZones(q='', stateFilter=''){
  const sel = document.getElementById('zoneSelect');
  if (!sel) return;
  const query = (q || '').toLowerCase().trim();
  const stateQ = (stateFilter || document.getElementById('zoneStateFilter')?.value || '').toLowerCase().trim();
  const filtered = (zones || []).filter(z => {
    const name = (z.NAME||'').toLowerCase();
    const state = (z.STATE||'').toLowerCase();
    const matchQuery = !query || name.includes(query) || state.includes(query) || (z.ZONE||'').toLowerCase().includes(query);
    const matchState = !stateQ || state === stateQ;
    return matchQuery && matchState;
  });

  const currentSel = (document.getElementById('userId') && document.getElementById('userId').value)
    ? (JSON.parse((users.find(u=>u.idx==document.getElementById('userId').value)||{}).ZoneAlert||'[]').map(v=>parseInt(v,10)))
    : [];

  sel.innerHTML = (filtered || []).map(z => {
    const id = z.id ?? z.idx ?? z.ID ?? z.IDX ?? 0;
    const selected = currentSel.includes(Number(id)) ? 'selected' : '';
    // Build an option label that includes all fields from the raw zone object for clarity
    let label = ' ';
    try {
      if (z.raw && typeof z.raw === 'object') {
        label = Object.entries(z.raw).map(([k,v]) => `${k}:${v}`).join(' | ');
      } else {
        label = `${(z.STATE||'').trim()} - ${(z.NAME||'').trim()}${z.ZONE ? ' ('+z.ZONE+')' : ''}`;
      }
    } catch (e) { label = `${(z.STATE||'').trim()} - ${(z.NAME||'').trim()}`; }
    return `<option value="${id}" ${selected}>${label}</option>`;
  }).join('');
}

// ----- ADDED: loadUsers and loadZones (fixes "loadZones is not defined") -----
async function loadUsers(){
  try {
    const r = await fetch('/api/users', { credentials: 'include' });
    const j = await r.json();
    users = (j && j.data) ? j.data : [];
    renderUsers();
  } catch (err) {
    console.error('loadUsers error', err);
    users = [];
    renderUsers();
  }
}

async function loadZones(){
  try {
    const r = await fetch('/api/zones?all=1', { credentials: 'include' });
    const j = await r.json();
    if (!j || !j.success) {
      console.error('Failed to load zones', j);
      zones = [];
      renderZones(document.getElementById('zoneFilter')?.value || '', document.getElementById('zoneStateFilter')?.value || '');
      return;
    }
    const raw = j.data || [];
    zones = raw.map(z => {
      const id = z.idx ?? z.id ?? z.ID ?? null;
      const name = z.NAME ?? z.name ?? '';
      const state = z.STATE ?? z.state ?? '';
      const zoneCode = z.ZONE ?? z.zone ?? '';
      return { id: id === null ? null : parseInt(id, 10), NAME: name, STATE: state, ZONE: zoneCode, raw: z };
    }).filter(z => z.id !== null);
    // Populate state select with unique states
    try {
      const stateSel = document.getElementById('zoneStateFilter');
      if (stateSel) {
        const states = Array.from(new Set(zones.map(z => (z.STATE||'').trim()).filter(s => s))).sort();
        stateSel.innerHTML = '<option value="">All states</option>' + states.map(s => `<option value="${s}">${s}</option>`).join('');
      }
    } catch (e) { console.error('populate state select failed', e); }

    renderZones(document.getElementById('zoneFilter')?.value || '', document.getElementById('zoneStateFilter')?.value || '');
  } catch (err) {
    console.error('loadZones error', err);
    zones = [];
    renderZones(document.getElementById('zoneFilter')?.value || '', document.getElementById('zoneStateFilter')?.value || '');
  }
}
// ----- end added -----

// Popup for zone full list (click-to-copy)
let zonePopupEl = null;
let zonePopupBackdropEl = null;
let zonePopupPinned = false;
let zonePopupCell = null;
function ensureZonePopup(){
  if (zonePopupEl) return zonePopupEl;
  // create backdrop and popup container
  zonePopupBackdropEl = document.createElement('div');
  zonePopupBackdropEl.className = 'zone-popup-backdrop';
  zonePopupBackdropEl.style.display = 'none';
  document.body.appendChild(zonePopupBackdropEl);

  zonePopupEl = document.createElement('div');
  zonePopupEl.className = 'zone-popup';
  zonePopupEl.style.display = 'none';
  zonePopupEl.setAttribute('aria-hidden','true');
  document.body.appendChild(zonePopupEl);
  return zonePopupEl;
}
function showZonePopup(cell, clientX, clientY, pinned = false){
  try {
    const full = cell.getAttribute('data-full') || '';
    const el = ensureZonePopup();
    el.innerHTML = '';

    // Header with close
    const hdr = document.createElement('div');
    hdr.style.display = 'flex';
    hdr.style.justifyContent = 'space-between';
    hdr.style.alignItems = 'center';
    hdr.style.marginBottom = '8px';
    const title = document.createElement('div');
    title.textContent = 'Full zone list';
    title.style.fontWeight = '700';
    hdr.appendChild(title);
    const closeBtn = document.createElement('button');
    closeBtn.textContent = '✕';
    closeBtn.style.border = 'none';
    closeBtn.style.background = 'transparent';
    closeBtn.style.cursor = 'pointer';
    closeBtn.addEventListener('click', () => { zonePopupPinned = false; hideZonePopup(); });
    hdr.appendChild(closeBtn);
    el.appendChild(hdr);

    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Filter inside list...';
    search.style.width = '100%';
    search.style.padding = '8px';
    search.style.marginBottom = '8px';
    search.style.borderRadius = '6px';
    search.style.border = '1px solid rgba(0,0,0,0.08)';
    el.appendChild(search);

    const list = document.createElement('div');
    list.style.maxHeight = '50vh';
    list.style.overflow = 'auto';
    list.style.padding = '6px';
    list.style.borderRadius = '6px';
    list.style.background = '#fbfdff';
    el.appendChild(list);

    const items = (full || '').split(/,\s*/).filter(Boolean);
    function renderList(filter){
      const q = (filter||'').toLowerCase().trim();
      list.innerHTML = items.filter(i => !q || i.toLowerCase().includes(q)).map(i => `<div style="padding:6px 4px;border-bottom:1px solid rgba(0,0,0,0.03)">${htmlEscape(i)}</div>`).join('');
    }
    renderList('');
    search.addEventListener('input', (e)=> renderList(e.target.value));

    const btnRow = document.createElement('div');
    btnRow.style.display = 'flex';
    btnRow.style.justifyContent = 'flex-end';
    btnRow.style.gap = '8px';
    btnRow.style.marginTop = '10px';
    const copyVisible = document.createElement('div');
    copyVisible.className = 'copy-btn';
    copyVisible.textContent = 'Copy visible';
    copyVisible.addEventListener('click', async () => {
      try { const visible = Array.from(list.children).map(n=>n.textContent).join(', '); await navigator.clipboard.writeText(visible); copyVisible.textContent = 'Copied'; setTimeout(()=>copyVisible.textContent='Copy visible', 1500); } catch(e){ console.error(e); }
    });
    const copyFull = document.createElement('div');
    copyFull.className = 'copy-btn';
    copyFull.textContent = 'Copy full list';
    copyFull.addEventListener('click', async () => { try { await navigator.clipboard.writeText(full); copyFull.textContent = 'Copied'; setTimeout(()=>copyFull.textContent='Copy full list', 1500); } catch(e){ console.error(e); } });
    btnRow.appendChild(copyVisible);
    btnRow.appendChild(copyFull);
    el.appendChild(btnRow);

    // Show as modal (centered) when pinned, otherwise near cell
    el.style.display = 'block';
    el.style.opacity = '1';
    el.setAttribute('aria-hidden','false');
    if (pinned) {
      if (zonePopupBackdropEl) zonePopupBackdropEl.style.display = 'block';
      el.style.left = '50%';
      el.style.top = '12%';
      el.style.transform = 'translateX(-50%)';
      el.style.maxWidth = '80vw';
    } else {
      const pad = 12;
      const rect = el.getBoundingClientRect();
      let left = clientX + pad;
      let top = clientY + pad;
      if (left + rect.width > window.innerWidth - pad) left = window.innerWidth - rect.width - pad;
      if (top + rect.height > window.innerHeight - pad) top = clientY - rect.height - pad;
      if (top < pad) top = pad;
      if (left < pad) left = pad;
      el.style.left = left + 'px';
      el.style.top = top + 'px';
      el.style.transform = '';
    }

    zonePopupPinned = !!pinned;
    zonePopupCell = cell;
  } catch (e) { console.error('showZonePopup error', e); }
}

function hideZonePopup(){
  try{
    const el = ensureZonePopup();
    el.style.display = 'none';
    el.setAttribute('aria-hidden','true');
    if (zonePopupBackdropEl) zonePopupBackdropEl.style.display = 'none';
    zonePopupPinned = false;
    zonePopupCell = null;
  } catch(e){}
}

// Show add modal immediately; load zones asynchronously so fetch issues don't block the UI
function showAddModal(){
  document.getElementById('modalTitle').textContent = 'Add User';
  document.getElementById('userForm').reset();
  document.getElementById('userId').value = '';

  // populate zones if already loaded, otherwise start loading in background
  if (zones.length === 0) {
    loadZones().catch(err => { console.error('loadZones error', err); });
  } else {
    renderZones(document.getElementById('zoneFilter').value || '', document.getElementById('zoneStateFilter')?.value || '');
  }

  document.getElementById('userModal').style.display = 'flex';
  document.getElementById('userModal').setAttribute('aria-hidden', 'false');
  document.getElementById('firstName').focus();
}

// Edit user: open modal immediately and ensure zones will populate when available
function editUser(id){
  const u = users.find(x => x.idx == id);
  if (!u) return;
  document.getElementById('modalTitle').textContent = 'Edit User';
  document.getElementById('userId').value = u.idx;
  document.getElementById('firstName').value = u.FirstName || '';
  document.getElementById('lastName').value = u.LastName || '';
  document.getElementById('email').value = u.Email || '';
  document.getElementById('timezone').value = u.Timezone || 'America/New_York';
  document.getElementById('pushoverUser').value = u.PushoverUser || '';
  document.getElementById('pushoverToken').value = u.PushoverToken || '';
  document.getElementById('ntfyUser').value = u.NtfyUser || '';
  document.getElementById('ntfyPassword').value = u.NtfyPassword || '';
  document.getElementById('ntfyToken').value = u.NtfyToken || '';

  if (zones.length === 0) {
  loadZones().then(() => renderZones(document.getElementById('zoneFilter').value || '', document.getElementById('zoneStateFilter')?.value || '')).catch(err => {
      console.error('loadZones error', err);
      renderZones(document.getElementById('zoneFilter').value || '', document.getElementById('zoneStateFilter')?.value || '');
    });
  } else {
    renderZones(document.getElementById('zoneFilter').value || '', document.getElementById('zoneStateFilter')?.value || '');
  }

  document.getElementById('userModal').style.display = 'flex';
  document.getElementById('userModal').setAttribute('aria-hidden', 'false');
}

async function deleteUser(id){
  if(!confirm('Delete user?')) return;
  const r = await fetch('/api/users/' + id, { method: 'DELETE', credentials: 'include' });
  const j = await r.json();
  if(j.success) loadUsers(); else alert(j.error || 'Delete failed');
}

// Attach Cancel button handler (defensive)
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('cancelUserBtn');
  if (btn) btn.addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
  const zf = document.getElementById('zoneFilter');
  if (zf) zf.addEventListener('input', (e) => renderZones(e.target.value, document.getElementById('zoneStateFilter')?.value || ''));
  const zsf = document.getElementById('zoneStateFilter');
  if (zsf) zsf.addEventListener('change', (e) => renderZones(document.getElementById('zoneFilter')?.value || '', e.target.value || ''));
  // Delegate Edit/Delete button clicks to avoid inline onclicks and startup race conditions
  const usersTable = document.getElementById('usersTable');
  if (usersTable) {
    usersTable.addEventListener('click', (ev) => {
      try {
        const btn = ev.target.closest('button');
        if (!btn) return;
        if (btn.classList.contains('btn-edit')) {
          const id = btn.getAttribute('data-id');
          if (id) editUser(Number(id));
          return;
        }
        if (btn.classList.contains('btn-delete')) {
          const id = btn.getAttribute('data-id');
          if (id) deleteUser(Number(id));
          return;
        }
      } catch (e) { console.error('usersTable click handler', e); }
    });
  }
  // Wire Add User button (replaces inline onclick)
  const addBtn = document.getElementById('addUserBtn');
  if (addBtn) addBtn.addEventListener('click', (e) => { e.preventDefault(); showAddModal(); });

  // Zone popup handlers (show on hover, hide on leave). Use delegation so rows can be re-rendered.
  // Hover to preview (non-pinned), click to pin modal with backdrop and search
  document.addEventListener('pointerover', (ev) => {
    const cell = ev.target.closest && ev.target.closest('.zones-cell');
    if (cell && !zonePopupPinned) {
      const rect = cell.getBoundingClientRect();
      showZonePopup(cell, rect.right, rect.top + (rect.height/2), false);
    }
  });
  document.addEventListener('pointerout', (ev) => {
    const cell = ev.target.closest && ev.target.closest('.zones-cell');
    if (cell && !zonePopupPinned) hideZonePopup();
  });
  // Click toggles pinned modal; clicks outside close it
  document.addEventListener('click', (ev) => {
    const cell = ev.target.closest && ev.target.closest('.zones-cell');
    if (cell) {
      const rect = cell.getBoundingClientRect();
      if (zonePopupPinned && zonePopupCell === cell) {
        zonePopupPinned = false; hideZonePopup();
      } else { showZonePopup(cell, rect.right, rect.top + (rect.height/2), true); }
      ev.stopPropagation();
      return;
    }
    // if clicking outside popup while pinned, hide it
    if (zonePopupPinned && zonePopupEl && !zonePopupEl.contains(ev.target)) {
      zonePopupPinned = false; hideZonePopup();
    }
  });
  const form = document.getElementById('userForm');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = document.getElementById('userId').value || '';
      const payload = {
        FirstName: document.getElementById('firstName').value || '',
        LastName: document.getElementById('lastName').value || '',
        Email: document.getElementById('email').value || '',
        Timezone: document.getElementById('timezone').value || 'America/New_York',
        PushoverUser: document.getElementById('pushoverUser').value || '',
        PushoverToken: document.getElementById('pushoverToken').value || '',
        NtfyUser: document.getElementById('ntfyUser').value || '',
        NtfyPassword: document.getElementById('ntfyPassword').value || '',
        NtfyToken: document.getElementById('ntfyToken').value || '',
        ZoneAlert: Array.from(document.getElementById('zoneSelect').selectedOptions || []).map(o => parseInt(o.value, 10)).filter(n => !isNaN(n))
      };

      try {
        const url = id ? '/api/users/' + encodeURIComponent(id) : '/api/users';
        const method = id ? 'PUT' : 'POST';
        const r = await fetch(url, { method, credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const j = await r.json();
        if (!r.ok || !j || j.success === false) {
          alert(j && j.error ? j.error : 'Save failed');
          return;
        }
        closeModal();
        if (typeof loadUsers === 'function') loadUsers();
      } catch (err) {
        console.error('save user error', err);
        alert('Save failed');
      }
    });
  }
  // existing startup: load users/zones
  try {
      if (typeof loadUsers === 'function' && typeof loadZones === 'function') {
          loadUsers();
          loadZones();
          return;
      }
  } catch (e) { console.error('startup check failed', e); }
  setTimeout(() => {
      try { if (typeof loadUsers === 'function') loadUsers(); if (typeof loadZones === 'function') loadZones(); } catch (err) { console.error('startup load error', err); }
  }, 100);
});
</script>
</body>
</html>
    <?php
    exit;
}

http_response_code(404);
echo 'Not Found';
exit;

function backupUsersTable($pdo) {
    try {
        $dir = dirname(\App\Config::$dbPath);
        $backupFile = $dir . '/users_backup_' . date('Y-m-d_H-i-s') . '.json';
        $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($backupFile, json_encode($users, JSON_PRETTY_PRINT));
        $backups = glob($dir . '/users_backup_*.json');
        if (count($backups) > 10) {
            usort($backups, function($a,$b){return filemtime($a)-filemtime($b);});
            foreach (array_slice($backups, 0, count($backups)-10) as $old) @unlink($old);
        }
    } catch (Exception $e) {
        error_log('backupUsersTable error: '.$e->getMessage());
    }
}