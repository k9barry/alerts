<?php

namespace Alerts;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Database Handler for Weather Alerts
 * 
 * Manages SQLite database connections and operations for storing
 * weather alerts from the weather.gov API.
 */
class Database
{
    /**
     * PDO connection instance
     * 
     * @var PDO|null
     */
    private ?PDO $pdo = null;
    
    /**
     * Logger instance
     * 
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    
    /**
     * Database file path
     * 
     * @var string
     */
    private string $dbPath;
    
    /**
     * Constructor
     *
     * @param string $dbPath Path to SQLite database file
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(string $dbPath, LoggerInterface $logger)
    {
        $this->dbPath = $dbPath;
        $this->logger = $logger;
    }
    
    /**
     * Get PDO connection, creating it if necessary
     *
     * @return PDO Database connection
     * @throws PDOException If connection fails
     */
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            try {
                // Ensure directory exists
                $dir = dirname($this->dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    $this->logger->info("Created database directory: $dir");
                }
                
                $this->pdo = new PDO(
                    "sqlite:{$this->dbPath}",
                    null,
                    null,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
                
                // Enable Write-Ahead Logging for better concurrency
                $this->pdo->exec('PRAGMA journal_mode=WAL');
                
                // Enable foreign keys
                $this->pdo->exec('PRAGMA foreign_keys=ON');
                
                $this->logger->info("Database connection established: {$this->dbPath}");
            } catch (PDOException $e) {
                $this->logger->error("Database connection failed: " . $e->getMessage());
                throw $e;
            }
        }
        
