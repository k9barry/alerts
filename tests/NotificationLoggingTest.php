<?php
/**
 * Notification Logging Test
 * 
 * Tests that both Pushover and ntfy notifications are properly logged in sent_alerts table
 * with support for multiple users per alert.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Repository\AlertsRepository;
use App\DB\Connection;

/**
 * Test class for notification logging functionality
 */
class NotificationLoggingTest extends TestCase
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
        $this->pdo->exec("DELETE FROM sent_alerts");
        $this->pdo->exec("DELETE FROM zones");
        
        // Insert test zone data
        $this->pdo->exec("INSERT INTO zones (STATE, ZONE, NAME, STATE_ZONE, FIPS, LAT, LON) VALUES 
            ('IN', '040', 'Delaware County', 'INC040,INZ040', '018035', 40.3346, -85.6495)");
    }
    
    /**
     * Test that both pushover and ntfy notifications are logged separately
     *
     * @return void
     */
    public function testBothChannelsAreLogged(): void
    {
        $alertRow = [
            'id' => 'test-alert-1',
            'type' => 'Feature',
            'status' => 'Actual',
            'msg_type' => 'Alert',
            'category' => 'Met',
            'severity' => 'Moderate',
            'certainty' => 'Likely',
            'urgency' => 'Expected',
            'event' => 'Winter Weather Advisory',
            'headline' => 'Test Alert',
            'description' => 'Test description',
            'instruction' => 'Test instruction',
            'area_desc' => 'Test area',
            'sent' => '2024-01-01T12:00:00Z',
            'effective' => '2024-01-01T12:00:00Z',
            'onset' => '2024-01-01T13:00:00Z',
            'expires' => '2024-01-01T18:00:00Z',
            'ends' => '2024-01-01T18:00:00Z',
            'same_array' => '["INZ040"]',
            'ugc_array' => '["INC040"]',
            'json' => '{"id":"test-alert-1","properties":{"event":"Winter Weather Advisory"}}',
        ];
        
        $result = [
            'status' => 'processed',
            'user_id' => 1,
            'channels' => [
                [
                    'channel' => 'pushover',
                    'result' => [
                        'status' => 'success',
                        'attempts' => 1,
                        'error' => null,
                        'request_id' => 'test-req-id',
                    ]
                ],
                [
                    'channel' => 'ntfy',
                    'result' => [
                        'status' => 'success',
                        'attempts' => 1,
                        'error' => null,
                    ]
                ]
            ]
        ];
        
        $this->repo->insertSentResult($alertRow, $result);
        
        // Verify both channels are logged
        $stmt = $this->pdo->query("SELECT channel, user_id, result_status FROM sent_alerts WHERE id = 'test-alert-1' ORDER BY channel");
        $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(2, $records, 'Should have 2 records (one for each channel)');
        $this->assertEquals('ntfy', $records[0]['channel']);
        $this->assertEquals('success', $records[0]['result_status']);
        $this->assertEquals(1, $records[0]['user_id']);
        
        $this->assertEquals('pushover', $records[1]['channel']);
        $this->assertEquals('success', $records[1]['result_status']);
        $this->assertEquals(1, $records[1]['user_id']);
    }
    
    /**
     * Test that multiple users can receive the same alert
     *
     * @return void
     */
    public function testMultipleUsersPerAlert(): void
    {
        $alertRow = [
            'id' => 'test-alert-2',
            'type' => 'Feature',
            'status' => 'Actual',
            'msg_type' => 'Alert',
            'category' => 'Met',
            'severity' => 'Moderate',
            'certainty' => 'Likely',
            'urgency' => 'Expected',
            'event' => 'Flood Warning',
            'headline' => 'Test Flood Alert',
            'description' => 'Test description',
            'instruction' => 'Test instruction',
            'area_desc' => 'Test area',
            'sent' => '2024-01-01T12:00:00Z',
            'effective' => '2024-01-01T12:00:00Z',
            'onset' => '2024-01-01T13:00:00Z',
            'expires' => '2024-01-01T18:00:00Z',
            'ends' => '2024-01-01T18:00:00Z',
            'same_array' => '["INZ040"]',
            'ugc_array' => '["INC040"]',
            'json' => '{"id":"test-alert-2","properties":{"event":"Flood Warning"}}',
        ];
        
        // User 1 gets pushover and ntfy
        $result1 = [
            'status' => 'processed',
            'user_id' => 1,
            'channels' => [
                ['channel' => 'pushover', 'result' => ['status' => 'success', 'attempts' => 1, 'error' => null]],
                ['channel' => 'ntfy', 'result' => ['status' => 'success', 'attempts' => 1, 'error' => null]]
            ]
        ];
        $this->repo->insertSentResult($alertRow, $result1);
        
        // User 2 gets only ntfy
        $result2 = [
            'status' => 'processed',
            'user_id' => 2,
            'channels' => [
                ['channel' => 'ntfy', 'result' => ['status' => 'success', 'attempts' => 1, 'error' => null]]
            ]
        ];
        $this->repo->insertSentResult($alertRow, $result2);
        
        // Verify all records are present
        $stmt = $this->pdo->query("SELECT user_id, channel FROM sent_alerts WHERE id = 'test-alert-2' ORDER BY user_id, channel");
        $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(3, $records, 'Should have 3 records (2 for user 1, 1 for user 2)');
        
        // User 1 records
        $this->assertEquals(1, $records[0]['user_id']);
        $this->assertEquals('ntfy', $records[0]['channel']);
        $this->assertEquals(1, $records[1]['user_id']);
        $this->assertEquals('pushover', $records[1]['channel']);
        
        // User 2 record
        $this->assertEquals(2, $records[2]['user_id']);
        $this->assertEquals('ntfy', $records[2]['channel']);
    }
    
    /**
     * Test zone coordinate lookup functionality
     *
     * @return void
     */
    public function testZoneCoordinateLookup(): void
    {
        // Test with STATE_ZONE format (INC040, INZ040)
        $coords = $this->repo->getZoneCoordinates(['INC040']);
        $this->assertIsArray($coords);
        $this->assertEquals(40.3346, $coords['lat']);
        $this->assertEquals(-85.6495, $coords['lon']);
        
        $coords = $this->repo->getZoneCoordinates(['INZ040']);
        $this->assertEquals(40.3346, $coords['lat']);
        $this->assertEquals(-85.6495, $coords['lon']);
        
        // Test with FIPS code
        $coords = $this->repo->getZoneCoordinates(['018035']);
        $this->assertEquals(40.3346, $coords['lat']);
        $this->assertEquals(-85.6495, $coords['lon']);
        
        // Test with non-existent zone
        $coords = $this->repo->getZoneCoordinates(['NONEXISTENT']);
        $this->assertNull($coords['lat']);
        $this->assertNull($coords['lon']);
        
        // Test with empty array
        $coords = $this->repo->getZoneCoordinates([]);
        $this->assertNull($coords['lat']);
        $this->assertNull($coords['lon']);
    }
    
    /**
     * Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM sent_alerts");
        $this->pdo->exec("DELETE FROM zones");
        parent::tearDown();
    }
}
