<?php
require __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;

use App\Service\NtfyNotifier;

$pdo = Connection::get();
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// API endpoint to download users table as SQLite database
if ($requestUri === '/api/users/download' && $method === 'GET') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="users_backup_' . date('Y-m-d_H-i-s') . '.sqlite"');
    
    $tempDb = null;
    try {
        // Create temporary SQLite database with users data
        // Set restrictive umask before creating temp file to avoid world-readable window
        $oldUmask = umask(0077);
        $tempDb = tempnam(sys_get_temp_dir(), 'users_backup_');
        umask($oldUmask);
        
        $tempPdo = new PDO("sqlite:$tempDb");
        $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create users table with same schema
        $tempPdo->exec("CREATE TABLE users (
            idx INTEGER PRIMARY KEY,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Email TEXT NOT NULL UNIQUE,
            Timezone TEXT DEFAULT 'America/New_York',
            PushoverUser TEXT,
            PushoverToken TEXT,
            NtfyUser TEXT,
            NtfyPassword TEXT,
            NtfyToken TEXT,
            NtfyTopic TEXT,
            ZoneAlert TEXT DEFAULT '[]',
            CreatedAt TEXT,
            UpdatedAt TEXT
        )");
        
        // Copy all users data
        $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $tempPdo->prepare("INSERT INTO users (idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($users as $user) {
            $stmt->execute([
                $user['idx'],
                $user['FirstName'],
                $user['LastName'],
                $user['Email'],
                $user['Timezone'] ?? 'America/New_York',
                $user['PushoverUser'] ?? '',
                $user['PushoverToken'] ?? '',
                $user['NtfyUser'] ?? '',
                $user['NtfyPassword'] ?? '',
                $user['NtfyToken'] ?? '',
                $user['NtfyTopic'] ?? '',
                $user['ZoneAlert'] ?? '[]',
                $user['CreatedAt'] ?? null,
                $user['UpdatedAt'] ?? null
            ]);
        }
        
        // Close connection and output file
        $tempPdo = null;
        readfile($tempDb);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to create backup: ' . $e->getMessage()]);
        exit;
    } finally {
        // Ensure temp file is cleaned up even if readfile() fails
        if ($tempDb !== null && file_exists($tempDb)) {
            @unlink($tempDb);
        }
    }
}

// API endpoint to upload and restore users table from SQLite database
if ($requestUri === '/api/users/upload' && $method === 'POST') {
    header('Content-Type: application/json');
    
    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
            exit;
        }
        
        $uploadedFile = $_FILES['file']['tmp_name'];
        
        // Validate file size (not zero, not too large, e.g. max 10MB)
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        $fileSize = filesize($uploadedFile);
        if ($fileSize === false || $fileSize === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Uploaded file is empty']);
            exit;
        }
        if ($fileSize > $maxFileSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Uploaded file is too large (max 10MB)']);
            exit;
        }

        // Validate SQLite magic header
        $expectedHeader = "SQLite format 3\0";
        $handle = fopen($uploadedFile, 'rb');
        if ($handle === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to open uploaded file']);
            exit;
        }
        try {
            $header = fread($handle, 16);
        } finally {
            fclose($handle);
        }
        if ($header !== $expectedHeader) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Uploaded file is not a valid SQLite database']);
            exit;
        }
        
        // Validate it's a SQLite database
        $uploadPdo = new PDO("sqlite:$uploadedFile");
        $uploadPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if users table exists
        $tables = $uploadPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid file: users table not found']);
            exit;
        }
        
        // Get users from uploaded database
        $uploadedUsers = $uploadPdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $uploadPdo = null;
        
        if (empty($uploadedUsers)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No users found in uploaded file']);
            exit;
        }
        
        // Begin transaction to replace all users
        $pdo->beginTransaction();
        
        try {
            // Clear existing users
            $pdo->exec("DELETE FROM users");
            
            // Insert uploaded users
            $stmt = $pdo->prepare("INSERT INTO users (idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $count = 0;
            foreach ($uploadedUsers as $user) {
                // Validate required fields (check for non-empty strings after trimming)
                if (
                    !isset($user['FirstName']) || trim($user['FirstName']) === '' ||
                    !isset($user['LastName']) || trim($user['LastName']) === '' ||
                    !isset($user['Email']) || trim($user['Email']) === ''
                ) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Missing required user fields: FirstName, LastName, and Email must be non-empty'
                    ]);
                    exit;
                }
                
                $stmt->execute([
                    $user['idx'] ?? null,
                    $user['FirstName'],
                    $user['LastName'],
                    $user['Email'],
                    $user['Timezone'] ?? 'America/New_York',
                    $user['PushoverUser'] ?? '',
                    $user['PushoverToken'] ?? '',
                    $user['NtfyUser'] ?? '',
                    $user['NtfyPassword'] ?? '',
                    $user['NtfyToken'] ?? '',
                    $user['NtfyTopic'] ?? '',
                    $user['ZoneAlert'] ?? '[]',
                    $user['CreatedAt'] ?? null,
                    $user['UpdatedAt'] ?? null
                ]);
                $count++;
            }
            
            $pdo->commit();
            
            // Create backup after successful upload
            backupUsersTable($pdo);
            
            echo json_encode(['success' => true, 'message' => "Successfully restored {$count} users"]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to restore users: ' . $e->getMessage()]);
        exit;
    }
}

