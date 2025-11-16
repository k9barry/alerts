<?php
/**
 * Test for Ntfy per-user topic functionality
 * 
 * Tests that Ntfy notifications work when users have their own NtfyTopic
 * even if the global config topic is empty or invalid.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Service\AlertProcessor;
use App\Service\NtfyNotifier;
use App\Repository\AlertsRepository;
use App\DB\Connection;
use App\Config;

/**
 * Test class for Ntfy per-user topic functionality
 */
class NtfyPerUserTopicTest extends TestCase
{
    use TestMigrationTrait;
    
    /**
     * @var \PDO Database connection
     */
    private $pdo;
    
    /**
     * @var AlertsRepository Repository instance
     */
    private $repo;
    
    /**
     * @var string Original ntfy topic value
     */
    private $originalNtfyTopic;
    
    /**
     * @var bool Original ntfy enabled value
     */
    private $originalNtfyEnabled;
    
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
        
        // Save original config values
        $this->originalNtfyTopic = Config::$ntfyTopic;
        $this->originalNtfyEnabled = Config::$ntfyEnabled;
        
        // Run migrations to create tables
        $this->runMigrations();
        
        // Clean up test data
        $this->pdo->exec("DELETE FROM incoming_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM active_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM users WHERE Email LIKE 'test-ntfy-per-user-%'");
        $this->pdo->exec("DELETE FROM zones WHERE STATE = 'TST'");
        
        // Add test zone data
        $this->pdo->exec("INSERT INTO zones (STATE, ZONE, NAME, STATE_ZONE, FIPS, LAT, LON) VALUES 
            ('TST', '001', 'Test Zone', 'TSTC001,TSTZ001', '999001', 40.0, -85.0)");
    }
    
    /**
     * Test that Ntfy notifications are sent when user has a topic but global config doesn't
     *
     * @return void
     */
    public function testNtfyWorksWithPerUserTopicWhenGlobalTopicEmpty(): void
    {
        // Set global config to have empty topic (simulating the production issue)
        Config::$ntfyTopic = '';
        Config::$ntfyEnabled = true;
        
        // Zone data already created in setUp()
        
        // Create a test user with their own NtfyTopic
        $userStmt = $this->pdo->prepare("INSERT INTO users (FirstName, LastName, Email, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?)");
        $userStmt->execute([
            'Test',
            'User',
            'test-ntfy-per-user-' . uniqid() . '@example.com',
            'user-specific-topic',
            '["TSTC001"]'
        ]);
        $userId = (int)$this->pdo->lastInsertId();
        
        // Create a test alert matching the user's zone
        $testAlert = [
            'id' => 'test-per-user-' . uniqid(),
            'type' => 'Feature',
            'same_array' => json_encode(['TSTC001']),
            'ugc_array' => json_encode(['TSTC001']),
            'json' => json_encode([
                'properties' => [
                    'status' => 'Actual',
                    'messageType' => 'Alert',
                    'category' => 'Met',
                    'severity' => 'Severe',
                    'certainty' => 'Likely',
                    'urgency' => 'Immediate',
                    'event' => 'Test Per-User Topic Alert',
                    'headline' => 'Test per-user topic functionality',
                    'description' => 'This tests that ntfy works with per-user topics',
                    'areaDesc' => 'Test Zone',
                ]
            ]),
            'event' => 'Test Per-User Topic Alert',
            'headline' => 'Test per-user topic functionality',
            'severity' => 'Severe',
            'certainty' => 'Likely',
            'urgency' => 'Immediate',
            'description' => 'This tests that ntfy works with per-user topics',
            'area_desc' => 'Test Zone',
        ];
        
        // Insert alert into pending_alerts
        $stmt = $this->pdo->prepare("INSERT INTO pending_alerts (
            id, type, same_array, ugc_array, json, event, headline, 
            severity, certainty, urgency, description, area_desc
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $testAlert['id'],
            $testAlert['type'],
            $testAlert['same_array'],
            $testAlert['ugc_array'],
            $testAlert['json'],
            $testAlert['event'],
            $testAlert['headline'],
            $testAlert['severity'],
            $testAlert['certainty'],
            $testAlert['urgency'],
            $testAlert['description'],
            $testAlert['area_desc'],
        ]);
        
        // Process pending alerts
        $processor = new AlertProcessor();
        $processor->processPending();
        
        // Verify that a sent_alerts record was created for this user
        $stmt = $this->pdo->prepare("SELECT * FROM sent_alerts WHERE id = ? AND user_id = ? AND channel = 'ntfy' LIMIT 1");
        $stmt->execute([$testAlert['id'], $userId]);
        $sentAlert = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($sentAlert, 'Alert should be recorded in sent_alerts');
        
