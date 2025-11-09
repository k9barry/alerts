<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/bootstrap.php';

use App\Service\AlertProcessor;
use App\Repository\AlertsRepository;
use App\DB\Connection;
use App\Config;

$pdo = Connection::get();

// Clean up
$pdo->exec("DELETE FROM incoming_alerts WHERE id LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM users WHERE Email LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM zones WHERE STATE = 'DBG'");

// Create a test zone
$stmt = $pdo->prepare("INSERT INTO zones (STATE, ZONE, NAME, STATE_ZONE) VALUES (?, ?, ?, ?)");
$stmt->execute(['DBG', '001', 'Debug Zone', 'DBGC001']);

// Create a test user with their own NtfyTopic
$userStmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?)");
$userStmt->execute([
    'Debug',
    'User',
    'test-debug-' . uniqid() . '@example.com',
    'debug-topic',
    '["DBGC001"]'
]);
$userId = (int)$pdo->lastInsertId();

echo "Created user with ID: $userId\n";

// Check ntfy initialization
Config::$ntfyTopic = '';
Config::$ntfyEnabled = true;

echo "Config ntfyEnabled: " . var_export(Config::$ntfyEnabled, true) . "\n";
echo "Config ntfyTopic: " . var_export(Config::$ntfyTopic, true) . "\n";

$processor = new AlertProcessor();
echo "AlertProcessor created\n";

// Create a test alert
$testAlert = [
    'id' => 'test-debug-' . uniqid(),
    'type' => 'Feature',
    'same_array' => json_encode(['DBGC001']),
    'ugc_array' => json_encode(['DBGC001']),
    'json' => json_encode([
        'properties' => [
            'status' => 'Actual',
            'messageType' => 'Alert',
            'event' => 'Debug Test',
            'headline' => 'Debug headline',
            'description' => 'Debug description',
        ]
    ]),
    'event' => 'Debug Test',
    'headline' => 'Debug headline',
    'description' => 'Debug description',
];

// Insert into pending_alerts
$stmt = $pdo->prepare("INSERT INTO pending_alerts (
    id, type, same_array, ugc_array, json, event, headline, description
) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $testAlert['id'],
    $testAlert['type'],
    $testAlert['same_array'],
    $testAlert['ugc_array'],
    $testAlert['json'],
    $testAlert['event'],
    $testAlert['headline'],
    $testAlert['description'],
]);

echo "Alert inserted into pending_alerts\n";

// Process pending
echo "Processing pending alerts...\n";
$processor->processPending();

// Check sent_alerts
$stmt = $pdo->prepare("SELECT * FROM sent_alerts WHERE id = ?");
$stmt->execute([$testAlert['id']]);
$sentAlert = $stmt->fetch(PDO::FETCH_ASSOC);

if ($sentAlert) {
    echo "Sent alert found:\n";
    print_r($sentAlert);
} else {
    echo "No sent alert found\n";
}

// Clean up
$pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM users WHERE Email LIKE 'test-debug-%'");
$pdo->exec("DELETE FROM zones WHERE STATE = 'DBG'");
