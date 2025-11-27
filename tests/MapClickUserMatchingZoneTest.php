<?php
/**
 * MapClick User Matching Zone Test
 * 
 * Test suite to verify that MapClick URLs use coordinates from the user's
 * matching zone, not any arbitrary zone from the alert.
 * 
 * This test addresses the issue where MapClick URLs were using coordinates
 * from the first zone in the alert's zone list instead of the zone that
 * matched the user's subscribed zones.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\DB\Connection;
use App\Repository\AlertsRepository;

/**
 * Test class for MapClick URL user matching zone logic
 */
class MapClickUserMatchingZoneTest extends TestCase
{
    use TestMigrationTrait;

    /**
     * Test zone identifiers and coordinates
     * Two different zones with different coordinates
     */
    private const ZONE_A = 'INC001';  // Indiana zone A
    private const ZONE_A_LAT = 39.7684;
    private const ZONE_A_LON = -86.1581;
    
    private const ZONE_B = 'OHC001';  // Ohio zone B (different state)
    private const ZONE_B_LAT = 40.7128;
    private const ZONE_B_LON = -82.0193;
    
    /**
     * @var \PDO Database connection
     */
    private \PDO $pdo;

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Connection::get();
        
        // Run migrations to create tables
        $this->runMigrations();
        
        // Clean up any existing test data
        $stmt = $this->pdo->prepare("DELETE FROM zones WHERE ZONE IN (?, ?)");
        $stmt->execute([self::ZONE_A, self::ZONE_B]);
        
