<?php
/**
 * Cleanup script to remove duplicate alert records
 * Run this script to fix any existing duplicate records in the database
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Logging\LoggerFactory;

$pdo = new PDO('sqlite:' . Config::$dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$logger = LoggerFactory::get();

echo "Checking for duplicate alert IDs...\n\n";

// Check each table for duplicates
$tables = ['incoming_alerts', 'active_alerts', 'pending_alerts', 'sent_alerts'];

foreach ($tables as $table) {
    echo "Checking $table...\n";
    
    // Find duplicates
    $stmt = $pdo->query("
        SELECT id, COUNT(*) as count 
        FROM $table 
        GROUP BY id 
        HAVING count > 1
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($duplicates)) {
        echo "  ✓ No duplicates found in $table\n\n";
        continue;
    }
    
    echo "  ✗ Found " . count($duplicates) . " duplicate IDs in $table\n";
    
    $pdo->beginTransaction();
    try {
        foreach ($duplicates as $dup) {
            $id = $dup['id'];
            $count = $dup['count'];
            
            echo "    - ID '$id' has $count copies, keeping the first one...\n";
            
            // Keep only the first record (lowest rowid)
            $pdo->exec("
                DELETE FROM $table 
                WHERE id = " . $pdo->quote($id) . " 
                AND rowid NOT IN (
                    SELECT MIN(rowid) 
                    FROM $table 
                    WHERE id = " . $pdo->quote($id) . "
                )
            ");
        }
        
        $pdo->commit();
        echo "  ✓ Cleaned up $table\n\n";
        
        $logger->info("Cleaned up duplicate records", [
            'table' => $table,
            'duplicate_ids' => count($duplicates)
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ✗ Error cleaning $table: " . $e->getMessage() . "\n\n";
        $logger->error("Failed to clean up duplicates", [
            'table' => $table,
            'error' => $e->getMessage()
        ]);
    }
}

// Verify no duplicates remain
echo "\nVerifying cleanup...\n";
$allClean = true;

foreach ($tables as $table) {
    $stmt = $pdo->query("
        SELECT COUNT(*) as dup_count 
        FROM (
            SELECT id, COUNT(*) as count 
            FROM $table 
            GROUP BY id 
            HAVING count > 1
        )
    ");
    $dupCount = $stmt->fetchColumn();
    
    if ($dupCount > 0) {
        echo "  ✗ $table still has $dupCount duplicate IDs\n";
        $allClean = false;
    } else {
        echo "  ✓ $table is clean\n";
    }
}

if ($allClean) {
    echo "\n✓ All tables are clean - no duplicate IDs found!\n";
} else {
    echo "\n✗ Some duplicates remain - manual intervention may be needed\n";
}

echo "\nDone.\n";
