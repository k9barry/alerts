<?php
/**
 * Alert Geometry Coordinate Extraction Test
 * 
 * Tests that AlertProcessor can extract coordinates from alert geometry
 * when zones table lookup fails, ensuring MapClick URLs are always generated
 * when coordinates are available in the alert data.
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
 * Test class for alert geometry coordinate extraction
 */
class AlertGeometryCoordinateExtractionTest extends TestCase
{
    use TestMigrationTrait;
    
    /**
     * @var \PDO Database connection
     */
    private \PDO $pdo;
    
    /**
     * @var AlertsRepository Repository instance
     */
    private AlertsRepository $repo;
    
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
        $this->pdo->exec("DELETE FROM users WHERE Email LIKE 'test-geometry-%'");
    }
    
    /**
     * Create a test alert with GeoJSON geometry
     *
     * @param string $idSuffix Unique suffix for the alert ID
     * @param array $geometry GeoJSON geometry object
     * @param array $overrides Optional property overrides
     * @return array Alert in weather.gov format with geometry
     */
    private function createTestAlertWithGeometry(string $idSuffix, array $geometry, array $overrides = []): array
    {
        $id = 'test-geometry-alert-' . $idSuffix;
        $defaults = [
            'id' => $id,
            'type' => 'Feature',
            'geometry' => $geometry,
            'same_array' => ['GEOMETRY_TEST_ZONE'],
            'ugc_array' => ['GEOZ001'],
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Severe',
                'certainty' => 'Likely',
                'urgency' => 'Immediate',
                'event' => 'Test Geometry Alert',
                'headline' => 'Test Alert with Geometry',
                'description' => 'Testing geometry coordinate extraction',
                'instruction' => 'Test instructions',
                'areaDesc' => 'Test Geometry County',
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
     * Test that coordinates can be extracted from Polygon geometry
     *
     * @return void
     */
    public function testExtractCoordinatesFromPolygonGeometry(): void
    {
        // Create an alert with polygon geometry (typical weather.gov format)
        // GeoJSON Polygon format: coordinates[0] is outer ring, each point is [lon, lat]
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [
                [
                    [-86.5, 40.0],  // Southwest corner
                    [-86.0, 40.0],  // Southeast corner
                    [-86.0, 40.5],  // Northeast corner
                    [-86.5, 40.5],  // Northwest corner
                    [-86.5, 40.0],  // Close the ring
                ]
            ]
        ];
        
        $testAlert = $this->createTestAlertWithGeometry(uniqid(), $geometry);
        
        // Store in database
        $this->repo->replaceIncoming([$testAlert]);
        
        // Get the stored alert
        $stmt = $this->pdo->prepare("SELECT * FROM incoming_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $storedAlert = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($storedAlert, 'Alert should be stored in database');
        $this->assertNotEmpty($storedAlert['json'], 'Alert should have JSON data');
        
        // Verify geometry is preserved in JSON
        $json = json_decode($storedAlert['json'], true);
        $this->assertIsArray($json, 'JSON should decode to array');
        $this->assertArrayHasKey('geometry', $json, 'JSON should contain geometry');
        $this->assertEquals('Polygon', $json['geometry']['type'], 'Geometry type should be Polygon');
        
        // Verify coordinates are present
        $coords = $json['geometry']['coordinates'][0][0] ?? null;
        $this->assertIsArray($coords, 'First coordinate should be an array');
        $this->assertCount(2, $coords, 'Coordinate should have 2 elements [lon, lat]');
        $this->assertEquals(-86.5, $coords[0], 'Longitude should be -86.5');
        $this->assertEquals(40.0, $coords[1], 'Latitude should be 40.0');
    }
    
    /**
     * Test that coordinates can be extracted from MultiPolygon geometry
     *
     * @return void
     */
    public function testExtractCoordinatesFromMultiPolygonGeometry(): void
    {
        // Create an alert with MultiPolygon geometry
        // This format is used when the alert area consists of multiple disconnected regions
        $geometry = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [ // First polygon
                    [
                        [-85.0, 39.0],
                        [-84.5, 39.0],
                        [-84.5, 39.5],
                        [-85.0, 39.5],
                        [-85.0, 39.0],
                    ]
                ],
                [ // Second polygon
                    [
                        [-86.0, 40.0],
                        [-85.5, 40.0],
                        [-85.5, 40.5],
                        [-86.0, 40.5],
                        [-86.0, 40.0],
                    ]
                ]
            ]
        ];
        
        $testAlert = $this->createTestAlertWithGeometry(uniqid(), $geometry);
        
        // Store in database
        $this->repo->replaceIncoming([$testAlert]);
        
        // Get the stored alert
        $stmt = $this->pdo->prepare("SELECT * FROM incoming_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $storedAlert = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($storedAlert, 'Alert should be stored in database');
        
        // Verify geometry is preserved
        $json = json_decode($storedAlert['json'], true);
        $this->assertEquals('MultiPolygon', $json['geometry']['type'], 'Geometry type should be MultiPolygon');
        
        // Verify first polygon's first coordinate
        $coords = $json['geometry']['coordinates'][0][0][0] ?? null;
        $this->assertIsArray($coords, 'First coordinate should be an array');
        $this->assertEquals(-85.0, $coords[0], 'Longitude should be -85.0');
        $this->assertEquals(39.0, $coords[1], 'Latitude should be 39.0');
    }
    
    /**
     * Test that alerts without geometry return null coordinates
     *
     * @return void
     */
    public function testAlertWithoutGeometryReturnsNullCoordinates(): void
    {
        // Create an alert without geometry field
        $testAlert = [
            'id' => 'test-no-geometry-' . uniqid(),
            'type' => 'Feature',
            'same_array' => ['NO_GEOM_ZONE'],
            'ugc_array' => ['NGZ001'],
            'properties' => [
                'status' => 'Actual',
                'messageType' => 'Alert',
                'category' => 'Met',
                'severity' => 'Minor',
                'certainty' => 'Possible',
                'urgency' => 'Future',
                'event' => 'Test No Geometry',
                'headline' => 'Alert without geometry',
                'description' => 'Testing null geometry handling',
                'instruction' => '',
                'areaDesc' => 'Test Area',
                'sent' => date('Y-m-d\TH:i:sP'),
                'effective' => date('Y-m-d\TH:i:sP'),
                'onset' => date('Y-m-d\TH:i:sP'),
                'expires' => date('Y-m-d\TH:i:sP', strtotime('+1 hour')),
                'ends' => date('Y-m-d\TH:i:sP', strtotime('+1 hour')),
            ]
        ];
        // Note: no 'geometry' key in this alert
        
        $this->repo->replaceIncoming([$testAlert]);
        
        // Get the stored alert
        $stmt = $this->pdo->prepare("SELECT * FROM incoming_alerts WHERE id = ?");
        $stmt->execute([$testAlert['id']]);
        $storedAlert = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($storedAlert, 'Alert should be stored');
        
        // Verify no geometry in JSON
        $json = json_decode($storedAlert['json'], true);
        $this->assertArrayNotHasKey('geometry', $json, 'JSON should not contain geometry key');
    }
    
    /**
     * Clean up test environment after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->exec("DELETE FROM incoming_alerts WHERE id LIKE 'test-geometry-%' OR id LIKE 'test-no-geometry-%'");
        $this->pdo->exec("DELETE FROM active_alerts WHERE id LIKE 'test-geometry-%' OR id LIKE 'test-no-geometry-%'");
        $this->pdo->exec("DELETE FROM pending_alerts WHERE id LIKE 'test-geometry-%' OR id LIKE 'test-no-geometry-%'");
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-geometry-%' OR id LIKE 'test-no-geometry-%'");
        $this->pdo->exec("DELETE FROM users WHERE Email LIKE 'test-geometry-%'");
        
        parent::tearDown();
    }
}
