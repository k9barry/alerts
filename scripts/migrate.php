<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;
use App\Config;

// Ensure the SQLite directory exists and is writable before connecting
$dbPath = Config::$dbPath; // Expected to be something like 'data/alerts.sqlite'
$dir = dirname($dbPath);

if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create database directory: {$dir}\n");
        exit(1);
    }
}

if (!is_writable($dir)) {
    fwrite(STDERR, "Database directory is not writable: {$dir}\n");
    exit(1);
}

$pdo = Connection::get();

// Unified alert schema columns matching weather.gov properties (plus arrays and json)
$alertColumns = [
  "id TEXT PRIMARY KEY", // API id
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

// Helper to create table with full schema if not exists
$createTable = function (PDO $pdo, string $table, string $extraCols) use ($alertColumns) {
  $all = implode(",\n  ", array_merge($alertColumns, array_filter([$extraCols])));
  $sql = "CREATE TABLE IF NOT EXISTS {$table} (\n  {$all}\n);";
  $pdo->exec($sql);
};

// Helper to add column if not exists (SQLite)
$ensureColumn = function (PDO $pdo, string $table, string $colDef) {
  [$colName] = explode(' ', $colDef, 2);
  $cols = $pdo->query("PRAGMA table_info('{$table}')")->fetchAll(PDO::FETCH_ASSOC);
  $names = array_map(fn($c) => $c['name'], $cols);
  if (!in_array($colName, $names, true)) {
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$colDef}");
  }
};

foreach ($tablesToEnsure as $table => $extra) {
  $createTable($pdo, $table, $extra);
  // Ensure all core columns exist
  foreach ($alertColumns as $def) {
    $ensureColumn($pdo, $table, $def);
  }
  // Ensure extra columns exist
  foreach (array_filter(array_map('trim', explode(',', $extra))) as $extraDef) {
    if ($extraDef !== '') {
      $ensureColumn($pdo, $table, $extraDef);
    }
  }
}

// Create zones table
$pdo->exec("CREATE TABLE IF NOT EXISTS zones (
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
  UNIQUE(STATE, ZONE, STATE_ZONE)
)");

// Create indexes for zones table
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state ON zones(STATE)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_name ON zones(NAME)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state_zone ON zones(STATE_ZONE)");

// Check if zones table needs UNIQUE constraint migration
// SQLite doesn't allow modifying constraints, so we need to check if the table
// has the old UNIQUE(STATE, ZONE) constraint without STATE_ZONE
$tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='zones'")->fetch(PDO::FETCH_ASSOC);
$needsConstraintMigration = false;
if ($tableInfo && isset($tableInfo['sql'])) {
    $sql = $tableInfo['sql'];
    // Check if it has UNIQUE(STATE, ZONE) but not UNIQUE(STATE, ZONE, STATE_ZONE)
    if (preg_match('/UNIQUE\s*\(\s*STATE\s*,\s*ZONE\s*\)/i', $sql) && 
        !preg_match('/UNIQUE\s*\(\s*STATE\s*,\s*ZONE\s*,\s*STATE_ZONE\s*\)/i', $sql)) {
        $needsConstraintMigration = true;
    }
}

