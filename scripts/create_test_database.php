<?php
/**
 * Create Test Database Script
 *
 * This script creates a sanitized test database from the production database.
 * It copies all tables and data, but sanitizes sensitive user information:
 * - Replaces user names with generic "Test User N"
 * - Replaces emails with "testuser{N}@example.com"
 * - Replaces Pushover/Ntfy credentials with placeholder values
 * - Preserves zone alerts and timezone settings for testing
 * - Copies all zones, alerts, and other non-sensitive data as-is
 *
 * Usage: php scripts/create_test_database.php
 *
 * @package Alerts\Scripts
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use PDO;

echo "=== Test Database Creation Script ===\n\n";

// Determine paths
$productionDbPath = Config::$dbPath;
$testDbPath = str_replace('.sqlite', '_test.sqlite', $productionDbPath);

// Ensure production database exists
if (!file_exists($productionDbPath)) {
  fwrite(STDERR, "Error: Production database not found at: {$productionDbPath}\n");
  fwrite(STDERR, "Please run migrations first: php scripts/migrate.php\n");
  exit(1);
}

echo "Production DB: {$productionDbPath}\n";
echo "Test DB:       {$testDbPath}\n\n";

// Confirm overwrite if test database already exists
if (file_exists($testDbPath)) {
  echo "Warning: Test database already exists and will be overwritten.\n";
  echo "Continue? (yes/no): ";
  $handle = fopen("php://stdin", "r");
  $line = trim(fgets($handle));
  fclose($handle);

  if (strtolower($line) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
  }

  // Remove existing test database
  if (!unlink($testDbPath)) {
    fwrite(STDERR, "Error: Failed to remove existing test database\n");
    exit(1);
  }
  echo "Removed existing test database.\n\n";
}

try {
  // Connect to production database (read-only)
  $prodPdo = new PDO("sqlite:{$productionDbPath}", null, null, [
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);

  // Create new test database
  $testPdo = new PDO("sqlite:{$testDbPath}");
  $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  echo "Step 1: Copying database schema...\n";

  // Get all table creation SQL from production database
  $tables = $prodPdo->query(
    "SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
  )->fetchAll(PDO::FETCH_ASSOC);

  foreach ($tables as $table) {
    $testPdo->exec($table['sql']);
    echo "  - Created table: {$table['name']}\n";
  }

  // Copy indexes
  $indexes = $prodPdo->query(
    "SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'"
  )->fetchAll(PDO::FETCH_COLUMN);

  foreach ($indexes as $indexSql) {
    $testPdo->exec($indexSql);
  }
  echo "  - Copied indexes\n\n";

  // Copy non-sensitive tables as-is
  echo "Step 2: Copying non-sensitive data...\n";

  $nonSensitiveTables = ['zones', 'incoming_alerts', 'active_alerts', 'pending_alerts'];

  foreach ($nonSensitiveTables as $tableName) {
    // Check if table exists in production
    $exists = $prodPdo->query(
      "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$tableName}'"
    )->fetchColumn();

    if (!$exists) {
      echo "  - Skipping {$tableName} (not found)\n";
      continue;
    }

    $rows = $prodPdo->query("SELECT * FROM {$tableName}")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
      echo "  - {$tableName}: 0 rows (empty)\n";
      continue;
    }

    // Get column names from first row
    $columns = array_keys($rows[0]);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $columnList = implode(',', $columns);

    $stmt = $testPdo->prepare("INSERT INTO {$tableName} ({$columnList}) VALUES ({$placeholders})");

    $testPdo->beginTransaction();
    foreach ($rows as $row) {
      $stmt->execute(array_values($row));
    }
    $testPdo->commit();

    echo "  - {$tableName}: " . count($rows) . " rows copied\n";
  }

  echo "\nStep 3: Sanitizing and copying users table...\n";

  $users = $prodPdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);

  if (empty($users)) {
    echo "  - No users found in production database\n";
  } else {
    $stmt = $testPdo->prepare("INSERT INTO users 
            (idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, 
             NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert, CreatedAt, UpdatedAt) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $testPdo->beginTransaction();

    foreach ($users as $index => $user) {
      $userNum = $index + 1;

      // Sanitize user data
      $sanitizedUser = [
        $user['idx'],                                    // Keep original ID for referential integrity
        "Test",                                          // FirstName
        "User{$userNum}",                               // LastName
        "testuser{$userNum}@example.com",               // Email
        $user['Timezone'] ?? 'America/New_York',        // Keep timezone for testing
        "uTestUser{$userNum}",                          // PushoverUser (placeholder)
        "aTestToken{$userNum}",                         // PushoverToken (placeholder)
        "ntfy_test_user{$userNum}",                     // NtfyUser (placeholder)
        "test_password_{$userNum}",                     // NtfyPassword (placeholder)
        "test_token_{$userNum}",                        // NtfyToken (placeholder)
        "test_topic_{$userNum}",                        // NtfyTopic (placeholder)
        $user['ZoneAlert'] ?? '[]',                     // Keep zone alerts for testing
        $user['CreatedAt'] ?? null,
        $user['UpdatedAt'] ?? null
      ];

      $stmt->execute($sanitizedUser);
    }

    $testPdo->commit();
    echo "  - Sanitized and copied " . count($users) . " users\n";
    echo "    * Names: Test User1, Test User2, ...\n";
    echo "    * Emails: testuser1@example.com, testuser2@example.com, ...\n";
    echo "    * Credentials: Replaced with test placeholders\n";
    echo "    * Zone alerts: Preserved for testing\n";
  }

  echo "\nStep 4: Sanitizing and copying sent_alerts table...\n";

  // Check if sent_alerts table exists
  $sentAlertsExists = $prodPdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='sent_alerts'"
  )->fetchColumn();

  if (!$sentAlertsExists) {
    echo "  - sent_alerts table not found (skipping)\n";
  } else {
    $sentAlerts = $prodPdo->query("SELECT * FROM sent_alerts")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sentAlerts)) {
      echo "  - No sent alerts found\n";
    } else {
      // Get column names dynamically to handle schema variations
      $columns = array_keys($sentAlerts[0]);
      $placeholders = implode(',', array_fill(0, count($columns), '?'));
      $columnList = implode(',', $columns);

      $stmt = $testPdo->prepare("INSERT INTO sent_alerts ({$columnList}) VALUES ({$placeholders})");

      $testPdo->beginTransaction();

      foreach ($sentAlerts as $alert) {
        // Sanitize: remove Pushover request IDs (they're production-specific)
        if (isset($alert['pushover_request_id'])) {
          $alert['pushover_request_id'] = null;
        }

        $stmt->execute(array_values($alert));
      }

      $testPdo->commit();
      echo "  - Copied " . count($sentAlerts) . " sent alerts (sanitized request IDs)\n";
    }
  }

  // Verify test database
  echo "\nStep 5: Verifying test database...\n";

  $testTables = $testPdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
  )->fetchAll(PDO::FETCH_COLUMN);

  echo "  - Tables in test database: " . implode(', ', $testTables) . "\n";

  foreach ($testTables as $tableName) {
    $count = $testPdo->query("SELECT COUNT(*) FROM {$tableName}")->fetchColumn();
    echo "  - {$tableName}: {$count} rows\n";
  }

  // Set file permissions (read/write for owner only)
  chmod($testDbPath, 0600);

  echo "\n=== Test Database Created Successfully ===\n";
  echo "Location: {$testDbPath}\n";
  echo "\nIMPORTANT: This database contains sanitized test data.\n";
  echo "All user credentials have been replaced with placeholders.\n";
  echo "Zone alerts and other test data have been preserved.\n\n";

} catch (Exception $e) {
  fwrite(STDERR, "\nError: " . $e->getMessage() . "\n");
  fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
  exit(1);
}
