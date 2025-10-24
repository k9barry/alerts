<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Alerts\Database;
use Alerts\ApiClient;
use Alerts\LoggerFactory;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Create logger
$logger = LoggerFactory::create(
    $config['app']['name'],
    $config['logging']['level'],
    $config['logging']['path']
);

$logger->info("Starting alerts scheduler v{$config['app']['version']}");
$logger->info("Environment: {$config['app']['env']}");
$logger->info("Fetch interval: {$config['scheduler']['fetch_interval']} seconds");
$logger->info("Vacuum interval: {$config['scheduler']['vacuum_interval_days']} days");
$logger->info("Archive retention: {$config['scheduler']['archive_retention_days']} days");

try {
    // Initialize database
    $database = new Database(
        $config['database']['path'],
        $logger
    );
    
    $database->initializeSchema();
    
    // Initialize API client
    $apiClient = new ApiClient(
        $config['api']['base_url'],
        $config['app']['name'],
        $config['app']['version'],
        $config['contact']['email'],
        $config['api']['rate_limit'],
        $config['api']['rate_period'],
        $database,
        $logger
    );
    
    // Track last maintenance times
    $lastVacuum = time();
    $lastArchive = time();
    $fetchInterval = $config['scheduler']['fetch_interval'];
    $vacuumIntervalDays = $config['scheduler']['vacuum_interval_days'];
    $archiveRetentionDays = $config['scheduler']['archive_retention_days'];
    
    $logger->info("Scheduler started. Press Ctrl+C to stop.");
    
    // Main scheduler loop
    while (true) {
        $startTime = time();
        
        try {
            // Fetch and process alerts
            $logger->info("Fetching active alerts from API");
            $alerts = $apiClient->fetchAlerts(['status' => 'actual']);
            
            if ($alerts !== null) {
                $logger->info("Processing " . count($alerts) . " alerts");
                
                $successCount = 0;
                $failCount = 0;
                
                foreach ($alerts as $alert) {
                    $alertId = $alert['id'] ?? 'unknown';
                    $logger->debug("Processing alert: $alertId");
                    
                    if ($database->upsertAlert($alert)) {
                        $successCount++;
                    } else {
                        $failCount++;
                        $logger->warning("Failed to store alert: $alertId");
                    }
                }
                
                $logger->info("Alert processing complete. Success: $successCount, Failed: $failCount");
            } else {
                $logger->error("Failed to fetch alerts");
            }
            
            // Archive expired alerts (run every fetch cycle)
            $archivedCount = $database->archiveExpiredAlerts();
            if ($archivedCount > 0) {
                $logger->info("Archived $archivedCount expired alerts");
            }
            
            // Cleanup old API call records
            $database->cleanupOldApiCalls();
            
            // Remove old archived alerts (check every fetch cycle)
            $currentTime = time();
            $daysSinceLastArchive = ($currentTime - $lastArchive) / 86400;
            
            if ($daysSinceLastArchive >= 1) {
                $removedCount = $database->removeOldArchivedAlerts($archiveRetentionDays);
                if ($removedCount > 0) {
                    $logger->info("Removed $removedCount old archived alerts");
                }
                $lastArchive = $currentTime;
            }
            
            // Vacuum database periodically
            $daysSinceLastVacuum = ($currentTime - $lastVacuum) / 86400;
            
            if ($daysSinceLastVacuum >= $vacuumIntervalDays) {
                $logger->info("Running scheduled database vacuum");
                $database->vacuumDatabase();
                $lastVacuum = $currentTime;
            }
            
        } catch (\Exception $e) {
            $logger->error("Error in scheduler loop: " . $e->getMessage());
            $logger->error("Stack trace: " . $e->getTraceAsString());
        }
        
        // Calculate sleep time to maintain consistent interval
        $elapsedTime = time() - $startTime;
        $sleepTime = max(0, $fetchInterval - $elapsedTime);
        
        if ($sleepTime > 0) {
            $logger->debug("Sleeping for $sleepTime seconds until next fetch");
            sleep($sleepTime);
        } else {
            $logger->warning("Processing took longer than fetch interval ($elapsedTime seconds)");
        }
    }
    
} catch (\Exception $e) {
    $logger->error("Fatal error in scheduler: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
