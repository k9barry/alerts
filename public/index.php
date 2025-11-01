<?php
require __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;

$pdo = Connection::get();
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Route handler
if (preg_match('#^/api/users(/(\d+))?$#', $requestUri, $matches)) {
    header('Content-Type: application/json');
    $userId = $matches[2] ?? null;
    
    if ($method === 'GET' && !$userId) {
        // List all users
        $users = $pdo->query("SELECT * FROM users ORDER BY idx DESC")->fetchAll();
        echo json_encode(['success' => true, 'data' => $users]);
        exit;
    } elseif ($method === 'GET' && $userId) {
        // Get single user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE idx = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        echo json_encode(['success' => true, 'data' => $user]);
        exit;
    } elseif ($method === 'POST') {
        // Create user
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, ZoneAlert) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $zoneAlert = is_array($data['ZoneAlert'] ?? null) ? json_encode($data['ZoneAlert']) : '[]';
            $stmt->execute([
                $data['FirstName'] ?? '',
                $data['LastName'] ?? '',
                $data['Email'] ?? '',
                $data['Timezone'] ?? 'America/New_York',
                $data['PushoverUser'] ?? '',
                $data['PushoverToken'] ?? '',
                $data['NtfyUser'] ?? '',
                $data['NtfyPassword'] ?? '',
                $data['NtfyToken'] ?? '',
                $zoneAlert
            ]);
            $newId = $pdo->lastInsertId();
            
            // Backup users table
            backupUsersTable($pdo);
            
            echo json_encode(['success' => true, 'id' => $newId]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($method === 'PUT' && $userId) {
        // Update user
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $stmt = $pdo->prepare(
                "UPDATE users SET FirstName=?, LastName=?, Email=?, Timezone=?, PushoverUser=?, PushoverToken=?, NtfyUser=?, NtfyPassword=?, NtfyToken=?, ZoneAlert=?, UpdatedAt=CURRENT_TIMESTAMP WHERE idx=?"
            );
            $zoneAlert = is_array($data['ZoneAlert'] ?? null) ? json_encode($data['ZoneAlert']) : '[]';
            $stmt->execute([
                $data['FirstName'] ?? '',
                $data['LastName'] ?? '',
                $data['Email'] ?? '',
                $data['Timezone'] ?? 'America/New_York',
                $data['PushoverUser'] ?? '',
                $data['PushoverToken'] ?? '',
                $data['NtfyUser'] ?? '',
                $data['NtfyPassword'] ?? '',
                $data['NtfyToken'] ?? '',
                $zoneAlert,
                $userId
            ]);
            
            // Backup users table
            backupUsersTable($pdo);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($method === 'DELETE' && $userId) {
        // Delete user
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE idx = ?");
            $stmt->execute([$userId]);
            
            // Backup users table
            backupUsersTable($pdo);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
} elseif ($requestUri === '/api/zones' && $method === 'GET') {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 500;
    
    if ($search) {
        $stmt = $pdo->prepare("SELECT * FROM zones WHERE NAME LIKE ? OR STATE LIKE ? ORDER BY STATE, NAME LIMIT ?");
        $stmt->execute(["%{$search}%", "%{$search}%", $limit]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM zones ORDER BY STATE, NAME LIMIT ?");
        $stmt->execute([$limit]);
    }
    
    $zones = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $zones]);
    exit;
}

// Serve the HTML interface for root path
if ($requestUri === '/' || $requestUri === '/index.php') {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts - User Management</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 30px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; margin: 50px auto; padding: 30px; width: 90%; max-width: 600px; border-radius: 8px; max-height: 80vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .zone-select { height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px; }
        .zone-item { padding: 5px; cursor: pointer; }
        .zone-item:hover { background: #f0f0f0; }
        .zone-item input { margin-right: 8px; }
        .actions { display: flex; gap: 10px; }
        .zone-search { margin-bottom: 10px; width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>User Management</h1>
        <button class="btn btn-primary" onclick="showAddModal()">Add New User</button>
        
        <table id="usersTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Timezone</th>
                    <th>Zones</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div id="userModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Add User</h2>
            <form id="userForm">
                <input type="hidden" id="userId">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" id="firstName" required>
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" id="lastName" required>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" id="email" required>
                </div>
                <div class="form-group">
                    <label>Timezone</label>
                    <input type="text" id="timezone" value="America/New_York">
                </div>
                <div class="form-group">
                    <label>Pushover User</label>
                    <input type="text" id="pushoverUser">
                </div>
                <div class="form-group">
                    <label>Pushover Token</label>
                    <input type="text" id="pushoverToken">
                </div>
                <div class="form-group">
                    <label>Ntfy User</label>
                    <input type="text" id="ntfyUser">
                </div>
                <div class="form-group">
                    <label>Ntfy Password</label>
                    <input type="password" id="ntfyPassword">
                </div>
                <div class="form-group">
                    <label>Ntfy Token</label>
                    <input type="text" id="ntfyToken">
                </div>
                <div class="form-group">
                    <label>Alert Zones</label>
                    <input type="text" class="zone-search" id="zoneSearch" placeholder="Search zones...">
                    <div class="zone-select" id="zoneSelect"></div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-success">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let zones = [];
        let users = [];

        async function loadUsers() {
            const res = await fetch('/api/users');
            const data = await res.json();
            users = data.data || [];
            renderUsers();
        }

        async function loadZones() {
            const res = await fetch('/api/zones');
            const data = await res.json();
            zones = data.data || [];
        }

        function renderUsers() {
            const tbody = document.querySelector('#usersTable tbody');
            tbody.innerHTML = users.map(user => {
                const zoneAlert = JSON.parse(user.ZoneAlert || '[]');
                return `
                    <tr>
                        <td>${user.idx}</td>
                        <td>${user.FirstName} ${user.LastName}</td>
                        <td>${user.Email}</td>
                        <td>${user.Timezone}</td>
                        <td>${zoneAlert.length} zones</td>
                        <td class="actions">
                            <button class="btn btn-primary" onclick="editUser(${user.idx})">Edit</button>
                            <button class="btn btn-danger" onclick="deleteUser(${user.idx})">Delete</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function renderZones(search = '') {
            const container = document.getElementById('zoneSelect');
            const filtered = search ? zones.filter(z => 
                z.NAME.toLowerCase().includes(search.toLowerCase()) || 
                z.STATE.toLowerCase().includes(search.toLowerCase())
            ) : zones;
            
            const zoneAlert = JSON.parse(document.getElementById('userId').value ? 
                users.find(u => u.idx == document.getElementById('userId').value)?.ZoneAlert || '[]' : '[]');
            
            container.innerHTML = filtered.slice(0, 50).map(zone => `
                <div class="zone-item">
                    <input type="checkbox" value="${zone.idx}" ${zoneAlert.includes(zone.idx) ? 'checked' : ''}>
                    ${zone.STATE} - ${zone.NAME} (${zone.ZONE})
                </div>
            `).join('');
        }

        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            renderZones();
            document.getElementById('userModal').style.display = 'block';
        }

        async function editUser(id) {
            const user = users.find(u => u.idx === id);
            if (!user) return;
            
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('userId').value = user.idx;
            document.getElementById('firstName').value = user.FirstName;
            document.getElementById('lastName').value = user.LastName;
            document.getElementById('email').value = user.Email;
            document.getElementById('timezone').value = user.Timezone;
            document.getElementById('pushoverUser').value = user.PushoverUser || '';
            document.getElementById('pushoverToken').value = user.PushoverToken || '';
            document.getElementById('ntfyUser').value = user.NtfyUser || '';
            document.getElementById('ntfyPassword').value = user.NtfyPassword || '';
            document.getElementById('ntfyToken').value = user.NtfyToken || '';
            
            renderZones();
            document.getElementById('userModal').style.display = 'block';
        }

        async function deleteUser(id) {
            if (!confirm('Are you sure you want to delete this user?')) return;
            
            const res = await fetch(`/api/users/${id}`, { method: 'DELETE' });
            const data = await res.json();
            
            if (data.success) {
                await loadUsers();
            } else {
                alert('Error: ' + data.error);
            }
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        document.getElementById('userForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const selectedZones = Array.from(document.querySelectorAll('#zoneSelect input:checked'))
                .map(input => parseInt(input.value));
            
            const userData = {
                FirstName: document.getElementById('firstName').value,
                LastName: document.getElementById('lastName').value,
                Email: document.getElementById('email').value,
                Timezone: document.getElementById('timezone').value,
                PushoverUser: document.getElementById('pushoverUser').value,
                PushoverToken: document.getElementById('pushoverToken').value,
                NtfyUser: document.getElementById('ntfyUser').value,
                NtfyPassword: document.getElementById('ntfyPassword').value,
                NtfyToken: document.getElementById('ntfyToken').value,
                ZoneAlert: selectedZones
            };
            
            const userId = document.getElementById('userId').value;
            const url = userId ? `/api/users/${userId}` : '/api/users';
            const method = userId ? 'PUT' : 'POST';
            
            const res = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(userData)
            });
            
            const data = await res.json();
            
            if (data.success) {
                closeModal();
                await loadUsers();
            } else {
                alert('Error: ' + data.error);
            }
        });

        document.getElementById('zoneSearch').addEventListener('input', (e) => {
            renderZones(e.target.value);
        });

        // Initialize
        loadUsers();
        loadZones();
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
    // Backup asynchronously to avoid blocking the response
    // Check if last backup was created recently (within 1 minute) to avoid excessive backups
    $dir = dirname(\App\Config::$dbPath);
    $backups = glob($dir . '/users_backup_*.json');
    
    if (!empty($backups)) {
        usort($backups, function($a, $b) { return filemtime($b) - filemtime($a); });
        $lastBackupTime = filemtime($backups[0]);
        
        // Skip backup if last one was less than 60 seconds ago
        if (time() - $lastBackupTime < 60) {
            return;
        }
    }
    
    $backupFile = $dir . '/users_backup_' . date('Y-m-d_H-i-s') . '.json';
    
    try {
        $users = $pdo->query("SELECT * FROM users")->fetchAll();
        file_put_contents($backupFile, json_encode($users, JSON_PRETTY_PRINT));
        
        // Keep only last 10 backups
        $backups = glob($dir . '/users_backup_*.json');
        if (count($backups) > 10) {
            usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($backups, 0, count($backups) - 10) as $old) {
                @unlink($old);
            }
        }
    } catch (\Exception $e) {
        // Log error but don't fail the request
        error_log("Backup failed: " . $e->getMessage());
    }
}
