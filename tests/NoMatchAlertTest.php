<?php
/**
 * Test to verify that alerts with no matching users are NOT saved to sent_alerts table.
 * 
 * This test ensures that only alerts that have matching results and get sent are recorded
 * in the sent_alerts table, not alerts that have no matching user zones.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Service\AlertProcessor;
use App\Repository\AlertsRepository;
use App\DB\Connection;

/**
 * Test class for verifying no-match alerts are not saved
 */
class NoMatchAlertTest extends TestCase
{
    /**
     * @var PDO Database connection
     */
    private $pdo;
    
    /**
     * @var AlertsRepository Repository instance
     */
    private $repo;
    
    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Connection::get();
        $this->repo = new AlertsRepository();
        
        // Run migrations to create tables
        $this->runMigrations();
        
        // Clean up test data
        $this->pdo->exec("DELETE FROM incoming_alerts");
        $this->pdo->exec("DELETE FROM active_alerts");
        $this->pdo->exec("DELETE FROM pending_alerts");
        $this->pdo->exec("DELETE FROM sent_alerts");
        $this->pdo->exec("DELETE FROM users WHERE Email LIKE '%test-no-match%'");
    }
    
    /**
     * Run database migrations to create tables
     *
     * @return void
     */
    private function runMigrations(): void
    {
        // Unified alert schema columns matching weather.gov properties
        $alertColumns = [
            "id TEXT PRIMARY KEY",
            "type TEXT",
            "status TEXT",
            "msg_type TEXT",
            "category TEXT",
            "severity TEXT",
            "certainty TEXT",
            "urgency TEXT",
            "event TEXT",
            "headline TEXT",
            "description TEXT",
            "instruction TEXT",
            "area_desc TEXT",
            "sent TEXT",
            "effective TEXT",
            "onset TEXT",
            "expires TEXT",
            "ends TEXT",
            "same_array TEXT NOT NULL",
            "ugc_array TEXT NOT NULL",
            "json TEXT NOT NULL"
        ];
        
        $tablesToEnsure = [
            'incoming_alerts' => 'received_at TEXT DEFAULT CURRENT_TIMESTAMP',
            'active_alerts' => 'updated_at TEXT DEFAULT CURRENT_TIMESTAMP',
            'pending_alerts' => 'created_at TEXT DEFAULT CURRENT_TIMESTAMP',
            'sent_alerts' => 'notified_at TEXT, result_status TEXT, result_attempts INTEGER NOT NULL DEFAULT 0, result_error TEXT, pushover_request_id TEXT, user_id INTEGER'
        ];
        
        foreach ($tablesToEnsure as $table => $extra) {
            $all = implode(",\n  ", array_merge($alertColumns, array_filter([$extra])));
            $sql = "CREATE TABLE IF NOT EXISTS {$table} (\n  {$all}\n);";
            $this->pdo->exec($sql);
        }
        
        // Create users table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            idx INTEGER PRIMARY KEY AUTOINCREMENT,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Email TEXT NOT NULL UNIQUE,
            Timezone TEXT DEFAULT 'America/New_York',
            PushoverUser TEXT,
            PushoverToken TEXT,
            NtfyUser TEXT,
            NtfyPassword TEXT,
            NtfyToken TEXT,
            NtfyTopic TEXT,
            ZoneAlert TEXT DEFAULT '[]',
            CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Create zones table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS zones (
            idx INTEGER PRIMARY KEY AUTOINCREMENT,
            STATE TEXT NOT NULL,
            ZONE TEXT NOT NULL,
            CWA TEXT,
            NAME TEXT NOT NULL,
            STATE_ZONE TEXT,
            COUNTY TEXT,
            FIPS TEXT,
            TIME_ZONE TEXT,
            FE_AREA TEXT,
            LAT REAL,
            LON REAL,
            UNIQUE(STATE, ZONE)
        )");
    }
    
    /**
     * Test that alerts with no matching users are NOT saved to sent_alerts table
     *
     * @return void
     */
    public function testAlertWithNoMatchingUsersNotSaved(): void
    {
        // Create a test alert with a zone that no user has configured
        $testAlert = [
            'id' => 'test-no-match-alert-' . uniqid(),
            'type' => 'Feature',
            'same_array' => ['999999'], // FIPS code that no user will have
            'ugc_array' => ['NOMATCH001'], // Zone code that no user will have
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Severe',
                'certainty' => 'Likely',
                'urgency' => 'Immediate',
                'event' => 'Test Alert No Match',
                'headline' => 'Test Alert with no matching users',
                'description' => 'This alert should not be saved to sent_alerts',
                'instruction' => 'No instruction',
                'areaDesc' => 'No Match County',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+2 hours')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+2 hours')),
            ]
        ];
        
        // Create a test user with a different zone
        $stmt = $this->pdo->prepare("INSERT INTO users (FirstName, LastName, Email, ZoneAlert, PushoverUser, PushoverToken) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Test',
            'User',
            'test-no-match-' . uniqid() . '@example.com',
            json_encode(['DIFFERENT001', '123456']), // Different zone than the alert
            'test-user-key',
            'test-token'
        ]);
        
        // Store the alert in incoming_alerts
        $this->repo->replaceIncoming([$testAlert]);
        
        // Queue the alert as pending
        $processor = new AlertProcessor();
        $queued = $processor->diffAndQueue();
        $this->assertGreaterThanOrEqual(1, $queued, 'Alert should be queued');
        
        // Process pending alerts (this will attempt to match users)
        $processor->processPending();
        
        // Verify the alert was NOT saved to sent_alerts table
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sent_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $count = (int)$stmt->fetchColumn();
        
        $this->assertEquals(0, $count, 'Alert with no matching users should NOT be saved to sent_alerts table');
        
        // Also verify it was removed from pending_alerts
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pending_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $pendingCount = (int)$stmt->fetchColumn();
        
        $this->assertEquals(0, $pendingCount, 'Alert should be removed from pending_alerts after processing');
    }
    
    /**
     * Test that alerts with matching users ARE saved to sent_alerts table
     *
     * @return void
     */
    public function testAlertWithMatchingUsersSaved(): void
    {
        // Create a test alert with a specific zone
        $testZone = 'MATCH001';
        $testFips = '888888';
        $testAlert = [
            'id' => 'test-match-alert-' . uniqid(),
            'type' => 'Feature',
            'same_array' => [$testFips],
            'ugc_array' => [$testZone],
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Severe',
                'certainty' => 'Likely',
                'urgency' => 'Immediate',
                'event' => 'Test Alert With Match',
                'headline' => 'Test Alert with matching user',
                'description' => 'This alert should be saved to sent_alerts',
                'instruction' => 'Take action',
                'areaDesc' => 'Match County',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+2 hours')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+2 hours')),
            ]
        ];
        
        // Create a test user with the SAME zone as the alert
        $stmt = $this->pdo->prepare("INSERT INTO users (FirstName, LastName, Email, ZoneAlert, PushoverUser, PushoverToken) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Test',
            'UserMatch',
            'test-match-' . uniqid() . '@example.com',
            json_encode([$testZone, $testFips]), // Same zone as the alert
            'test-user-key',
            'test-token'
        ]);
        
        // Store the alert in incoming_alerts
        $this->repo->replaceIncoming([$testAlert]);
        
        // Queue the alert as pending
        $processor = new AlertProcessor();
        $queued = $processor->diffAndQueue();
        $this->assertGreaterThanOrEqual(1, $queued, 'Alert should be queued');
        
        // Process pending alerts (this will match the user and send)
        $processor->processPending();
        
        // Verify the alert WAS saved to sent_alerts table
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sent_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $count = (int)$stmt->fetchColumn();
        
        $this->assertGreaterThanOrEqual(1, $count, 'Alert with matching users SHOULD be saved to sent_alerts table');
        
        // Verify the status is 'processed' not 'no_match'
        $stmt = $this->pdo->prepare("SELECT result_status FROM sent_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $status = $stmt->fetchColumn();
        
        $this->assertEquals('processed', $status, 'Alert with matching users should have status "processed"');
    }
    
    /**
     * Clean up test environment after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->exec("DELETE FROM incoming_alerts WHERE id LIKE 'test-no-match%' OR id LIKE 'test-match%'");
        $this->pdo->exec("DELETE FROM active_alerts WHERE id LIKE 'test-no-match%' OR id LIKE 'test-match%'");
        $this->pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-no-match%' OR id LIKE 'test-match%'");
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-no-match%' OR id LIKE 'test-match%'");
        $this->pdo->exec("DELETE FROM users WHERE Email LIKE '%test-no-match%' OR Email LIKE '%test-match%'");
        
        parent::tearDown();
    }
}
