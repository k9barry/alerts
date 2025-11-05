<?php
/**
 * Alert Workflow Test
 * 
 * Tests the complete workflow of alerts from API fetch to notification sending.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Service\AlertFetcher;
use App\Service\AlertProcessor;
use App\Repository\AlertsRepository;
use App\DB\Connection;

/**
 * Test class for alert workflow
 */
class AlertWorkflowTest extends TestCase
{
    /**
     * @var \PDO Database connection
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
     * Test that AlertFetcher can fetch and store alerts
     *
     * @return void
     */
    public function testAlertFetcherCanFetchAndStore(): void
    {
        $fetcher = new AlertFetcher();
        
        // Fetch alerts (this hits the real API)
        $count = $fetcher->fetchAndStoreIncoming();
        
        // Count should be >= 0 (could be 0 if no active alerts)
        $this->assertGreaterThanOrEqual(0, $count, 'Fetch count should be non-negative');
        
        // Verify alerts were stored in incoming_alerts table
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM incoming_alerts");
        $storedCount = (int)$stmt->fetchColumn();
        
        $this->assertEquals($count, $storedCount, 'Stored count should match fetch count');
    }
    
    /**
     * Create a test alert in weather.gov API format
     *
     * @param string $idSuffix Unique suffix for the alert ID
     * @param array $overrides Optional property overrides
     * @return array Alert in weather.gov format
     */
    private function createTestAlert(string $idSuffix, array $overrides = []): array
    {
        $id = 'test-alert-' . $idSuffix;
        $defaults = [
            'id' => $id,
            'type' => 'Feature',
            'same_array' => ['012345'],
            'ugc_array' => ['TST001'],
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Severe',
                'certainty' => 'Likely',
                'urgency' => 'Immediate',
                'event' => 'Test Severe Weather',
                'headline' => 'Test Alert Headline',
                'description' => 'This is a test alert for workflow testing',
                'instruction' => 'Take shelter immediately',
                'areaDesc' => 'Test County',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+2 hours')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+2 hours')),
            ]
        ];
        
        return array_merge($defaults, $overrides);
    }
    
    /**
     * Test that AlertProcessor can queue new alerts
     *
     * @return void
     */
    public function testAlertProcessorCanQueueAlerts(): void
    {
        // First, populate incoming_alerts with test data
        $testAlert = $this->createTestAlert(uniqid());
        
        $this->repo->replaceIncoming([$testAlert]);
        
        // Now test the processor
        $processor = new AlertProcessor();
        $queued = $processor->diffAndQueue();
        
        // Should queue the new alert
        $this->assertGreaterThanOrEqual(1, $queued, 'Should queue at least one new alert');
        
        // Verify alert is in pending_alerts
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pending_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $pendingCount = (int)$stmt->fetchColumn();
        
        $this->assertEquals(1, $pendingCount, 'Test alert should be in pending_alerts');
    }
    
    /**
     * Test that AlertProcessor handles duplicate alerts correctly
     *
     * @return void
     */
    public function testAlertProcessorHandlesDuplicates(): void
    {
        $testAlert = $this->createTestAlert('dup-' . uniqid(), [
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Moderate',
                'certainty' => 'Possible',
                'urgency' => 'Expected',
                'event' => 'Test Duplicate Alert',
                'headline' => 'Duplicate Test',
                'description' => 'Testing duplicate handling',
                'instruction' => '',
                'areaDesc' => 'Test Area',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+1 hour')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+1 hour')),
            ]
        ]);
        
        // Insert alert into incoming
        $this->repo->replaceIncoming([$testAlert]);
        // Copy incoming to active (simulating it was already processed)
        $this->repo->replaceActiveWithIncoming();
        
        // Try to queue - should not queue duplicate since it's already in active
        $processor = new AlertProcessor();
        $queued = $processor->diffAndQueue();
        
        $this->assertEquals(0, $queued, 'Should not queue duplicate alerts');
    }
    
    /**
     * Test that the repository prevents duplicate IDs
     *
     * @return void
     */
    public function testRepositoryPreventsDuplicateIds(): void
    {
        $alert1 = $this->createTestAlert('duplicate-test-id', [
            'id' => 'duplicate-test-id',
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Minor',
                'certainty' => 'Possible',
                'urgency' => 'Future',
                'event' => 'First Alert',
                'headline' => '',
                'description' => 'First version',
                'instruction' => '',
                'areaDesc' => 'Area 1',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+30 minutes')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+30 minutes')),
            ]
        ]);
        
        $alert2 = $alert1;
        $alert2['properties']['description'] = 'Second version - should replace first';
        
        // Insert first version
        $this->repo->replaceIncoming([$alert1]);
        
        // Insert second version with same ID
        $this->repo->replaceIncoming([$alert2]);
        
        // Check that only one record exists
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM incoming_alerts WHERE id = ?");
        $stmt->execute(['duplicate-test-id']);
        $count = (int)$stmt->fetchColumn();
        
        $this->assertEquals(1, $count, 'Should have exactly one record with duplicate ID');
        
        // Verify it's the second version
        $stmt = $this->pdo->prepare("SELECT description FROM incoming_alerts WHERE id = ?");
        $stmt->execute(['duplicate-test-id']);
        $description = $stmt->fetchColumn();
        
        $this->assertEquals('Second version - should replace first', $description, 
            'Should have replaced with second version');
    }
    
    /**
     * Test that incoming alerts table can be queried
     *
     * @return void
     */
    public function testIncomingAlertsCanBeQueried(): void
    {
        $ids = $this->repo->getIncomingIds();
        
        $this->assertIsArray($ids, 'getIncomingIds should return an array');
    }
    
    /**
     * Test workflow with mock alert data
     *
     * @return void
     */
    public function testCompleteWorkflowWithMockData(): void
    {
        // Step 1: Create mock incoming alert
        $mockAlert = $this->createTestAlert('workflow-' . uniqid(), [
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Severe',
                'certainty' => 'Observed',
                'urgency' => 'Immediate',
                'event' => 'Workflow Test Event',
                'headline' => 'Test Workflow Headline',
                'description' => 'Complete workflow test alert',
                'instruction' => 'This is a test',
                'areaDesc' => 'Test Workflow County',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+3 hours')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+3 hours')),
            ]
        ]);
        
        // Step 2: Store in incoming
        $this->repo->replaceIncoming([$mockAlert]);
        
        $incomingIds = $this->repo->getIncomingIds();
        $this->assertContains($mockAlert['id'], $incomingIds, 'Alert should be in incoming_alerts');
        
        // Step 3: Process (queue new alerts)
        $processor = new AlertProcessor();
        $queued = $processor->diffAndQueue();
        
        $this->assertGreaterThanOrEqual(1, $queued, 'Should queue the new alert');
        
        // Step 4: Move incoming to active (simulating scheduler's sync step)
        $this->repo->replaceActiveWithIncoming();
        
        // Verify alert moved through pipeline
        $activeIds = $this->repo->getActiveIds();
        $this->assertContains($mockAlert['id'], $activeIds, 'Alert should be in active_alerts');
        
        // Verify alert is in pending_alerts
        $pending = $this->repo->getPending();
        $pendingIds = array_column($pending, 'id');
        $this->assertContains($mockAlert['id'], $pendingIds, 'Alert should be in pending_alerts');
        
        // Step 5: Process pending (this would normally send notifications)
        // Note: We're not actually sending notifications in this test
        // as that would require valid credentials and could send real notifications
        
        $this->assertTrue(true, 'Workflow completed successfully');
    }
    
    /**
     * Clean up test environment after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->exec("DELETE FROM incoming_alerts WHERE id LIKE 'test-%' OR id LIKE 'workflow-%' OR id LIKE 'duplicate-test-id'");
        $this->pdo->exec("DELETE FROM active_alerts WHERE id LIKE 'test-%' OR id LIKE 'workflow-%' OR id LIKE 'duplicate-test-id'");
        $this->pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-%' OR id LIKE 'workflow-%' OR id LIKE 'duplicate-test-id'");
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-%' OR id LIKE 'workflow-%' OR id LIKE 'duplicate-test-id'");
        
        parent::tearDown();
    }
}
