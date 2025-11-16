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
  UNIQUE(STATE, ZONE)
)");

// Create indexes for zones table
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state ON zones(STATE)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_name ON zones(NAME)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state_zone ON zones(STATE_ZONE)");

// Check if zones table needs UNIQUE constraint migration or deduplication
$tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='zones'")->fetch(PDO::FETCH_ASSOC);
$needsConstraintMigration = false;
$needsDeduplication = false;

if ($tableInfo && isset($tableInfo['sql'])) {
    $sql = $tableInfo['sql'];
    // Check if it has UNIQUE(STATE, ZONE, STATE_ZONE) - needs migration to (STATE, ZONE)
    if (preg_match('/UNIQUE\s*\(\s*STATE\s*,\s*ZONE\s*,\s*STATE_ZONE\s*\)/i', $sql)) {
        $needsConstraintMigration = true;
        $needsDeduplication = true;
    }
}

if ($needsConstraintMigration) {
    echo "Migrating zones table to consolidate duplicate records...\n";
    
    $pdo->beginTransaction();
    try {
        // First, consolidate duplicate records by combining STATE_ZONE values
        // Get all unique (STATE, ZONE) combinations with their STATE_ZONE values
        $stmt = $pdo->query("
            SELECT STATE, ZONE, GROUP_CONCAT(STATE_ZONE, ',') as STATE_ZONE_COMBINED,
                   MIN(idx) as min_idx,
                   MAX(CWA) as CWA, MAX(NAME) as NAME, MAX(COUNTY) as COUNTY,
                   MAX(FIPS) as FIPS, MAX(TIME_ZONE) as TIME_ZONE, MAX(FE_AREA) as FE_AREA,
                   MAX(LAT) as LAT, MAX(LON) as LON
            FROM zones
            GROUP BY STATE, ZONE
            HAVING COUNT(*) > 1
        ");
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Found " . count($duplicates) . " sets of duplicate records to consolidate\n";
        
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
          UNIQUE(STATE, ZONE)
        )");
        
        // Copy consolidated data - for duplicates, combine STATE_ZONE values
        // First, handle the unique records (no duplicates)
        $pdo->exec("
            INSERT INTO zones_new (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON)
            SELECT STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON
            FROM zones
            WHERE (STATE, ZONE) IN (
                SELECT STATE, ZONE FROM zones GROUP BY STATE, ZONE HAVING COUNT(*) = 1
            )
        ");
        
        // Then, insert consolidated duplicate records
        $insertStmt = $pdo->prepare("
            INSERT INTO zones_new (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($duplicates as $dup) {
            $insertStmt->execute([
                $dup['STATE'],
                $dup['ZONE'],
                $dup['CWA'],
                $dup['NAME'],
                $dup['STATE_ZONE_COMBINED'], // Combined STATE_ZONE values
                $dup['COUNTY'],
                $dup['FIPS'],
                $dup['TIME_ZONE'],
                $dup['FE_AREA'],
                $dup['LAT'],
                $dup['LON']
            ]);
        }
        
        // Drop old table and rename new one
        $pdo->exec("DROP TABLE zones");
        $pdo->exec("ALTER TABLE zones_new RENAME TO zones");
        
        // Recreate indexes
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state ON zones(STATE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_name ON zones(NAME)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zones_state_zone ON zones(STATE_ZONE)");
        
        $pdo->commit();
        echo "Zones table migration completed - consolidated " . count($duplicates) . " duplicate records\n";
    } catch (Exception $e) {
        $pdo->rollback();
        echo "Error migrating zones table: " . $e->getMessage() . "\n";
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

// Migrate sent_alerts table to support composite primary key (id, user_id)
// This allows multiple users to receive the same alert and have separate records
$sentAlertsInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='sent_alerts'")->fetch(PDO::FETCH_ASSOC);
if ($sentAlertsInfo && isset($sentAlertsInfo['sql'])) {
    $sql = $sentAlertsInfo['sql'];
    // Check if it has single PRIMARY KEY on id (needs migration to composite key)
    if (preg_match('/id TEXT PRIMARY KEY/i', $sql)) {
        echo "Migrating sent_alerts table to support composite primary key (id, user_id)...\n";
        
        $pdo->beginTransaction();
        try {
            // Create new table with composite primary key
            $pdo->exec("CREATE TABLE sent_alerts_new (
                id TEXT NOT NULL,
                type TEXT,
                status TEXT,
                msg_type TEXT,
                category TEXT,
                severity TEXT,
                certainty TEXT,
                urgency TEXT,
                event TEXT,
                headline TEXT,
                description TEXT,
                instruction TEXT,
                area_desc TEXT,
                sent TEXT,
                effective TEXT,
                onset TEXT,
                expires TEXT,
                ends TEXT,
                same_array TEXT NOT NULL,
                ugc_array TEXT NOT NULL,
                json TEXT NOT NULL,
                notified_at TEXT,
                result_status TEXT,
                result_attempts INTEGER NOT NULL DEFAULT 0,
                result_error TEXT,
                pushover_request_id TEXT,
                user_id INTEGER NOT NULL,
                channel TEXT,
                PRIMARY KEY (id, user_id, channel)
            )");
            
            // Copy existing data (set default channel to 'pushover' for existing records)
            // Use -1 as sentinel for unknown user_id (migrated records with NULL user_id)
            $pdo->exec("INSERT INTO sent_alerts_new SELECT 
                id, type, status, msg_type, category, severity, certainty, urgency,
                event, headline, description, instruction, area_desc, sent, effective,
                onset, expires, ends, same_array, ugc_array, json,
                notified_at, result_status, result_attempts, result_error, pushover_request_id,
                COALESCE(user_id, -1), 'pushover'
                FROM sent_alerts");
            
            // Replace old table with new one
            $pdo->exec("DROP TABLE sent_alerts");
            $pdo->exec("ALTER TABLE sent_alerts_new RENAME TO sent_alerts");
            
            // Create indexes
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sent_alerts_user_id ON sent_alerts(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sent_alerts_notified_at ON sent_alerts(notified_at)");
            
            $pdo->commit();
            echo "sent_alerts table migration completed - now supports multiple users and channels per alert\n";
        } catch (Exception $e) {
            $pdo->rollback();
            echo "Error migrating sent_alerts table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

// Load zones data if file exists and table is empty
// Extract filename from zones data URL for local storage
$zonesFileName = basename(parse_url(Config::$zonesDataUrl, PHP_URL_PATH));
$zonesFile = $dir . '/' . $zonesFileName;
if (file_exists($zonesFile)) {
    $count = $pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
    
    // No transformation logic needed - new data format stores both C and Z variants in single record
    
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
                
                // 1. Create both "C" and "Z" variants of STATE_ZONE as comma-separated values
                //    Original: IN040 becomes "INC040,INZ040" (both variants in single field)
                $stateZoneCombined = '';
                if (strlen($stateZone) >= 3) {
                    $stateZoneC = substr($stateZone, 0, 2) . 'C' . substr($stateZone, 2); // C variant
                    $stateZoneZ = substr($stateZone, 0, 2) . 'Z' . substr($stateZone, 2); // Z variant
                    $stateZoneCombined = $stateZoneC . ',' . $stateZoneZ;
                } else {
                    // If STATE_ZONE is too short, keep as-is
                    $stateZoneCombined = $stateZone;
                }
                
                // 2. Add "0" as the first character in FIPS (e.g., 35045 becomes 035045)
                if (!empty($fips) && is_numeric($fips)) {
                    $fips = '0' . $fips;
                }
                
                // 3. Insert a single record with both STATE_ZONE variants
                $stmt = $pdo->prepare(
                    "INSERT OR IGNORE INTO zones (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                $stmt->execute([
                    $fields[0] ?? '', // STATE
                    $fields[1] ?? '', // ZONE
                    $fields[2] ?? '', // CWA
                    $fields[3] ?? '', // NAME
                    $stateZoneCombined, // STATE_ZONE (both C and Z variants combined)
                    $fields[5] ?? '', // COUNTY
                    $fips,           // FIPS (modified)
                    $fields[7] ?? '', // TIME_ZONE
                    $fields[8] ?? '', // FE_AREA
                    !empty($fields[9]) ? (float)$fields[9] : null, // LAT
                    !empty($fields[10]) ? (float)$fields[10] : null  // LON
                ]);
                $loaded++;
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