// API endpoint to test alert workflow
if ($requestUri === '/api/test-alert' && $method === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $userId = $data['userId'] ?? null;
        
        if (!$userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'User ID is required']);
            exit;
        }
        
        // Get user details
        $stmt = $pdo->prepare("SELECT * FROM users WHERE idx = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        // Execute test_alert_workflow.php script in non-interactive mode
        // For now, return success and indicate it should be run via CLI
        echo json_encode([
            'success' => true, 
            'message' => 'Test alert feature available via CLI only. Run: php scripts/test_alert_workflow.php',
            'user' => $user['FirstName'] . ' ' . $user['LastName']
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Test failed: ' . $e->getMessage()]);
        exit;
    }
}

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
            // server-side normalize ZoneAlert to a consistent JSON array of strings
            $zoneAlert = normalizeZoneAlertForSave($data['ZoneAlert'] ?? []);
            
            // Validate NtfyTopic if provided
            $ntfyTopic = $data['NtfyTopic'] ?? '';
            if (!empty($ntfyTopic) && !NtfyNotifier::isValidTopicName($ntfyTopic)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid Ntfy Topic: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)']);
                exit;
            }
            
            $stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                $ntfyTopic,
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
            // server-side normalize ZoneAlert to a consistent JSON array of strings
            $zoneAlert = normalizeZoneAlertForSave($data['ZoneAlert'] ?? []);
            
            // Validate NtfyTopic if provided
            $ntfyTopic = $data['NtfyTopic'] ?? '';
            if (!empty($ntfyTopic) && !NtfyNotifier::isValidTopicName($ntfyTopic)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid Ntfy Topic: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE users SET FirstName=?, LastName=?, Email=?, Timezone=?, PushoverUser=?, PushoverToken=?, NtfyUser=?, NtfyPassword=?, NtfyToken=?, NtfyTopic=?, ZoneAlert=?, UpdatedAt=CURRENT_TIMESTAMP WHERE idx=?");
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
                $ntfyTopic,
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

/**
 * Normalize ZoneAlert data for database storage.
 * Converts to uppercase for STATE_ZONE format and returns JSON string.
 */
function normalizeZoneAlertForSave(array $zoneData): string {
    if (empty($zoneData)) {
        return json_encode([]);
    }

    $normalized = [];
    foreach ($zoneData as $zone) {
        $zone = trim((string)$zone);
        if ($zone === '') continue;
        
        // Convert STATE_ZONE format to uppercase for consistency
        if (preg_match('/^[a-z]{2,3}c?\d+$/i', $zone)) {
            $normalized[] = strtoupper($zone);
        } else {
            $normalized[] = $zone; // Keep FIPS codes as-is
        }
    }

    return json_encode(array_values(array_unique($normalized)));
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
/* Enhanced styles for a beautiful, modern interface */
* { box-sizing: border-box; }
body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  margin: 0;
  padding: 0;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  min-height: 100vh;
}
.page-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 20px;
}
.page-header {
  background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
  color: white;
  padding: 40px;
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  margin-bottom: 30px;
  position: relative;
  overflow: hidden;
}
.page-header::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -10%;
  width: 400px;
  height: 400px;
  background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
  border-radius: 50%;
}
.page-header h1 {
  margin: 0 0 10px 0;
  font-size: 42px;
  font-weight: 700;
  text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
  position: relative;
  z-index: 1;
}
.page-header .subtitle {
  margin: 0;
  font-size: 18px;
  opacity: 0.95;
  font-weight: 400;
  position: relative;
  z-index: 1;
}
.header-icon {
  display: inline-block;
  font-size: 48px;
  margin-right: 15px;
  vertical-align: middle;
  filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.2));
}
.content-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
  padding: 30px;
  margin-bottom: 20px;
}
.toolbar {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
  align-items: center;
}
.nav-link{padding:10px 18px;border-radius:8px;text-decoration:none;color:white;background:rgba(255,255,255,0.2);font-weight:500;transition:all 0.2s ease;}
.nav-link:hover{background:rgba(255,255,255,0.3);}
.btn{padding:10px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;font-size:14px;transition:all 0.2s ease;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,0.15);}
.btn:active{transform:translateY(0);}
.btn-primary{background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);color:white;}
.btn-secondary{background:linear-gradient(135deg,#6c757d 0%,#5a6268 100%);color:white;}
.btn-success{background:linear-gradient(135deg,#28a745 0%,#218838 100%);color:white;}
.btn-danger{background:linear-gradient(135deg,#dc3545 0%,#c82333 100%);color:white;}
.btn-info{background:linear-gradient(135deg,#17a2b8 0%,#138496 100%);color:white;}
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
.zone-list{width:100%;border:1px solid #dde6ef;border-radius:6px;background:#fff;max-height:360px;overflow:auto}
.zone-row{display:flex;gap:8px;padding:8px;border-bottom:1px solid #f1f5f9;align-items:center}
.zone-row > div{font-size:13px}
.zone-row .col-state{width:16%}
.zone-row .col-name{width:36%}
.zone-row .col-county{width:20%}
.zone-row .col-statezone{width:14%}
.zone-row .col-fips{width:14%}
input[type="file"]{display:none;}
.file-upload-label{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:linear-gradient(135deg,#17a2b8 0%,#138496 100%);color:white;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;transition:all 0.2s ease;}
.file-upload-label:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,0.15);}
</style>

<!-- removed early stub functions; wiring is done via event listeners later -->
</head>
<body>
<div class="page-container">
  <div class="page-header">
    <span class="header-icon">🌦️</span>
    <div style="display:inline-block;vertical-align:middle">
      <h1><span class="header-icon" style="display:inline;font-size:inherit;margin:0">⚡</span> Weather Alerts - User Management</h1>
      <p class="subtitle">Manage users and configure weather alert notifications</p>
    </div>
  </div>

  <div class="content-card">
    <div class="toolbar">
      <button class="btn btn-primary" id="addUserBtn">
        <span>➕</span> Add User
      </button>
      <button class="btn btn-success" id="downloadUsersBtn">
        <span>⬇️</span> Download Users
      </button>
      <label for="uploadUsersFile" class="file-upload-label">
        <span>⬆️</span> Upload Users
      </label>
      <input type="file" id="uploadUsersFile" accept=".sqlite,.db" />
    </div>
    
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
</div>

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

      <div class="form-group"><label>Ntfy Topic<input id="ntfyTopic" placeholder="Ntfy topic (optional)"></label></div>

      <div class="form-group">
        <label style="display:block;margin-bottom:8px;font-weight:600">State</label>
        <select id="zoneStateFilter" class="zone-search"><option value="">All states</option></select>
  <!-- Filter zones removed per request; keep state selector only -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin:10px 0 6px">
          <label style="font-weight:600;margin:0">Alert Zones</label>
          <button id="clearAllZonesBtn" type="button" class="btn btn-secondary" style="font-size:12px;padding:4px 8px">Clear All</button>
        </div>
        <div style="display:flex;gap:8px;font-weight:700;font-size:13px;margin-bottom:6px;color:#374151">
          <div style="width:16%">STATE</div>
          <div style="width:36%">NAME</div>
          <div style="width:20%">COUNTY</div>
          <div style="width:14%">STATE_ZONE</div>
          <div style="width:14%">FIPS</div>
        </div>
        <!-- custom multi-select list so we can show columns -->
        <div id="zoneList" class="zone-list" role="listbox" aria-label="Alert zones"></div>
        <div class="small" style="margin-top:8px">Use the state selector to narrow zones.</div>
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
// currentSelections holds the user's live selections while the modal is open.
// This allows selections to persist across state filter changes and while
// editing (before saving back to the server).
let currentSelections = new Set();
let selectionsExplicitlyCleared = false;

function initSelectionsForUser(userId){
  try {
    console.log('initSelectionsForUser called with userId:', userId);
    currentSelections.clear();
    selectionsExplicitlyCleared = false;
    if (!userId) {
      console.log('No userId provided, returning');
      return;
    }
    const stored = getStoredZoneSelections(userId) || [];
    console.log('Retrieved stored selections:', stored);
    console.log('Zones array length:', zones.length);
    
    stored.forEach(v => {
      if (v !== undefined && v !== null) {
        currentSelections.add(String(v));
        console.log('Added to currentSelections:', String(v));
      }
    });
    console.log('Final currentSelections size:', currentSelections.size);
    
    // If we didn't get any stored selections but zones aren't loaded yet,
    // we need to defer initialization until zones are available
    if (stored.length === 0 && zones.length === 0) {
      const u = users.find(x => String(x.idx) === String(userId));
      if (u && u.ZoneAlert && u.ZoneAlert !== '[]' && u.ZoneAlert.trim() !== '') {
        console.log('Deferring initialization - zones not loaded yet, ZoneAlert:', u.ZoneAlert);
        // Store userId for later initialization when zones load
        window.pendingUserIdForSelection = userId;
      }
    }
  } catch(e){ console.error('initSelectionsForUser error', e); currentSelections.clear(); }
}

// Return stored ZoneAlert as array of UGC codes for a given userId (or for current form userId)
function getStoredZoneSelections(userId){
  try {
    const id = userId || (document.getElementById('userId') && document.getElementById('userId').value) || '';
    console.log('getStoredZoneSelections called with userId:', id);
    if (!id) return [];
    const u = users.find(x => String(x.idx) === String(id));
    if (!u) {
      console.log('User not found for id:', id);
      return [];
    }
    console.log('Found user:', u.FirstName, u.LastName);
    const rawVal = u.ZoneAlert || '[]';
    console.log('Raw ZoneAlert value:', rawVal);
    let parsed = [];
    try { parsed = JSON.parse(rawVal || '[]'); } catch(e){ console.log('JSON parse error:', e); parsed = []; }
    console.log('Parsed ZoneAlert:', parsed);
    if (!Array.isArray(parsed)) return [];
    // Support several stored shapes and return an array of state_zone-like values for pre-checking
    const out = [];
    // If array of plain objects, try STATE_ZONE or ZONE
    if (parsed.length && typeof parsed[0] === 'object') {
      parsed.forEach(it => {
        if (!it) return;
        const sz = it.STATE_ZONE || it.STATEZONE || it.state_zone || it.STATE || it.ZONE || it.UGC || it.zone || '';
        if (sz) out.push(String(sz));
      });
      return Array.from(new Set(out)).filter(Boolean);
    }
    // If numeric-first, map numeric values to zones by id or FIPS
    if (parsed.length && (typeof parsed[0] === 'number' || /^[0-9]+$/.test(String(parsed[0])))) {
      // If zones aren't loaded yet, we can't map numeric IDs - return the raw values for later processing
      if (zones.length === 0) {
        return parsed.map(v => String(v));
      }
      parsed.forEach(v => {
        const found = zones.find(z => Number(z.id) === Number(v) || String((z.raw && (z.raw.FIPS||z.raw.fips||z.FIPS||''))) === String(v));
        if (found) out.push(String(found.raw && (found.raw.STATE_ZONE||found.STATE_ZONE||found.STATEZONE) || found.ZONE || ''));
        else out.push(String(v)); // Keep original if no mapping found
      });
      return Array.from(new Set(out)).filter(Boolean);
    }
    // String array: could be alternating [STATE_ZONE, FIPS, STATE_ZONE, FIPS] or simple [STATE_ZONE, STATE_ZONE]
    for (let i = 0; i < parsed.length; i++) {
      const v = parsed[i];
      if (typeof v === 'string' && /[A-Za-z]/.test(v)) {
        const sz = String(v);
        out.push(sz);
        // skip following numeric FIPS if present
        if (i+1 < parsed.length && (/^[0-9]+$/.test(String(parsed[i+1])))) i++;
      } else if (typeof v === 'string' && /^[0-9]+$/.test(v)) {
        // numeric string - try to map to zone if zones are loaded
        if (zones.length > 0) {
          const found = zones.find(z => String((z.raw && (z.raw.FIPS||z.raw.fips||z.FIPS||''))) === String(v) || String(z.id) === String(v));
          if (found) out.push(String(found.raw && (found.raw.STATE_ZONE||found.STATE_ZONE||found.STATEZONE) || found.ZONE || ''));
          else out.push(String(v)); // Keep original if no mapping found
        } else {
          out.push(String(v)); // Keep original if zones not loaded
        }
      }
    }
    console.log('getStoredZoneSelections returning:', Array.from(new Set(out)).filter(Boolean));
    return Array.from(new Set(out)).filter(Boolean);
  } catch(e){ 
    console.error('getStoredZoneSelections error:', e); 
    return []; 
  }
}

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
    // za may be array of ids or array of objects {FIPS, UGC}
    let zoneItems = [];
    try {
      if (Array.isArray(za) && zones && zones.length) {
          if (za.length && typeof za[0] === 'object') {
            // stored as objects with FIPS/UGC - map back to zone rows where possible
            zoneItems = za.map(it => {
              const f = String(it.FIPS || it.fips || '');
              const u = String(it.UGC || it.ugc || it.UGS || it.ZONE || '');
              const found = zones.find(z => (String((z.raw && (z.raw.FIPS||z.raw.fips||z.FIPS||'')) ) === f) || (String((z.ZONE||z.raw.ZONE||'')).toLowerCase() === (u||'').toLowerCase()));
              if (found) return { state: found.STATE, name: found.NAME || '', county: (found.raw && (found.raw.COUNTY||found.raw.County||'') ) || '', fips: f, ugc: u };
              return { state: '', name: '', county: '', fips: f, ugc: u };
            });
          } else if (za.length && typeof za[0] === 'string') {
            // stored as array of strings. Could be simple ['UGC','UGC',..] or alternating ['UGC','FIPS','UGC','FIPS',..]
            zoneItems = [];
            for (let i = 0; i < za.length; i++) {
              const cur = za[i];
              if (typeof cur === 'string' && /[A-Za-z]/.test(cur)) {
                const stateZoneVal = cur;
                let fips = '';
                if (i + 1 < za.length && (/^[0-9]+$/.test(String(za[i+1])))) {
                  fips = String(za[i+1]);
                  i++; // skip fips in the next iteration
                }
                const found = zones.find(z => {
                  const sz = String((z.raw && (z.raw.STATE_ZONE||z.raw.STATEZONE||z.STATE_ZONE||z.STATEZONE)) || z.STATE_ZONE || '').toLowerCase();
                  const code = String((z.ZONE || (z.raw && (z.raw.ZONE||z.raw.UGC||''))||'')).toLowerCase();
                  return sz === stateZoneVal.toLowerCase() || code === stateZoneVal.toLowerCase() || (z.raw && String(z.raw.FIPS||z.raw.fips||'') === fips);
                });
                if (found) zoneItems.push({ state: found.STATE || '', name: found.NAME || '', county: (found.raw && (found.raw.COUNTY||found.raw.County||'')) || '', fips: fips || (found.raw && (found.raw.FIPS||found.raw.fips||'')) || '', stateZone: stateZoneVal });
                else zoneItems.push({ state: '', name: '', county: '', fips, stateZone: stateZoneVal });
              } else if (typeof cur === 'number' || /^[0-9]+$/.test(String(cur))) {
                // numeric entry by itself: try to match by FIPS or id
                const f = String(cur);
                const found = zones.find(z => String((z.raw && (z.raw.FIPS||z.raw.fips||z.FIPS||''))) === f || String(z.id) === f);
                if (found) zoneItems.push({ state: found.STATE || '', name: found.NAME || '', county: (found.raw && (found.raw.COUNTY||found.raw.County||'')) || '', fips: f, stateZone: found.raw && (found.raw.STATE_ZONE||found.STATE_ZONE||found.STATEZONE) || found.ZONE || '' });
              }
            }
          } else {
            // stored as ids
            zoneItems = za.map(id => {
              const z = zones.find(x => Number(x.id) === Number(id));
              return z ? { state: z.STATE || '', name: z.NAME || '', county: (z.raw && (z.raw.COUNTY||z.raw.County||'')) || '', fips: (z.raw && (z.raw.FIPS||z.raw.fips||'')) || '', ugc: z.ZONE || (z.raw && (z.raw.ZONE||z.raw.UGC||'')) || '' } : null;
            }).filter(Boolean);
          }
        }
    } catch (e) { zoneItems = []; }

    // Show count of selected zones in the table cell; keep full details in the tooltip/popup
    const titleAttr = (zoneItems && zoneItems.length) ? zoneItems.map(it => {
      // Normalize UGC/stateZone display. Some entries may have undefined or the string "undefined";
      // prefer a meaningful STATE_ZONE value when available.
      let ugcVal = (it.ugc === undefined || it.ugc === null) ? (it.stateZone || '') : it.ugc;
      if (String(ugcVal).toLowerCase() === 'undefined') ugcVal = (it.stateZone || '');
      // Convert UGC to uppercase to avoid lowercase display
      ugcVal = String(ugcVal).toUpperCase();
      const fipsVal = (it.fips === undefined || it.fips === null) ? '' : it.fips;
      return `${it.state || ''} | ${it.name || ''} | FIPS:${fipsVal} | UGC:${ugcVal}`;
    }).join('\n') : (Array.isArray(za) ? JSON.stringify(za) : '0 zones');
    let count = 0;
    if (Array.isArray(zoneItems) && zoneItems.length) {
      count = zoneItems.length;
    } else if (Array.isArray(za)) {
      if (za.length === 0) count = 0;
      else if (typeof za[0] === 'object') count = za.length;
      else {
        // count string entries that look like STATE_ZONE (contain letters)
        const letterCount = za.filter(x => typeof x === 'string' && /[A-Za-z]/.test(x)).length;
        if (letterCount > 0) count = letterCount;
        else {
          const numericCount = za.filter(x => typeof x === 'number' || /^[0-9]+$/.test(String(x))).length;
          if (numericCount > 0) count = numericCount;
          else count = za.length;
        }
      }
    }
    const shortDisplay = `${count} zone${count === 1 ? '' : 's'}`;

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

// Render zones into the custom list view with columns
function renderZones(stateFilter=''){
  const list = document.getElementById('zoneList');
  if (!list) return;
  const stateQ = (stateFilter || document.getElementById('zoneStateFilter')?.value || '').toLowerCase().trim();
  const filtered = (zones || []).filter(z => {
    const state = (z.STATE||'').toLowerCase();
    const matchState = !stateQ || state === stateQ;
    return matchState;
  });
  const storedSelections = getStoredZoneSelections();
  console.log('renderZones - currentSelections size:', currentSelections.size);
  console.log('renderZones - storedSelections:', storedSelections);
  console.log('renderZones - filtered zones count:', filtered.length);

  list.innerHTML = (filtered || []).map(z => {
    const id = z.id ?? z.idx ?? z.ID ?? z.IDX ?? 0;
    const code = (z.ZONE || (z.raw && (z.raw.ZONE||z.raw.UGC||'')) || '').toString();
    const county = (z.raw && (z.raw.COUNTY || z.raw.County || '')) || '';
    const stateZone = (z.raw && (z.raw.STATE_ZONE || z.raw.STATEZONE || z.raw.STATE_ZONE_ID || z.STATE_ZONE || z.ZONE)) || '';
    const fips = (z.raw && (z.raw.FIPS || z.raw.fips || z.FIPS || '')) || '';
    // Prefer live currentSelections (user changes in the modal). If empty and not explicitly cleared,
    // fall back to stored selections loaded from the user record.
    // Also check numeric ID and FIPS for stored selections that might not have been mapped yet
    const isInCurrent = (currentSelections.size > 0) && 
      ((stateZone && Array.from(currentSelections).some(sel => sel.toLowerCase() === String(stateZone).toLowerCase())) || 
       Array.from(currentSelections).some(sel => sel.toLowerCase() === String(code).toLowerCase()) || 
       currentSelections.has(String(id)) || 
       currentSelections.has(String(fips)));
    const isInStored = (currentSelections.size === 0 && !selectionsExplicitlyCleared) && 
      ((stateZone && storedSelections.some(stored => stored.toLowerCase() === String(stateZone).toLowerCase())) || 
       storedSelections.some(stored => stored.toLowerCase() === String(code).toLowerCase()) || 
       storedSelections.includes(String(id)) || 
       storedSelections.includes(String(fips)));
    const checked = (isInCurrent || isInStored) ? 'checked' : '';
    
    if (checked === 'checked') {
      console.log('✓ Zone will be checked:', stateZone, 'code:', code, 'id:', id, 'fips:', fips, 'reason:', isInCurrent ? 'currentSelections' : 'storedSelections');
    } else if ((stateZone && String(stateZone).toLowerCase().includes('in0')) || (code && String(code).toLowerCase().includes('0'))) {
      console.log('✗ Indiana zone NOT checked:', stateZone, 'code:', code, 'id:', id, 'fips:', fips, 'storedSelections:', storedSelections);
    }
    return `
      <div class="zone-row" role="option" data-id="${id}" data-statezone="${htmlEscape(stateZone)}" data-ugc="${htmlEscape(code)}" data-fips="${htmlEscape(fips)}">
        <div style="width:4%"><input type="checkbox" class="zone-checkbox" value="${id}" data-statezone="${htmlEscape(stateZone)}" data-ugc="${htmlEscape(code)}" data-fips="${htmlEscape(fips)}" ${checked}></div>
        <div class="col-state">${htmlEscape(z.STATE||'')}</div>
        <div class="col-name">${htmlEscape(z.NAME||'')}</div>
        <div class="col-county">${htmlEscape(county)}</div>
        <div class="col-statezone">${htmlEscape(stateZone)}</div>
        <div class="col-fips">${htmlEscape(fips)}</div>
      </div>`;
  }).join('');

  // Wire change handlers on the checkboxes so selections are tracked live.
  try {
    const boxes = list.querySelectorAll('input.zone-checkbox');
    boxes.forEach(cb => {
      cb.removeEventListener('change', zoneCheckboxHandler);
      cb.addEventListener('change', zoneCheckboxHandler);
    });
  } catch(e){ /* ignore */ }
}

function zoneCheckboxHandler(ev){
  try {
    const cb = ev.target;
    const statezone = (cb.dataset.statezone || '').toString();
    const ugc = (cb.dataset.ugc || '').toString();
    if (cb.checked) {
      if (statezone) currentSelections.add(String(statezone));
      if (ugc) currentSelections.add(String(ugc));
    } else {
      if (statezone) currentSelections.delete(String(statezone));
      if (ugc) currentSelections.delete(String(ugc));
    }
    // Reset the flag since user is manually making selections
    selectionsExplicitlyCleared = false;
  } catch(e){ console.error('zoneCheckboxHandler', e); }
}

function clearAllZoneSelections(){
  try {
    currentSelections.clear();
    selectionsExplicitlyCleared = true;
    
    // Directly uncheck all checkboxes in the DOM to ensure form submission sees them as unchecked
    const checkboxes = document.querySelectorAll('#zoneList input.zone-checkbox');
    checkboxes.forEach(cb => {
      cb.checked = false;
    });
    
    console.log('Cleared all zone selections - unchecked', checkboxes.length, 'checkboxes');
  } catch(e){ console.error('clearAllZoneSelections', e); }
}

function isValidNtfyTopicName(topic) {
  const trimmedTopic = topic.trim();
  if (trimmedTopic === '') {
    return false;
  }
  // Check if topic contains only allowed characters: letters, numbers, underscores, hyphens
  return /^[A-Za-z0-9_-]+$/.test(trimmedTopic);
}

// ----- ADDED: loadUsers and loadZones (fixes "loadZones is not defined") -----
async function loadUsers(){
  try {
    const r = await fetch('/api/users', { credentials: 'include' });
    const j = await r.json();
    users = (j && j.data) ? j.data : [];
    // Wait for zones to be loaded before rendering users to ensure proper tooltip formatting
    if (zones.length === 0) {
      await loadZones();
    }
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
  renderZones(document.getElementById('zoneStateFilter')?.value || '');
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

    renderZones(document.getElementById('zoneStateFilter')?.value || '');
    
    // Handle deferred selection initialization for users edited before zones loaded
    if (window.pendingUserIdForSelection) {
      initSelectionsForUser(window.pendingUserIdForSelection);
      window.pendingUserIdForSelection = null;
      renderZones(document.getElementById('zoneStateFilter')?.value || '');
    }
  } catch (err) {
    console.error('loadZones error', err);
    zones = [];
    renderZones(document.getElementById('zoneStateFilter')?.value || '');
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

    const items = (full || '').split(/\n|,\s*/).filter(Boolean);
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

  // clear live selections for a fresh Add form
  currentSelections.clear();
  // populate zones if already loaded, otherwise start loading in background
  if (zones.length === 0) {
    loadZones().catch(err => { console.error('loadZones error', err); });
  } else {
    renderZones(document.getElementById('zoneStateFilter')?.value || '');
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
  document.getElementById('ntfyTopic').value = u.NtfyTopic || '';

  // Initialize live selections from stored user record so checkboxes persist
  initSelectionsForUser(u.idx);
  if (zones.length === 0) {
    loadZones().then(() => renderZones(document.getElementById('zoneStateFilter')?.value || '')).catch(err => {
      console.error('loadZones error', err);
      renderZones(document.getElementById('zoneStateFilter')?.value || '');
    });
  } else {
    renderZones(document.getElementById('zoneStateFilter')?.value || '');
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
  // State filter change handler
  const zsf = document.getElementById('zoneStateFilter');
  if (zsf) zsf.addEventListener('change', (e) => renderZones(e.target.value || ''));
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

  // Wire Clear All Zones button
  const clearAllBtn = document.getElementById('clearAllZonesBtn');
  if (clearAllBtn) clearAllBtn.addEventListener('click', (e) => { 
    e.preventDefault(); 
    clearAllZoneSelections(); 
  });

  // Add real-time validation for NTFY topic field
  const ntfyTopicInput = document.getElementById('ntfyTopic');
  if (ntfyTopicInput) {
    ntfyTopicInput.addEventListener('input', (e) => {
      const topic = e.target.value.trim();
      if (topic && !isValidNtfyTopicName(topic)) {
        e.target.setCustomValidity('Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)');
      } else {
        e.target.setCustomValidity('');
      }
    });
  }

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
      
      // Validate NTFY topic name
      const ntfyTopic = document.getElementById('ntfyTopic').value.trim();
      if (ntfyTopic && !isValidNtfyTopicName(ntfyTopic)) {
        alert('Invalid NTFY Topic: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)');
        return;
      }
      
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
        NtfyTopic: document.getElementById('ntfyTopic').value || '',
        // Build ZoneAlert as alternating [STATE_ZONE, FIPS, STATE_ZONE, FIPS, ...]
        ZoneAlert: (function(){
          const checked = Array.from(document.querySelectorAll('#zoneList input.zone-checkbox:checked') || []);
          const out = [];
          checked.forEach(cb => {
            const stateZone = (cb.dataset.statezone || '').toString();
            const fips = (cb.dataset.fips || '').toString();
            if (stateZone) out.push(String(stateZone));
            if (fips !== undefined && fips !== '') {
              // always store FIPS as string to keep ZoneAlert array consistent
              out.push(String(fips));
            }
          });
          return out;
        })()
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
          // loadUsers will wait for zones to be loaded before rendering
          loadUsers();
          return;
      }
  } catch (e) { console.error('startup check failed', e); }
  setTimeout(() => {
      try { if (typeof loadUsers === 'function') loadUsers(); } catch (err) { console.error('startup load error', err); }
  }, 100);

  // Download users button handler
  const downloadBtn = document.getElementById('downloadUsersBtn');
  if (downloadBtn) {
    downloadBtn.addEventListener('click', async () => {
      try {
        downloadBtn.disabled = true;
        downloadBtn.textContent = 'Downloading...';
        
        const response = await fetch('/api/users/download', { credentials: 'include' });
        if (!response.ok) {
          const error = await response.json();
          alert('Download failed: ' + (error.error || 'Unknown error'));
          return;
        }
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'users_backup_' + new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19) + '.sqlite';
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();
      } catch (error) {
        console.error('Download error:', error);
        alert('Download failed: ' + error.message);
      } finally {
        downloadBtn.disabled = false;
        downloadBtn.innerHTML = '<span>⬇️</span> Download Users';
      }
    });
  }

  // Upload users file handler
  const uploadInput = document.getElementById('uploadUsersFile');
  if (uploadInput) {
    uploadInput.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;

      if (!confirm('Are you sure you want to restore users from "' + file.name.replace(/"/g, '\\"') + '"? This will REPLACE all current users!')) {
        uploadInput.value = '';
        return;
      }

      try {
        const formData = new FormData();
        formData.append('file', file);

        const response = await fetch('/api/users/upload', {
          method: 'POST',
          credentials: 'include',
          body: formData
        });

        const result = await response.json();
        if (response.ok && result.success) {
          alert(result.message || 'Users restored successfully!');
          if (typeof loadUsers === 'function') loadUsers();
        } else {
          alert('Upload failed: ' + (result.error || 'Unknown error'));
        }
      } catch (error) {
        console.error('Upload error:', error);
        alert('Upload failed: ' + error.message);
      } finally {
        uploadInput.value = '';
      }
    });
  }
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
        // base data directory (same directory as configured DB)
        $dir = dirname(\App\Config::$dbPath);
        // ensure users_backup directory exists
        $backupDir = $dir . '/users_backup';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // write backup file into backupDir
        $backupFile = $backupDir . '/users_backup_' . date('Y-m-d_H-i-s') . '.json';
        $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($backupFile, json_encode($users, JSON_PRETTY_PRINT));

        // keep only the 10 most recent backups, remove older ones
        $backups = glob($backupDir . '/users_backup_*.json') ?: [];
        if (count($backups) > 10) {
            // sort by modification time descending (newest first)
            usort($backups, function($a, $b){
                return filemtime($b) - filemtime($a);
            });
            // remove everything after the first 10 entries
            $toRemove = array_slice($backups, 10);
            foreach ($toRemove as $old) {
                @unlink($old);
            }
        }
    } catch (Exception $e) {
        error_log('backupUsersTable error: '.$e->getMessage());
    }
}