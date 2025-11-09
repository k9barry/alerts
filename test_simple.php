<?php
require_once __DIR__ . '/tests/bootstrap.php';

use App\Service\AlertProcessor;
use App\Repository\AlertsRepository;
use App\DB\Connection;
use App\Config;

// Set config
Config::$ntfyEnabled = true;
Config::$ntfyTopic = '';
Config::$pushoverEnabled = false;

$pdo = Connection::get();

// Create tables
$pdo->exec("CREATE TABLE IF NOT EXISTS pending_alerts (
    id TEXT PRIMARY KEY,
    same_array TEXT,
    ugc_array TEXT,
    json TEXT
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS sent_alerts (
    id TEXT,
    result_status TEXT,
    user_id INTEGER
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    idx INTEGER PRIMARY KEY AUTOINCREMENT,
    FirstName TEXT,
    LastName TEXT,
    Email TEXT,
    NtfyTopic TEXT,
    ZoneAlert TEXT
)");

// Clean up
$pdo->exec("DELETE FROM pending_alerts WHERE id = 'test-1'");
$pdo->exec("DELETE FROM sent_alerts WHERE id = 'test-1'");
$pdo->exec("DELETE FROM users WHERE Email = 'test@example.com'");

// Create user with topic
$stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['Test', 'User', 'test@example.com', 'test-topic', '["TST001"]']);
$userId = $pdo->lastInsertId();

echo "Created user: $userId\n";

// Create alert
$stmt = $pdo->prepare("INSERT INTO pending_alerts (id, same_array, ugc_array, json) VALUES (?, ?, ?, ?)");
$stmt->execute([
    'test-1',
    json_encode(['TST001']),
    json_encode(['TST001']),
    json_encode(['properties' => ['event' => 'Test', 'headline' => 'Test headline']])
]);

echo "Created alert\n";

// Check pending
$stmt = $pdo->query("SELECT * FROM pending_alerts");
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Pending alerts: " . count($pending) . "\n";

// Create processor and process
$processor = new AlertProcessor();
echo "Calling processPending...\n";
$processor->processPending();

// Check results
$stmt = $pdo->prepare("SELECT * FROM sent_alerts WHERE id = ?");
$stmt->execute(['test-1']);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Sent alerts: " . count($sent) . "\n";
if (!empty($sent)) {
    print_r($sent);
}

// Check if alert was removed from pending
$stmt = $pdo->query("SELECT * FROM pending_alerts WHERE id = 'test-1'");
$stillPending = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Still pending: " . count($stillPending) . "\n";
