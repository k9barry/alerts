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
$zonesFile = $dir . '/bp18mr25.dbx';
if (file_exists($zonesFile)) {
    $count = $pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
    if ($count == 0) {
        echo "Loading zones data from bp18mr25.dbx...\n";
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
                $stmt = $pdo->prepare(
                    "INSERT OR IGNORE INTO zones (STATE, ZONE, CWA, NAME, STATE_ZONE, COUNTY, FIPS, TIME_ZONE, FE_AREA, LAT, LON) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $fields[0] ?? '', // STATE
                    $fields[1] ?? '', // ZONE
                    $fields[2] ?? '', // CWA
                    $fields[3] ?? '', // NAME
                    $fields[4] ?? '', // STATE_ZONE
                    $fields[5] ?? '', // COUNTY
                    $fields[6] ?? '', // FIPS
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
