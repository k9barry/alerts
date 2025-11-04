<?php
/**
 * Monitor scheduler health and check for errors
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

$pdo = new PDO('sqlite:' . Config::$dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Scheduler Health Check - " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 60) . "\n\n";

// Check for duplicate IDs in incoming_alerts
echo "1. Checking for duplicate IDs in incoming_alerts...\n";
$stmt = $pdo->query("
    SELECT id, COUNT(*) as count 
    FROM incoming_alerts 
    GROUP BY id 
    HAVING count > 1
");
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "   ✓ No duplicates found\n";
} else {
    echo "   ✗ Found " . count($duplicates) . " duplicate IDs:\n";
    foreach ($duplicates as $dup) {
        echo "     - ID: {$dup['id']} (count: {$dup['count']})\n";
    }
}

// Check incoming_alerts count
echo "\n2. Checking incoming_alerts count...\n";
$count = $pdo->query("SELECT COUNT(*) FROM incoming_alerts")->fetchColumn();
echo "   Total alerts: $count\n";

// Check pending_alerts count
echo "\n3. Checking pending_alerts count...\n";
$pendingCount = $pdo->query("SELECT COUNT(*) FROM pending_alerts")->fetchColumn();
echo "   Pending alerts: $pendingCount\n";

// Check sent_alerts count
echo "\n4. Checking sent_alerts count...\n";
$sentCount = $pdo->query("SELECT COUNT(*) FROM sent_alerts")->fetchColumn();
echo "   Sent alerts: $sentCount\n";

// Check active_alerts count
echo "\n5. Checking active_alerts count...\n";
$activeCount = $pdo->query("SELECT COUNT(*) FROM active_alerts")->fetchColumn();
echo "   Active alerts: $activeCount\n";

// Show last 5 incoming alerts
echo "\n6. Recent incoming alerts:\n";
$stmt = $pdo->query("
    SELECT id, event, sent, effective, expires 
    FROM incoming_alerts 
    ORDER BY sent DESC 
    LIMIT 5
");
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($recent)) {
    echo "   No alerts found\n";
} else {
    foreach ($recent as $alert) {
        echo "   - {$alert['event']} ({$alert['id']})\n";
        echo "     Sent: {$alert['sent']}, Effective: {$alert['effective']}, Expires: {$alert['expires']}\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Health check complete.\n";
