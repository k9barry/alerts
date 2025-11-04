<?php
require __DIR__ . '/src/bootstrap.php';

use App\DB\Connection;

// Test the zone name lookup functionality
$pdo = Connection::get();

// Check if we have zones in the database
$zoneCount = $pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
echo "Total zones in database: $zoneCount\n";

if ($zoneCount > 0) {
    // Get a few sample zones
    $stmt = $pdo->query("SELECT STATE, ZONE, NAME, STATE_ZONE FROM zones LIMIT 5");
    $sampleZones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nSample zones:\n";
    foreach ($sampleZones as $zone) {
        printf("%-8s %-8s %-30s %s\n", 
            $zone['STATE'], 
            $zone['ZONE'], 
            $zone['NAME'], 
            $zone['STATE_ZONE']
        );
    }
    
// Test the zone name lookup functionality with actual zone codes
    $alertIds = array_column($sampleZones, 'STATE_ZONE');
    echo "\nTesting zone name lookup with codes: " . implode(', ', $alertIds) . "\n";
    
    $placeholders = str_repeat('?,', count($alertIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT DISTINCT NAME FROM zones WHERE STATE_ZONE IN ($placeholders) OR ZONE IN ($placeholders)");
    $stmt->execute(array_merge($alertIds, $alertIds));
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found zone names: " . implode(', ', $names) . "\n";
} else {
    echo "No zones found in database.\n";
}

// Test user table structure
echo "\nChecking if we have any users:\n";
$userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo "Total users: $userCount\n";

if ($userCount > 0) {
    $stmt = $pdo->query("SELECT FirstName, LastName, NtfyTopic FROM users LIMIT 3");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        $topic = $user['NtfyTopic'] ?: '(not set)';
        echo "- {$user['FirstName']} {$user['LastName']}: NtfyTopic = $topic\n";
    }
}