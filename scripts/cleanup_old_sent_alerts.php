#!/usr/bin/env php
<?php
/**
 * Cleanup old sent_alerts records
 * 
 * This script deletes sent_alerts records older than a specified number of days
 * (default: 30 days) and then vacuums the database to reclaim disk space.
 * 
 * The sent_alerts table grows indefinitely as a permanent audit trail. This
 * script provides periodic cleanup to manage database size while retaining
 * recent notification history.
 * 
 * Usage: php scripts/cleanup_old_sent_alerts.php [days]
 * 
 * Examples:
 *   php scripts/cleanup_old_sent_alerts.php      # Delete records older than 30 days
 *   php scripts/cleanup_old_sent_alerts.php 90   # Delete records older than 90 days
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;
use App\Logging\LoggerFactory;

$logger = LoggerFactory::get();

// Get retention days from command line argument, default to 30 days
$retentionDays = 30;
if (isset($argv[1]) && is_numeric($argv[1]) && (int)$argv[1] > 0) {
    $retentionDays = (int)$argv[1];
}

try {
    $db = Connection::get();
    
    // Get count of records to be deleted
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
    $stmt = $db->prepare("SELECT COUNT(*) FROM sent_alerts WHERE notified_at < :cutoff");
    $stmt->execute([':cutoff' => $cutoffDate]);
    $count = $stmt->fetchColumn() ?: 0;
    unset($stmt); // Close statement before VACUUM
    
    if ($count === 0) {
        $logger->info('No old sent_alerts records to cleanup', [
            'retention_days' => $retentionDays,
            'cutoff_date' => $cutoffDate
        ]);
        echo "No records older than {$retentionDays} days found. Nothing to cleanup.\n";
        exit(0);
    }
    
    $logger->info('Starting cleanup of old sent_alerts records', [
        'retention_days' => $retentionDays,
        'cutoff_date' => $cutoffDate,
        'records_to_delete' => $count
    ]);
    echo "Cleaning sent_alerts records older than {$retentionDays} days (before {$cutoffDate})...\n";
    echo "Found {$count} records to delete.\n";
    
    // Delete old records
    $db->beginTransaction();
    $stmt = $db->prepare('DELETE FROM sent_alerts WHERE notified_at < :cutoff');
    $stmt->execute([':cutoff' => $cutoffDate]);
    $deletedCount = $stmt->rowCount();
    $db->commit();
    
    $logger->info('Deleted old sent_alerts records', [
        'records_deleted' => $deletedCount,
        'retention_days' => $retentionDays
    ]);
    echo "Deleted {$deletedCount} records.\n";
    
    // Run VACUUM on entire database to reclaim space
    echo "Running VACUUM on database to reclaim space...\n";
    $db->exec('VACUUM');
    
    $logger->info('Database vacuum complete after sent_alerts cleanup');
    echo "Database vacuum complete.\n";
    echo "Cleanup successful.\n";
    
} catch (Throwable $e) {
    $logger->error('Cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
