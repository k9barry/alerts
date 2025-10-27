<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;

$pdo = Connection::get();

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS user_data (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  email TEXT NOT NULL,
  pushover_token TEXT NOT NULL,
  pushover_user TEXT NOT NULL,
  same_array TEXT NOT NULL, -- JSON array
  ugc_array TEXT NOT NULL,  -- JSON array
  latitude REAL,
  longitude REAL,
  alert_location TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);

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

echo "Migrations applied\n";
