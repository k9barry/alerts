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

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS incoming_alerts (
  id TEXT PRIMARY KEY, -- id from API
  json TEXT NOT NULL,
  same_array TEXT NOT NULL,
  ugc_array TEXT NOT NULL,
  received_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS active_alerts (
  id TEXT PRIMARY KEY,
  json TEXT NOT NULL,
  same_array TEXT NOT NULL,
  ugc_array TEXT NOT NULL,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pending_alerts (
  id TEXT PRIMARY KEY,
  json TEXT NOT NULL,
  same_array TEXT NOT NULL,
  ugc_array TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS sent_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  alert_id TEXT NOT NULL,
  user_id INTEGER,
  json TEXT NOT NULL,
  send_status TEXT NOT NULL,
  send_attempts INTEGER NOT NULL DEFAULT 0,
  sent_at TEXT,
  error_message TEXT
);
SQL);

// Verify and summarize created tables
$tables = $pdo->query(
    "SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$expected = ['active_alerts','incoming_alerts','pending_alerts','sent_alerts'];
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
