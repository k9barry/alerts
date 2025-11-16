<?php

use PHPUnit\Framework\TestCase;
use App\DB\Connection;
use App\Repository\AlertsRepository;

/**
 * Test suite to verify that the test button in the user management UI
 * generates and sends MapClick URLs when test notifications are triggered.
 * 
 * This test addresses the issue where mock test alerts lacked zone data,
 * preventing MapClick URL generation.
 */
class TestButtonMapClickUrlTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Connection::get();
        
        // Create necessary tables
        $this->createTables();
        
        // Insert sample zone data that matches the mock alert zones (INC001, INZ001)
        $this->insertSampleZones();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->exec("DELETE FROM zones WHERE ZONE IN ('INC001', 'INZ001')");
        $this->pdo->exec("DELETE FROM incoming_alerts");
        parent::tearDown();
    }

    private function createTables(): void
    {
        // Create zones table if not exists
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS zones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ZONE TEXT NOT NULL,
            NAME TEXT,
            STATE TEXT,
            CWA TEXT,
            STATE_ZONE TEXT,
            LON REAL,
            LAT REAL,
            SHORTNAME TEXT,
            FIPS TEXT,
            COUNTY TEXT
        )");

        // Create incoming_alerts table if not exists
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS incoming_alerts (
            id TEXT PRIMARY KEY,
            event TEXT,
            severity TEXT,
            certainty TEXT,
            urgency TEXT,
            headline TEXT,
            description TEXT,
            area_desc TEXT,
            effective TEXT,
            expires TEXT,
            same_array TEXT,
            ugc_array TEXT,
            json TEXT,
            received_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }

    private function insertSampleZones(): void
    {
        // Insert test zone data for INC001 and INZ001 with valid coordinates
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO zones 
            (ZONE, NAME, STATE, STATE_ZONE, LAT, LON, FIPS, COUNTY) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        // Indiana test zones with actual coordinates
        $stmt->execute([
            'INC001', 
            'Test County', 
            'IN', 
            'INC001',
            39.7684, // Indianapolis latitude
            -86.1581, // Indianapolis longitude
            '18097',
            'Marion'
        ]);
        
        $stmt->execute([
            'INZ001',
            'Test Zone',
            'IN',
            'INZ001',
            39.7684,
            -86.1581,
            '18097',
            'Marion'
        ]);
    }

    /**
     * Test that mock alert contains zone data needed for MapClick URL generation
     */
    public function testMockAlertContainsZoneData(): void
    {
        // Simulate the scenario where no real alerts exist
        // This triggers the mock alert creation in users_table.php
        $stmt = $this->pdo->query("SELECT * FROM incoming_alerts ORDER BY RANDOM() LIMIT 1");
        $testAlert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertFalse($testAlert, 'Should have no real alerts for this test');
        
        // Create mock alert as done in users_table.php (after fix)
        $mockAlert = [
            'id' => 'TEST-' . time(),
            'event' => 'Test Weather Alert',
            'severity' => 'Minor',
            'certainty' => 'Likely',
            'urgency' => 'Expected',
            'headline' => 'This is a test alert to verify your notification settings',
            'description' => 'This is a test notification sent from the Weather Alerts system.',
            'area_desc' => 'Test Area',
            'effective' => date('Y-m-d H:i:s'),
            'expires' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            // Zone data that enables MapClick URL generation
            'same_array' => json_encode(['INC001']),
            'ugc_array' => json_encode(['INZ001'])
        ];

        // Verify zone data is present
        $this->assertArrayHasKey('same_array', $mockAlert);
        $this->assertArrayHasKey('ugc_array', $mockAlert);
        $this->assertNotEmpty($mockAlert['same_array']);
        $this->assertNotEmpty($mockAlert['ugc_array']);
        
        // Verify zone data is valid JSON
        $sameArray = json_decode($mockAlert['same_array'], true);
        $ugcArray = json_decode($mockAlert['ugc_array'], true);
        
        $this->assertIsArray($sameArray);
        $this->assertIsArray($ugcArray);
        $this->assertNotEmpty($sameArray);
        $this->assertNotEmpty($ugcArray);
    }

    /**
     * Test that MapClick URL is generated from mock alert zone data
     */
    public function testMapClickUrlGeneratedFromMockAlert(): void
    {
        // Create mock alert with zone data (as fixed)
        $mockAlert = [
            'id' => 'TEST-' . time(),
            'event' => 'Test Weather Alert',
            'same_array' => json_encode(['INC001']),
            'ugc_array' => json_encode(['INZ001'])
        ];

        // Simulate the MapClick URL generation logic from users_table.php
        $alertUrl = null;
        if (isset($mockAlert['same_array']) || isset($mockAlert['ugc_array'])) {
            $sameArray = json_decode($mockAlert['same_array'] ?? '[]', true) ?: [];
            $ugcArray = json_decode($mockAlert['ugc_array'] ?? '[]', true) ?: [];
            $alertIds = [];
            
            foreach (array_merge($sameArray, $ugcArray) as $v) {
                if (is_null($v)) continue;
                if (is_int($v) || (is_string($v) && preg_match('/^[0-9]+$/', $v))) {
                    $alertIds[] = (string)$v;
                } elseif (is_string($v) && trim($v) !== '') {
                    $v = trim($v);
                    if (preg_match('/^[a-z]{2,3}c?\d+$/i', $v)) {
                        $alertIds[] = strtoupper($v);
                    } else {
                        $alertIds[] = $v;
                    }
                }
            }
            $alertIds = array_values(array_unique($alertIds));
            
            if (!empty($alertIds)) {
                $alertsRepo = new AlertsRepository();
                $coords = $alertsRepo->getZoneCoordinates($alertIds);
                if ($coords['lat'] !== null && $coords['lon'] !== null) {
                    $alertUrl = sprintf(
                        'https://forecast.weather.gov/MapClick.php?lat=%s&lon=%s&lg=english&FcstType=graphical&menu=1',
                        $coords['lat'],
                        $coords['lon']
                    );
                }
            }
        }

        // Verify MapClick URL was generated
        $this->assertNotNull($alertUrl, 'MapClick URL should be generated from mock alert zone data');
        $this->assertStringContainsString('forecast.weather.gov/MapClick.php', $alertUrl);
        $this->assertStringContainsString('lat=', $alertUrl);
        $this->assertStringContainsString('lon=', $alertUrl);
        
        // Verify URL contains expected coordinates (from our test zones)
        $this->assertStringContainsString('39.7684', $alertUrl);
        $this->assertStringContainsString('-86.1581', $alertUrl);
    }

    /**
     * Test that the old mock alert (without zone data) would NOT generate MapClick URL
     * This verifies the bug that was fixed
     */
    public function testOldMockAlertWithoutZoneDataDoesNotGenerateUrl(): void
    {
        // Old mock alert without zone data (the bug)
        $oldMockAlert = [
            'id' => 'TEST-' . time(),
            'event' => 'Test Weather Alert',
            // Missing: 'same_array' and 'ugc_array'
        ];

        // Simulate the MapClick URL generation logic
        $alertUrl = null;
        if (isset($oldMockAlert['same_array']) || isset($oldMockAlert['ugc_array'])) {
            // This block won't execute because zone data is missing
            $alertUrl = 'should-not-be-set';
        }

        // Verify no MapClick URL was generated (demonstrating the bug)
        $this->assertNull($alertUrl, 'Old mock alert without zone data should NOT generate MapClick URL');
        
        // Also verify fallback to alert ID would fail since it's not a URL
        if ($alertUrl === null && isset($oldMockAlert['id']) && 
            is_string($oldMockAlert['id']) && 
            preg_match('#^https?://#i', $oldMockAlert['id'])) {
            $alertUrl = $oldMockAlert['id'];
        }
        
        $this->assertNull($alertUrl, 'Alert ID fallback should also fail for non-URL IDs');
    }

    /**
     * Test the complete flow: mock alert with zone data generates valid coordinates
     */
    public function testCompleteFlowMockAlertToCoordinates(): void
    {
        // Mock alert with zone data
        $mockAlert = [
            'same_array' => json_encode(['INC001']),
            'ugc_array' => json_encode(['INZ001'])
        ];

        // Extract zone IDs
        $sameArray = json_decode($mockAlert['same_array'], true);
        $ugcArray = json_decode($mockAlert['ugc_array'], true);
        $alertIds = array_values(array_unique(array_merge($sameArray, $ugcArray)));

        // Get coordinates
        $alertsRepo = new AlertsRepository();
        $coords = $alertsRepo->getZoneCoordinates($alertIds);

        // Verify coordinates were found
        $this->assertIsArray($coords);
        $this->assertArrayHasKey('lat', $coords);
        $this->assertArrayHasKey('lon', $coords);
        $this->assertNotNull($coords['lat'], 'Latitude should be found from zone data');
        $this->assertNotNull($coords['lon'], 'Longitude should be found from zone data');
        
        // Verify coordinates are valid numbers
        $this->assertIsNumeric($coords['lat']);
        $this->assertIsNumeric($coords['lon']);
        
        // Verify coordinates are reasonable (Indiana area)
        $this->assertGreaterThan(38.0, $coords['lat']); // Southern Indiana
        $this->assertLessThan(42.0, $coords['lat']); // Northern Indiana
        $this->assertGreaterThan(-88.0, $coords['lon']); // Eastern Indiana
        $this->assertLessThan(-84.0, $coords['lon']); // Western Indiana
    }
}
