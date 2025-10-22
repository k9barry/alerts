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

$logger->info("Starting alerts application v{$config['app']['version']}");
$logger->info("Environment: {$config['app']['env']}");

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
    
    // Fetch active alerts
    $logger->info("Fetching active alerts from API");
    $alerts = $apiClient->fetchAlerts(['status' => 'actual']);
    
    if ($alerts === null) {
        $logger->error("Failed to fetch alerts");
        exit(1);
    }
    
    $logger->info("Processing " . count($alerts) . " alerts");
    
    // Process and store each alert
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
    
    // Cleanup old API call records
    $database->cleanupOldApiCalls();
    
    $logger->info("Application completed successfully");
    
} catch (\Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
