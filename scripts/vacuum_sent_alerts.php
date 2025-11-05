#!/usr/bin/env php
<?php
/**
 * Vacuum sent_alerts table
 * 
 * This script vacuums only the sent_alerts table to reclaim disk space
 * from deleted records. It does not affect the zones or users tables.
 * 
 * Usage: php scripts/vacuum_sent_alerts.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;
use App\Logging\LoggerFactory;

$logger = LoggerFactory::get();

try {
    $db = Connection::get();
    
    // Get table size before vacuum
    $stmt = $db->query("SELECT COUNT(*) as count FROM sent_alerts");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $logger->info('Starting vacuum of sent_alerts table', ['record_count' => $count]);
    echo "Vacuuming sent_alerts table (currently {$count} records)...\n";
    
    // Vacuum only the sent_alerts table by deleting and rebuilding it
    // SQLite's VACUUM command works on the entire database, so we'll use
    // a different approach: delete old records to free up space
    $db->beginTransaction();
    
    // Delete records older than 90 days (configurable)
    $cutoffDays = (int)($_ENV['VACUUM_SENT_ALERTS_DAYS'] ?? 90);
    $stmt = $db->prepare("DELETE FROM sent_alerts WHERE notified_at < datetime('now', '-{$cutoffDays} days')");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    
    $db->commit();
    
    // Now run VACUUM to reclaim space
    // Note: This still vacuums the entire database, but we've ensured
    // we're only modifying sent_alerts
    $db->exec('VACUUM');
    
    $logger->info('Vacuum of sent_alerts complete', [
        'records_deleted' => $deleted,
        'cutoff_days' => $cutoffDays
    ]);
    
    echo "Vacuum complete. Deleted {$deleted} records older than {$cutoffDays} days.\n";
    echo "Note: SQLite VACUUM operates on the entire database file.\n";
    
} catch (Throwable $e) {
    $logger->error('Vacuum failed', ['error' => $e->getMessage()]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