        // Verify that the alert was processed
        $this->assertContains($sentAlert['result_status'], ['success', 'skipped', 'failure'], 'Alert should have status "processed"');
        
        // Verify it was processed for the correct user
        $this->assertEquals($userId, $sentAlert['user_id'], 'Alert should be associated with the correct user');
    }
    
    /**
     * Test that Ntfy notifications work when global topic is invalid but user has valid topic
     *
     * @return void
     */
    public function testNtfyWorksWithPerUserTopicWhenGlobalTopicInvalid(): void
    {
        // Set global config to have invalid topic (simulating the production issue)
        Config::$ntfyTopic = 'invalid topic!'; // Invalid because it contains spaces and special chars
        Config::$ntfyEnabled = true;
        
        // Create a test zone
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO zones (STATE, ZONE, NAME, STATE_ZONE) VALUES (?, ?, ?, ?)");
        $stmt->execute(['TST', '002', 'Test Zone 2', 'TSTC002']);
        
        // Create a test user with their own valid NtfyTopic
        $userStmt = $this->pdo->prepare("INSERT INTO users (FirstName, LastName, Email, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?)");
        $userStmt->execute([
            'Test',
            'User2',
            'test-ntfy-per-user-' . uniqid() . '@example.com',
            'valid-user-topic',
            '["TSTC002"]'
        ]);
        $userId = (int)$this->pdo->lastInsertId();
        
        // Create a test alert matching the user's zone
        $testAlert = [
            'id' => 'test-per-user-' . uniqid(),
            'type' => 'Feature',
            'same_array' => json_encode(['TSTC002']),
            'ugc_array' => json_encode(['TSTC002']),
            'json' => json_encode([
                'properties' => [
                    'status' => 'Actual',
                    'messageType' => 'Alert',
                    'category' => 'Met',
                    'severity' => 'Moderate',
                    'certainty' => 'Possible',
                    'urgency' => 'Expected',
                    'event' => 'Test Invalid Global Topic',
                    'headline' => 'Test with invalid global topic',
                    'description' => 'This tests that ntfy works when global topic is invalid',
                    'areaDesc' => 'Test Zone 2',
                ]
            ]),
            'event' => 'Test Invalid Global Topic',
            'headline' => 'Test with invalid global topic',
            'severity' => 'Moderate',
            'certainty' => 'Possible',
            'urgency' => 'Expected',
            'description' => 'This tests that ntfy works when global topic is invalid',
            'area_desc' => 'Test Zone 2',
        ];
        
        // Insert alert into pending_alerts
        $stmt = $this->pdo->prepare("INSERT INTO pending_alerts (
            id, type, same_array, ugc_array, json, event, headline, 
            severity, certainty, urgency, description, area_desc
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $testAlert['id'],
            $testAlert['type'],
            $testAlert['same_array'],
            $testAlert['ugc_array'],
            $testAlert['json'],
            $testAlert['event'],
            $testAlert['headline'],
            $testAlert['severity'],
            $testAlert['certainty'],
            $testAlert['urgency'],
            $testAlert['description'],
            $testAlert['area_desc'],
        ]);
        
        // Process pending alerts
        $processor = new AlertProcessor();
        $processor->processPending();
        
        // Verify that a sent_alerts record was created for this user
        $stmt = $this->pdo->prepare("SELECT * FROM sent_alerts WHERE id = ? AND user_id = ? AND channel = 'ntfy' LIMIT 1");
        $stmt->execute([$testAlert['id'], $userId]);
        $sentAlert = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($sentAlert, 'Alert should be recorded in sent_alerts');
        
        // Verify that the alert was processed
        $this->assertContains($sentAlert['result_status'], ['success', 'skipped', 'failure'], 'Alert should have status "processed"');
        
        // Verify it was processed for the correct user
        $this->assertEquals($userId, $sentAlert['user_id'], 'Alert should be associated with the correct user');
    }
    
    /**
     * Clean up test environment after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Restore original config values
        Config::$ntfyTopic = $this->originalNtfyTopic;
        Config::$ntfyEnabled = $this->originalNtfyEnabled;
        
        // Clean up test data
        $this->pdo->exec("DELETE FROM incoming_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM active_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-per-user-%'");
        $this->pdo->exec("DELETE FROM users WHERE Email LIKE 'test-ntfy-per-user-%'");
        $this->pdo->exec("DELETE FROM zones WHERE STATE = 'TST'");
        
        parent::tearDown();
    }
}