if ($needsConstraintMigration) {
    echo "Migrating zones table to new UNIQUE constraint...\n";
    
    $pdo->beginTransaction();
    try {
        // Create new table with updated constraint
        $pdo->exec("CREATE TABLE zones_new (
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
          UNIQUE(STATE, ZONE, STATE_ZONE)
        )");
        
        // Copy existing data
        $pdo->exec("INSERT INTO zones_new SELECT * FROM zones");
        
        // Drop old table and rename new one
        $pdo->exec("DROP TABLE zones");
        $pdo->exec("ALTER TABLE zones_new RENAME TO zones");
        
        // Recreate indexes
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state ON zones(STATE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_name ON zones(NAME)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state_zone ON zones(STATE_ZONE)");
        
        $pdo->commit();
        echo "Zones table constraint migration completed\n";
    } catch (Exception $e) {
        $pdo->rollback();
        echo "Error migrating zones table constraint: " . $e->getMessage() . "\n";
        throw $e;
    }
}


// Create users table
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
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

// Add NtfyTopic column if it doesn't exist (for existing databases)
$ensureColumn($pdo, 'users', 'NtfyTopic TEXT');

// Create index for users email
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(Email)");

// Load zones data if file exists and table is empty
// Extract filename from zones data URL for local storage
$zonesFileName = basename(parse_url(Config::$zonesDataUrl, PHP_URL_PATH));
$zonesFile = $dir . '/' . $zonesFileName;
if (file_exists($zonesFile)) {
    $count = $pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
    
    // Check if we need to apply transformations to existing data
    // Look for records that don't have the "C" in STATE_ZONE (untransformed)
    $needsTransformation = $pdo->query("
        SELECT COUNT(*) FROM zones 
        WHERE LENGTH(STATE_ZONE) >= 3 
        AND SUBSTR(STATE_ZONE, 3, 1) != 'C'
        AND SUBSTR(STATE_ZONE, 3, 1) != 'Z'
        LIMIT 1
    ")->fetchColumn();
    
    // Check if we need to add Z variants for existing C records
    $needsZVariants = $pdo->query("
        SELECT COUNT(*) FROM zones z1
        WHERE LENGTH(z1.STATE_ZONE) >= 3 
        AND SUBSTR(z1.STATE_ZONE, 3, 1) = 'C'
        AND NOT EXISTS (
            SELECT 1 FROM zones z2 
            WHERE z2.STATE = z1.STATE 
            AND z2.ZONE = z1.ZONE 
            AND SUBSTR(z2.STATE_ZONE, 3, 1) = 'Z'
        )
        LIMIT 1
    ")->fetchColumn();
    
    if ($needsTransformation > 0) {
        echo "Applying transformations to existing zones data...\n";
        
        $pdo->beginTransaction();
        try {
            // First, fetch all untransformed zones to duplicate with Z variant
            $stmt = $pdo->query("
                SELECT * FROM zones 
                WHERE LENGTH(STATE_ZONE) >= 3 
                AND SUBSTR(STATE_ZONE, 3, 1) != 'C'
                AND SUBSTR(STATE_ZONE, 3, 1) != 'Z'
            ");
            $zonesToDuplicate = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update STATE_ZONE: Add "C" as third character for existing records
            $stmt = $pdo->prepare("
                UPDATE zones 
                SET STATE_ZONE = SUBSTR(STATE_ZONE, 1, 2) || 'C' || SUBSTR(STATE_ZONE, 3)
                WHERE LENGTH(STATE_ZONE) >= 3 
                AND SUBSTR(STATE_ZONE, 3, 1) != 'C'
                AND SUBSTR(STATE_ZONE, 3, 1) != 'Z'
            ");
            $stmt->execute();
            $stateZoneUpdates = $stmt->rowCount();
            
            // Insert Z variants for each zone that was just transformed
            $insertStmt = $pdo->prepare(
                "INSERT OR IGNORE INTO zones (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $zVariantCount = 0;
            foreach ($zonesToDuplicate as $zone) {
                $stateZone = $zone['STATE_ZONE'] ?? '';
                if (strlen($stateZone) >= 3) {
                    // Create Z variant
                    $stateZoneZ = substr($stateZone, 0, 2) . 'Z' . substr($stateZone, 2);
                    $insertStmt->execute([
                        $zone['STATE'],
                        $zone['ZONE'],
                        $zone['CWA'],
                        $zone['NAME'],
                        $stateZoneZ,
                        $zone['COUNTY'],
                        $zone['FIPS'],
                        $zone['TIME_ZONE'],
                        $zone['FE_AREA'],
                        $zone['LAT'],
                        $zone['LON']
                    ]);
                    $zVariantCount++;
                }
            }
            
            // Update FIPS: Add "0" as first character  
            $stmt = $pdo->prepare("
                UPDATE zones 
                SET FIPS = '0' || FIPS
                WHERE LENGTH(FIPS) = 5 
                AND FIPS GLOB '[0-9][0-9][0-9][0-9][0-9]'
                AND SUBSTR(FIPS, 1, 1) != '0'
            ");
            $stmt->execute();
            $fipsUpdates = $stmt->rowCount();
            
            $pdo->commit();
            echo "Applied transformations: {$stateZoneUpdates} STATE_ZONE C-variants, {$zVariantCount} Z-variants added, {$fipsUpdates} FIPS updates\n";
        } catch (Exception $e) {
            $pdo->rollback();
            echo "Error applying transformations: " . $e->getMessage() . "\n";
        }
    }
    
    // Add Z variants for existing C records if they don't exist
    if ($needsZVariants > 0) {
        echo "Adding Z variants for existing C records...\n";
        
        $pdo->beginTransaction();
        try {
            // Fetch all C-variant zones that don't have corresponding Z variants
            $stmt = $pdo->query("
                SELECT z1.* FROM zones z1
                WHERE LENGTH(z1.STATE_ZONE) >= 3 
                AND SUBSTR(z1.STATE_ZONE, 3, 1) = 'C'
                AND NOT EXISTS (
                    SELECT 1 FROM zones z2 
                    WHERE z2.STATE = z1.STATE 
                    AND z2.ZONE = z1.ZONE 
                    AND SUBSTR(z2.STATE_ZONE, 3, 1) = 'Z'
                )
            ");
            $cVariantZones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Insert Z variant for each C variant
            $insertStmt = $pdo->prepare(
                "INSERT OR IGNORE INTO zones (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $zVariantCount = 0;
            foreach ($cVariantZones as $zone) {
                $stateZone = $zone['STATE_ZONE'] ?? '';
                if (strlen($stateZone) >= 3 && substr($stateZone, 2, 1) === 'C') {
                    // Replace C with Z to create Z variant
                    $stateZoneZ = substr($stateZone, 0, 2) . 'Z' . substr($stateZone, 3);
                    $insertStmt->execute([
                        $zone['STATE'],
                        $zone['ZONE'],
                        $zone['CWA'],
                        $zone['NAME'],
                        $stateZoneZ,
                        $zone['COUNTY'],
                        $zone['FIPS'],
                        $zone['TIME_ZONE'],
                        $zone['FE_AREA'],
                        $zone['LAT'],
                        $zone['LON']
                    ]);
                    $zVariantCount++;
                }
            }
            
            $pdo->commit();
            echo "Added {$zVariantCount} Z-variants for existing C records\n";
        } catch (Exception $e) {
            $pdo->rollback();
            echo "Error adding Z variants: " . $e->getMessage() . "\n";
        }
    }
    
    if ($count == 0) {
        echo "Loading zones data from {$zonesFileName}...\n";
        $lines = file($zonesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = null;
        $loaded = 0;
        
        foreach ($lines as $line) {
            $fields = explode('|', $line);
            if ($header === null) {
                $header = $fields;
                continue;
            }
            
            if (count($fields) >= 11) {
                // Apply transformations to STATE_ZONE and FIPS
                $stateZone = $fields[4] ?? '';
                $fips = $fields[6] ?? '';
                
                // 1. Create both "C" and "Z" variants of STATE_ZONE
                //    Original: NM201 becomes both NMC201 (C variant) and NMZ201 (Z variant)
                $stateZoneVariants = [];
                if (strlen($stateZone) >= 3) {
                    $stateZoneVariants[] = substr($stateZone, 0, 2) . 'C' . substr($stateZone, 2); // C variant
                    $stateZoneVariants[] = substr($stateZone, 0, 2) . 'Z' . substr($stateZone, 2); // Z variant
                } else {
                    // If STATE_ZONE is too short, keep as-is
                    $stateZoneVariants[] = $stateZone;
                }
                
                // 2. Add "0" as the first character in FIPS (e.g., 35045 becomes 035045)
                if (!empty($fips) && is_numeric($fips)) {
                    $fips = '0' . $fips;
                }
                
                // 3. Insert a record for each STATE_ZONE variant (C and Z)
                $stmt = $pdo->prepare(
                    "INSERT OR IGNORE INTO zones (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                foreach ($stateZoneVariants as $stateZoneVariant) {
                    $stmt->execute([
                        $fields[0] ?? '', // STATE
                        $fields[1] ?? '', // ZONE
                        $fields[2] ?? '', // CWA
                        $fields[3] ?? '', // NAME
                        $stateZoneVariant, // STATE_ZONE (C or Z variant)
                        $fields[5] ?? '', // COUNTY
                        $fips,           // FIPS (modified, same for both variants)
                        $fields[7] ?? '', // TIME_ZONE
                        $fields[8] ?? '', // FE_AREA
                        !empty($fields[9]) ? (float)$fields[9] : null, // LAT
                        !empty($fields[10]) ? (float)$fields[10] : null  // LON
                    ]);
                    $loaded++;
                }
            }
        }
        echo "Loaded {$loaded} zone records.\n";
    }
}

// Verify and summarize created tables
$tables = $pdo->query(
    "SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$expected = ['active_alerts','incoming_alerts','pending_alerts','sent_alerts','users','zones'];
$missing = array_values(array_diff($expected, $tables));
$unexpected = array_values(array_diff($tables, $expected));

echo "Migrations applied\n";
echo "Tables present (" . count($tables) . "): " . implode(', ', $tables) . "\n";
if (!empty($missing)) {
    fwrite(STDERR, "Missing expected tables: " . implode(', ', $missing) . "\n");
}
if (!empty($unexpected)) {
    echo "Note: Additional tables found: " . implode(', ', $unexpected) . "\n";
}
