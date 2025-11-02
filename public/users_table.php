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
</style>

<!-- Safe early stubs so any inline onclick won't throw before real functions load -->
<script>
window.closeModal = window.closeModal || function(){ try { const m = document.getElementById('userModal'); if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden','true'); } const f = document.getElementById('userForm'); if(f) f.reset(); } catch(e){} };
window.showAddModal = window.showAddModal || function(){ try { const m = document.getElementById('userModal'); if (m) m.style.display = 'flex'; } catch(e){} };
</script>
</head>
<body>
<header class="header">
  <h1 style="margin:0">Users</h1>
  <nav>
    <a class="nav-link" href="/view_tables.php">View Tables</a>
    <a class="nav-link primary" href="/users_table.php" style="margin-left:8px">Users</a>
  </nav>
</header>
<button class="btn btn-primary" onclick="showAddModal()">Add User</button>
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
        <label>Alert Zones</label>
        <input id="zoneSearch" class="zone-search" placeholder="Filter zones (state or name)">
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

// Render users table
function renderUsers(){
  const t = document.querySelector('#usersTable tbody');
  if (!t) return;
  t.innerHTML = (users || []).map(u=>{
    let za = [];
    try { za = JSON.parse(u.ZoneAlert || '[]'); } catch(e) { za = []; }
    return `<tr>
      <td>${u.idx}</td>
      <td>${(u.FirstName||'') + ' ' + (u.LastName||'')}</td>
      <td>${u.Email || ''}</td>
      <td>${u.Timezone || ''}</td>
      <td>${Array.isArray(za) ? za.length : 0} zones</td>
      <td>
        <button class="btn btn-primary" onclick="editUser(${u.idx})">Edit</button>
        <button class="btn btn-danger" onclick="deleteUser(${u.idx})">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

// Render zones into the multi-select (supports normalized zone objects)
function renderZones(q=''){
  const sel = document.getElementById('zoneSelect');
  if (!sel) return;
  const query = (q || '').toLowerCase().trim();
  const filtered = query ? (zones || []).filter(z => (z.NAME||'').toLowerCase().includes(query) || (z.STATE||'').toLowerCase().includes(query)) : (zones || []);
  const currentSel = (document.getElementById('userId') && document.getElementById('userId').value)
    ? (JSON.parse((users.find(u=>u.idx==document.getElementById('userId').value)||{}).ZoneAlert||'[]').map(v=>parseInt(v,10)))
    : [];

  sel.innerHTML = (filtered || []).map(z => {
    const id = z.id ?? z.idx ?? z.ID ?? z.IDX ?? 0;
    const selected = currentSel.includes(Number(id)) ? 'selected' : '';
    const label = `${(z.STATE||'').trim()} - ${(z.NAME||'').trim()}${z.ZONE ? ' ('+z.ZONE+')' : ''}`;
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
      renderZones(document.getElementById('zoneSearch')?.value || '');
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
    renderZones(document.getElementById('zoneSearch')?.value || '');
  } catch (err) {
    console.error('loadZones error', err);
    zones = [];
    renderZones(document.getElementById('zoneSearch')?.value || '');
  }
}
// ----- end added -----

// Show add modal immediately; load zones asynchronously so fetch issues don't block the UI
function showAddModal(){
  document.getElementById('modalTitle').textContent = 'Add User';
  document.getElementById('userForm').reset();
  document.getElementById('userId').value = '';

  // populate zones if already loaded, otherwise start loading in background
  if (zones.length === 0) {
    loadZones().catch(err => { console.error('loadZones error', err); });
  } else {
    renderZones(document.getElementById('zoneSearch').value || '');
  }

  document.getElementById('userModal').style.display = 'flex';
  document.getElementById('userModal').setAttribute('aria-hidden', 'false');
  document.getElementById('firstName').focus();
}

// Edit user: open modal immediately and ensure zones will populate when available
function editUser(id){
  const u = users.find(x => x.idx === id);
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
    loadZones().then(() => renderZones(document.getElementById('zoneSearch').value || '')).catch(err => {
      console.error('loadZones error', err);
      renderZones(document.getElementById('zoneSearch').value || '');
    });
  } else {
    renderZones(document.getElementById('zoneSearch').value || '');
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