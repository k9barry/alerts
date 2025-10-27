<?php
require __DIR__ . '/../src/bootstrap.php';

use App\Repository\UserRepository;

session_start();

function csrf_token() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_check($t) {
    return hash_equals($_SESSION['csrf'] ?? '', $t ?? '');
}

$repo = new UserRepository();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'pushover_token' => trim($_POST['pushover_token'] ?? ''),
        'pushover_user' => trim($_POST['pushover_user'] ?? ''),
        'same_array' => array_filter(array_map('trim', explode(',', $_POST['same_array'] ?? ''))),
        'ugc_array' => array_filter(array_map('trim', explode(',', $_POST['ugc_array'] ?? ''))),
        'latitude' => $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null,
        'longitude' => $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null,
        'alert_location' => trim($_POST['alert_location'] ?? ''),
    ];
    $repo->create($data);
    header('Location: /');
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'pushover_token' => trim($_POST['pushover_token'] ?? ''),
        'pushover_user' => trim($_POST['pushover_user'] ?? ''),
        'same_array' => array_filter(array_map('trim', explode(',', $_POST['same_array'] ?? ''))),
        'ugc_array' => array_filter(array_map('trim', explode(',', $_POST['ugc_array'] ?? ''))),
        'latitude' => $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null,
        'longitude' => $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null,
        'alert_location' => trim($_POST['alert_location'] ?? ''),
    ];
    $repo->update($id, $data);
    header('Location: /');
    exit;
}

if ($action === 'delete' && isset($_GET['id'])) {
    $repo->delete((int)$_GET['id']);
    header('Location: /');
    exit;
}

$editing = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $editing = $repo->find((int)$_GET['id']);
}

$users = $repo->all();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Alerts - User Data</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="mb-4">User Data</h1>

  <div class="card mb-4">
    <div class="card-header"><?= $editing ? 'Edit User' : 'Add User' ?></div>
    <div class="card-body">
      <form method="post" action="?action=<?= $editing ? 'update' : 'create' ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
        <?php if ($editing): ?>
          <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input class="form-control" name="first_name" value="<?= htmlspecialchars($editing['first_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input class="form-control" name="last_name" value="<?= htmlspecialchars($editing['last_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($editing['email'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Pushover Token</label>
            <input class="form-control" name="pushover_token" value="<?= htmlspecialchars($editing['pushover_token'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Pushover User</label>
            <input class="form-control" name="pushover_user" value="<?= htmlspecialchars($editing['pushover_user'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">SAME Codes (comma-separated)</label>
            <input class="form-control" name="same_array" value="<?= htmlspecialchars(isset($editing) ? implode(',', json_decode($editing['same_array'] ?? '[]', true) ?: []) : '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">UGC Codes (comma-separated)</label>
            <input class="form-control" name="ugc_array" value="<?= htmlspecialchars(isset($editing) ? implode(',', json_decode($editing['ugc_array'] ?? '[]', true) ?: []) : '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Latitude</label>
            <input type="number" step="any" class="form-control" name="latitude" value="<?= htmlspecialchars($editing['latitude'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Longitude</label>
            <input type="number" step="any" class="form-control" name="longitude" value="<?= htmlspecialchars($editing['longitude'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Alert Location</label>
            <input class="form-control" name="alert_location" value="<?= htmlspecialchars($editing['alert_location'] ?? '') ?>">
          </div>
        </div>
        <div class="mt-3">
          <button class="btn btn-primary" type="submit">Save</button>
          <?php if ($editing): ?>
            <a class="btn btn-secondary" href="/">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Users</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped mb-0">
          <thead><tr>
            <th>ID</th><th>Name</th><th>Email</th><th>SAME</th><th>UGC</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><small><?= htmlspecialchars(implode(',', json_decode($u['same_array'] ?? '[]', true) ?: [])) ?></small></td>
              <td><small><?= htmlspecialchars(implode(',', json_decode($u['ugc_array'] ?? '[]', true) ?: [])) ?></small></td>
              <td>
                <a class="btn btn-sm btn-outline-primary" href="?action=edit&id=<?= (int)$u['id'] ?>">Edit</a>
                <a class="btn btn-sm btn-outline-danger" href="?action=delete&id=<?= (int)$u['id'] }" onclick="return confirm('Delete this user?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
