<?php
require __DIR__ . '/src/bootstrap.php';

use App\DB\Connection;

$pdo = Connection::get();

echo "Looking for zones that match user's ZoneAlert values...\n";

// User has: ["in070","18093","in040","18095"]
$userValues = ["in070","18093","in040","18095"];

foreach ($userValues as $value) {
    echo "\n=== Searching for: $value ===\n";
    
    // Try different search strategies
    $stmt = $pdo->prepare("SELECT * FROM zones WHERE LOWER(STATE_ZONE) = LOWER(?) OR ZONE = ? OR FIPS = ? LIMIT 5");
    $stmt->execute([$value, $value, $value]);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($zones) . " zones:\n";
    foreach ($zones as $zone) {
        echo "  STATE: {$zone['STATE']}, ZONE: {$zone['ZONE']}, STATE_ZONE: {$zone['STATE_ZONE']}, NAME: {$zone['NAME']}\n";
        if (!empty($zone['FIPS'])) echo "  FIPS: {$zone['FIPS']}\n";
    }
}