        return $this->pdo;
    }
    
    /**
     * Initialize database schema
     * 
     * Creates the necessary tables if they don't exist.
     * Schema based on weather.gov API alert structure.
     *
     * @return void
     * @throws PDOException If schema creation fails
     */
    public function initializeSchema(): void
    {
        $pdo = $this->getConnection();
        
        try {
            // Main alerts table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS alerts (
                    id TEXT PRIMARY KEY,
                    type TEXT,
                    geometry_type TEXT,
                    area_desc TEXT,
                    sent DATETIME,
                    effective DATETIME,
                    onset DATETIME,
                    expires DATETIME,
                    ends DATETIME,
                    status TEXT,
                    message_type TEXT,
                    category TEXT,
                    severity TEXT,
                    certainty TEXT,
                    urgency TEXT,
                    event TEXT,
                    sender TEXT,
                    sender_name TEXT,
                    headline TEXT,
                    description TEXT,
                    instruction TEXT,
                    response TEXT,
                    parameters TEXT,
                    ugc TEXT,
                    same TEXT,
                    raw_json TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Index for common queries
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_event ON alerts(event)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_severity ON alerts(severity)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_expires ON alerts(expires)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_sent ON alerts(sent)");
            
            // Table for storing affected zones
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS alert_zones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    alert_id TEXT NOT NULL,
                    zone_id TEXT NOT NULL,
                    FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
                    UNIQUE(alert_id, zone_id)
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_alert_id ON alert_zones(alert_id)");
            
            // Table for tracking API calls (rate limiting)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS api_calls (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    called_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    success INTEGER DEFAULT 1,
                    alert_count INTEGER DEFAULT 0,
                    error_message TEXT
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_calls_time ON api_calls(called_at)");
            
            // Archive table for expired alerts
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS alerts_archive (
                    id TEXT PRIMARY KEY,
                    type TEXT,
                    geometry_type TEXT,
                    area_desc TEXT,
                    sent DATETIME,
                    effective DATETIME,
                    onset DATETIME,
                    expires DATETIME,
                    ends DATETIME,
                    status TEXT,
                    message_type TEXT,
                    category TEXT,
                    severity TEXT,
                    certainty TEXT,
                    urgency TEXT,
                    event TEXT,
                    sender TEXT,
                    sender_name TEXT,
                    headline TEXT,
                    description TEXT,
                    instruction TEXT,
                    response TEXT,
                    parameters TEXT,
                    ugc TEXT,
                    same TEXT,
                    raw_json TEXT,
                    created_at DATETIME,
                    updated_at DATETIME,
                    archived_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_archived_at ON alerts_archive(archived_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_expires ON alerts_archive(expires)");
            
            $this->logger->info("Database schema initialized successfully");
        } catch (PDOException $e) {
            $this->logger->error("Schema initialization failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Insert or update an alert record
     *
     * @param array $alert Alert data from API
     * @return bool True if successful
     */
    public function upsertAlert(array $alert): bool
    {
        try {
            $pdo = $this->getConnection();
            
            $properties = $alert['properties'] ?? [];
            $geometry = $alert['geometry'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO alerts (
                    id, type, geometry_type, area_desc, sent, effective, onset, expires, ends,
                    status, message_type, category, severity, certainty, urgency, event,
                    sender, sender_name, headline, description, instruction, response,
                    parameters, ugc, same, raw_json, updated_at
                ) VALUES (
                    :id, :type, :geometry_type, :area_desc, :sent, :effective, :onset, :expires, :ends,
                    :status, :message_type, :category, :severity, :certainty, :urgency, :event,
                    :sender, :sender_name, :headline, :description, :instruction, :response,
                    :parameters, :ugc, :same, :raw_json, CURRENT_TIMESTAMP
                )
            ");
            
            // Extract UGC and SAME from parameters if they exist
            $parameters = $properties['parameters'] ?? [];
            $ugc = isset($parameters['UGC']) ? json_encode($parameters['UGC']) : null;
            $same = isset($parameters['SAME']) ? json_encode($parameters['SAME']) : null;
            
            $stmt->execute([
                ':id' => $alert['id'] ?? null,
                ':type' => $alert['type'] ?? null,
                ':geometry_type' => $geometry['type'] ?? null,
                ':area_desc' => $properties['areaDesc'] ?? null,
                ':sent' => $properties['sent'] ?? null,
                ':effective' => $properties['effective'] ?? null,
                ':onset' => $properties['onset'] ?? null,
                ':expires' => $properties['expires'] ?? null,
                ':ends' => $properties['ends'] ?? null,
                ':status' => $properties['status'] ?? null,
                ':message_type' => $properties['messageType'] ?? null,
                ':category' => $properties['category'] ?? null,
                ':severity' => $properties['severity'] ?? null,
                ':certainty' => $properties['certainty'] ?? null,
                ':urgency' => $properties['urgency'] ?? null,
                ':event' => $properties['event'] ?? null,
                ':sender' => $properties['sender'] ?? null,
                ':sender_name' => $properties['senderName'] ?? null,
                ':headline' => $properties['headline'] ?? null,
                ':description' => $properties['description'] ?? null,
                ':instruction' => $properties['instruction'] ?? null,
                ':response' => $properties['response'] ?? null,
                ':parameters' => json_encode($parameters),
                ':ugc' => $ugc,
                ':same' => $same,
                ':raw_json' => json_encode($alert),
            ]);
            
            // Insert affected zones
            if (!empty($properties['affectedZones'])) {
                $this->insertAlertZones($alert['id'], $properties['affectedZones']);
            }
            
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Failed to insert alert: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Insert alert zone associations
     *
     * @param string $alertId Alert ID
     * @param array $zones Array of zone URLs
     * @return void
     */
    private function insertAlertZones(string $alertId, array $zones): void
    {
        $pdo = $this->getConnection();
        
        foreach ($zones as $zoneUrl) {
            // Extract zone ID from URL
            $zoneId = basename($zoneUrl);
            
            try {
                $stmt = $pdo->prepare("
                    INSERT OR IGNORE INTO alert_zones (alert_id, zone_id)
                    VALUES (:alert_id, :zone_id)
                ");
                
                $stmt->execute([
                    ':alert_id' => $alertId,
                    ':zone_id' => $zoneId,
                ]);
            } catch (PDOException $e) {
                $this->logger->warning("Failed to insert zone $zoneId: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Record an API call for rate limiting tracking
     *
     * @param bool $success Whether the call was successful
     * @param int $alertCount Number of alerts received
     * @param string|null $errorMessage Error message if failed
     * @return void
     */
    public function recordApiCall(bool $success, int $alertCount = 0, ?string $errorMessage = null): void
    {
        try {
            $pdo = $this->getConnection();
            
            $stmt = $pdo->prepare("
                INSERT INTO api_calls (success, alert_count, error_message)
                VALUES (:success, :alert_count, :error_message)
            ");
            
            $stmt->execute([
                ':success' => $success ? 1 : 0,
                ':alert_count' => $alertCount,
                ':error_message' => $errorMessage,
            ]);
        } catch (PDOException $e) {
            $this->logger->warning("Failed to record API call: " . $e->getMessage());
        }
    }
    
    /**
     * Get the number of API calls made in the last period
     *
     * @param int $seconds Time period in seconds
     * @return int Number of calls made
     */
    public function getRecentApiCallCount(int $seconds): int
    {
        try {
            $pdo = $this->getConnection();
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM api_calls
                WHERE called_at > datetime('now', '-' || :seconds || ' seconds')
            ");
            
            $stmt->execute([':seconds' => $seconds]);
            $result = $stmt->fetch();
            
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            $this->logger->error("Failed to get API call count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Clean up old API call records (keep last 24 hours)
     *
     * @return void
     */
    public function cleanupOldApiCalls(): void
    {
        try {
            $pdo = $this->getConnection();
            
            $pdo->exec("
                DELETE FROM api_calls
                WHERE called_at < datetime('now', '-24 hours')
            ");
            
            $this->logger->debug("Cleaned up old API call records");
        } catch (PDOException $e) {
            $this->logger->warning("Failed to cleanup API calls: " . $e->getMessage());
        }
    }
    
    /**
     * Archive expired alerts
     * 
     * Moves alerts that have expired to the archive table
     *
     * @return int Number of alerts archived
     */
    public function archiveExpiredAlerts(): int
    {
        try {
            $pdo = $this->getConnection();
            
            // Insert expired alerts into archive
            $pdo->exec("
                INSERT OR REPLACE INTO alerts_archive 
                SELECT *, CURRENT_TIMESTAMP as archived_at
                FROM alerts
                WHERE datetime(expires) < datetime('now')
            ");
            
            // Get count before deleting
            $stmt = $pdo->query("
                SELECT COUNT(*) as count FROM alerts
                WHERE datetime(expires) < datetime('now')
            ");
            $result = $stmt->fetch();
            $archivedCount = (int)($result['count'] ?? 0);
            
            // Delete archived alerts from main table
            $pdo->exec("
                DELETE FROM alerts
                WHERE datetime(expires) < datetime('now')
            ");
            
            if ($archivedCount > 0) {
                $this->logger->info("Archived $archivedCount expired alerts");
            }
            
            return $archivedCount;
        } catch (PDOException $e) {
            $this->logger->error("Failed to archive expired alerts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Remove old archived alerts
     *
     * @param int $days Number of days to keep in archive
     * @return int Number of alerts removed
     */
    public function removeOldArchivedAlerts(int $days): int
    {
        try {
            $pdo = $this->getConnection();
            
            $stmt = $pdo->prepare("
                DELETE FROM alerts_archive
                WHERE datetime(archived_at) < datetime('now', '-' || :days || ' days')
            ");
            
            $stmt->execute([':days' => $days]);
            $removedCount = $stmt->rowCount();
            
            if ($removedCount > 0) {
                $this->logger->info("Removed $removedCount old archived alerts (older than $days days)");
            }
            
            return $removedCount;
        } catch (PDOException $e) {
            $this->logger->error("Failed to remove old archived alerts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Vacuum the database to reclaim space
     *
     * @return void
     */
    public function vacuumDatabase(): void
    {
        try {
            $pdo = $this->getConnection();
            
            $this->logger->info("Starting database vacuum...");
            $pdo->exec("VACUUM");
            $this->logger->info("Database vacuum completed successfully");
        } catch (PDOException $e) {
            $this->logger->error("Failed to vacuum database: " . $e->getMessage());
        }
    }
}
