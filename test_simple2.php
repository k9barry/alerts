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

// Create tables properly
$pdo->exec("CREATE TABLE IF NOT EXISTS pending_alerts (
    id TEXT PRIMARY KEY,
    same_array TEXT,
    ugc_array TEXT,
    json TEXT,
    event TEXT,
    headline TEXT
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
    Timezone TEXT DEFAULT 'UTC',
    PushoverUser TEXT,
    PushoverToken TEXT,
    NtfyUser TEXT,
    NtfyPassword TEXT,
    NtfyToken TEXT,
    NtfyTopic TEXT,
    ZoneAlert TEXT
)");

// Clean up
$pdo->exec("DELETE FROM pending_alerts WHERE id = 'test-2'");
$pdo->exec("DELETE FROM sent_alerts WHERE id = 'test-2'");
$pdo->exec("DELETE FROM users WHERE Email = 'test2@example.com'");

// Create user with topic
$stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, Timezone, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute(['Test', 'User', 'test2@example.com', 'UTC', 'test-topic', '["TST001"]']);
$userId = $pdo->lastInsertId();

echo "Created user: $userId\n";

// Create alert
$stmt = $pdo->prepare("INSERT INTO pending_alerts (id, same_array, ugc_array, json, event, headline) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([
    'test-2',
    json_encode(['TST001']),
    json_encode(['TST001']),
    json_encode(['properties' => ['event' => 'Test Event', 'headline' => 'Test headline']]),
    'Test Event',
    'Test headline'
]);

echo "Created alert\n";

// Create processor and process
$processor = new AlertProcessor();
echo "Calling processPending...\n";
$processor->processPending();

// Check results
$stmt = $pdo->prepare("SELECT * FROM sent_alerts WHERE id = ?");
$stmt->execute(['test-2']);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Sent alerts: " . count($sent) . "\n";
if (!empty($sent)) {
    print_r($sent);
} else {
    echo "No sent alerts found\n";
}

// Check user has NtfyTopic
$stmt = $pdo->prepare("SELECT NtfyTopic FROM users WHERE idx = ?");
$stmt->execute([$userId]);
$topic = $stmt->fetchColumn();
echo "User's NtfyTopic: " . var_export($topic, true) . "\n";
