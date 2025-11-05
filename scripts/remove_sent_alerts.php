#!/usr/bin/env php
<?php
/**
 * Remove all sent_alerts and vacuum database
 * 
 * This script deletes ALL records from the sent_alerts table and then
 * vacuums the entire database to reclaim disk space.
 * 
 * Usage: php scripts/remove_sent_alerts.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;
use App\Logging\LoggerFactory;

$logger = LoggerFactory::get();

try {
    $db = Connection::get();
    
    // Get table size before deletion
    $stmt = $db->query("SELECT COUNT(*) FROM sent_alerts");
    $count = $stmt->fetchColumn() ?: 0;
    unset($stmt); // Close statement before VACUUM
    
    $logger->info('Starting cleanup of sent_alerts table', ['record_count' => $count]);
    echo "Cleaning sent_alerts table (currently {$count} records)...\n";
    
    // Delete ALL records from sent_alerts table
    $db->beginTransaction();
    $db->exec('DELETE FROM sent_alerts');
    $db->commit();
    
    $logger->info('Deleted all records from sent_alerts', ['records_deleted' => $count]);
    echo "Deleted all {$count} records from sent_alerts table.\n";
    
    // Run VACUUM on entire database to reclaim space
    echo "Running VACUUM on entire database...\n";
    $db->exec('VACUUM');
    
    $logger->info('Database vacuum complete');
    echo "Database vacuum complete.\n";
    
} catch (Throwable $e) {
    $logger->error('Vacuum failed', ['error' => $e->getMessage()]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