        // Insert two test zones with different coordinates
        $this->insertTestZones();
    }

    /**
     * Clean up test environment after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data
        $stmt = $this->pdo->prepare("DELETE FROM zones WHERE ZONE IN (?, ?)");
        $stmt->execute([self::ZONE_A, self::ZONE_B]);
        parent::tearDown();
    }

    /**
     * Insert test zones with different coordinates
     *
     * @return void
     */
    private function insertTestZones(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO zones 
            (ZONE, NAME, STATE, STATE_ZONE, LAT, LON, FIPS, COUNTY) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        // Zone A - Indiana
        $stmt->execute([
            self::ZONE_A, 
            'Indiana Test County', 
            'IN', 
            self::ZONE_A,
            self::ZONE_A_LAT,
            self::ZONE_A_LON,
            '18001',
            'Adams'
        ]);
        
        // Zone B - Ohio (different state, different coordinates)
        $stmt->execute([
            self::ZONE_B,
            'Ohio Test County',
            'OH',
            self::ZONE_B,
            self::ZONE_B_LAT,
            self::ZONE_B_LON,
            '39001',
            'Adams'
        ]);
    }

    /**
     * Test that getZoneCoordinates returns coordinates for the first zone in the array
     * This verifies the repository behavior
     *
     * @return void
     */
    public function testGetZoneCoordinatesReturnsFirstMatchingZone(): void
    {
        $repo = new AlertsRepository();
        
        // When querying with Zone A first, should get Zone A's coordinates
        $coords = $repo->getZoneCoordinates([self::ZONE_A, self::ZONE_B]);
        $this->assertEquals(self::ZONE_A_LAT, $coords['lat']);
        $this->assertEquals(self::ZONE_A_LON, $coords['lon']);
    }

    /**
     * Test the key fix: when user is subscribed to Zone B and alert has both zones,
     * passing only matching zones ensures we get coordinates for Zone B
     *
     * @return void
     */
    public function testUserMatchingZoneGetsCorrectCoordinates(): void
    {
        $repo = new AlertsRepository();
        
        // Simulate: Alert has zones [ZONE_A, ZONE_B]
        $alertZones = [self::ZONE_A, self::ZONE_B];
        
        // Simulate: User is subscribed only to ZONE_B
        $userZones = [self::ZONE_B];
        
        // The fix: get intersection of alert zones and user zones
        $matchingZones = array_values(array_intersect($alertZones, $userZones));
        
        // Verify the intersection is correct
        $this->assertEquals([self::ZONE_B], $matchingZones);
        
        // Get coordinates for only the matching zones (the fix)
        $coords = $repo->getZoneCoordinates($matchingZones);
        
        // Should get Zone B's coordinates since that's the user's zone
        $this->assertEquals(self::ZONE_B_LAT, $coords['lat']);
        $this->assertEquals(self::ZONE_B_LON, $coords['lon']);
    }

    /**
     * Test that when alert has multiple zones and user subscribes to first zone,
     * we correctly get coordinates for that zone
     *
     * @return void
     */
    public function testUserSubscribedToFirstAlertZone(): void
    {
        $repo = new AlertsRepository();
        
        // Simulate: Alert has zones [ZONE_A, ZONE_B]
        $alertZones = [self::ZONE_A, self::ZONE_B];
        
        // Simulate: User is subscribed only to ZONE_A
        $userZones = [self::ZONE_A];
        
        // The fix: get intersection
        $matchingZones = array_values(array_intersect($alertZones, $userZones));
        
        // Verify the intersection is correct
        $this->assertEquals([self::ZONE_A], $matchingZones);
        
        // Get coordinates for matching zones
        $coords = $repo->getZoneCoordinates($matchingZones);
        
        // Should get Zone A's coordinates
        $this->assertEquals(self::ZONE_A_LAT, $coords['lat']);
        $this->assertEquals(self::ZONE_A_LON, $coords['lon']);
    }

    /**
     * Test demonstrating the bug before the fix:
     * Without the fix, passing all alert zones could return wrong coordinates
     *
     * @return void
     */
    public function testDemonstrateWhyIntersectionIsNeeded(): void
    {
        $repo = new AlertsRepository();
        
        // Simulate: Alert has zones [ZONE_A, ZONE_B]
        $alertZones = [self::ZONE_A, self::ZONE_B];
        
        // Simulate: User only cares about ZONE_B
        $userZones = [self::ZONE_B];
        
        // BUG (before fix): passing all alert zones could return ZONE_A's coordinates
        // because getZoneCoordinates returns first matching zone in the database
        $coordsBefore = $repo->getZoneCoordinates($alertZones);
        
        // FIX: pass only matching zones
        $matchingZones = array_values(array_intersect($alertZones, $userZones));
        $coordsAfter = $repo->getZoneCoordinates($matchingZones);
        
        // The fix ensures we get Zone B's coordinates for a user subscribed to Zone B
        $this->assertEquals(self::ZONE_B_LAT, $coordsAfter['lat']);
        $this->assertEquals(self::ZONE_B_LON, $coordsAfter['lon']);
        
        // This test documents the issue: without the fix, we might have gotten Zone A's coords
        // when the user only subscribed to Zone B
    }

    /**
     * Test that invalid zone IDs are handled gracefully
     *
     * @return void
     */
    public function testInvalidZoneIdValidation(): void
    {
        $repo = new AlertsRepository();
        
        // Test with empty array
        $coords = $repo->getZoneCoordinates([]);
        $this->assertNull($coords['lat']);
        $this->assertNull($coords['lon']);
        
        // Test with invalid zone IDs (should be skipped by validation)
        $coords = $repo->getZoneCoordinates(['invalid-zone!', 'a@bc', '']);
        $this->assertNull($coords['lat']);
        $this->assertNull($coords['lon']);
        
        // Test with a mix of valid and invalid - should still find valid one
        $coords = $repo->getZoneCoordinates(['bad@id', self::ZONE_A, 'x!y']);
        $this->assertEquals(self::ZONE_A_LAT, $coords['lat']);
        $this->assertEquals(self::ZONE_A_LON, $coords['lon']);
    }

    /**
     * Test that zone IDs that are too long are rejected
     *
     * @return void
     */
    public function testLongZoneIdValidation(): void
    {
        $repo = new AlertsRepository();
        
        // Zone ID longer than 10 characters should be skipped
        $longZoneId = 'VERYLONGZONEID123';
        $coords = $repo->getZoneCoordinates([$longZoneId]);
        $this->assertNull($coords['lat']);
        $this->assertNull($coords['lon']);
        
        // But valid zones should still work
        $coords = $repo->getZoneCoordinates([$longZoneId, self::ZONE_B]);
        $this->assertEquals(self::ZONE_B_LAT, $coords['lat']);
        $this->assertEquals(self::ZONE_B_LON, $coords['lon']);
    }
}
