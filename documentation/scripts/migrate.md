# migrate.php

Database migration script that initializes and updates the SQLite schema.

## Location

`scripts/migrate.php`

## Purpose

Creates and maintains the database schema for the Alerts application. Ensures all required tables exist with the correct structure, and adds any missing columns to existing tables.

## Execution

### Manual
```sh
php scripts/migrate.php
```

### Automatic
- **Composer hooks**: Runs after `composer install` and `composer update`
- **Docker startup**: Runs in container entrypoint before scheduler starts

## What It Does

### 1. Environment Setup
- Loads `src/bootstrap.php` (environment, config, logger)
- Validates database directory exists and is writable
- Creates directory if missing

### 2. Database Connection
- Obtains PDO connection via `DB\Connection::get()`
- Enables WAL mode and foreign keys

### 3. Schema Definition

Defines common alert columns shared across all tables:
- **id**: TEXT PRIMARY KEY (alert URL/ID)
- **type**, **status**, **msg_type**, **category**: Classification
- **severity**, **certainty**, **urgency**: Priority fields
- **event**, **headline**, **description**, **instruction**, **area_desc**: Content
- **sent**, **effective**, **onset**, **expires**, **ends**: Timestamps
- **same_array**, **ugc_array**: JSON arrays for geographic codes
- **json**: Full GeoJSON feature

Defines table-specific columns:
- **incoming_alerts**: `received_at` timestamp
- **active_alerts**: `updated_at` timestamp
- **pending_alerts**: `created_at` timestamp
- **sent_alerts**: Notification metadata (notified_at, result_status, result_attempts, result_error, pushover_request_id, user_id)

### 4. Table Creation

For each table:
```sql
CREATE TABLE IF NOT EXISTS table_name (
    id TEXT PRIMARY KEY,
    -- ... all common columns ...
    -- ... table-specific columns ...
);
```

### 5. Column Addition

For existing tables, adds missing columns:
1. Query `PRAGMA table_info('table_name')`
2. Compare against expected columns
3. Execute `ALTER TABLE table_name ADD COLUMN ...` for missing columns

This allows migrations to be run multiple times safely (idempotent).

### 6. Validation

After creation:
- Lists all tables in database
- Compares against expected tables: `active_alerts`, `incoming_alerts`, `pending_alerts`, `sent_alerts`
- Reports missing or unexpected tables

## Output

### Success
```
Migrations applied
Tables present (4): active_alerts, incoming_alerts, pending_alerts, sent_alerts
```

### With Unexpected Tables
```
Migrations applied
Tables present (5): active_alerts, incoming_alerts, pending_alerts, sent_alerts, user_data
Note: Additional tables found: user_data
```

### Failure
```
Failed to create database directory: /data
```
(Exits with code 1)

## Idempotency

The script is safe to run multiple times:
- `CREATE TABLE IF NOT EXISTS` doesn't error if table exists
- Column addition checks existence first via `PRAGMA table_info`
- No destructive operations (no DROP, no DELETE)

## Adding Columns

To add a new column to all alert tables:

1. Edit `scripts/migrate.php`
2. Add to `$alertColumns` array:
```php
$alertColumns = [
    // existing columns...
    "new_column TEXT",
];
```
3. Run migration: `php scripts/migrate.php`

To add a table-specific column:

1. Edit `$tablesToEnsure` array:
```php
$tablesToEnsure = [
    'sent_alerts' => 'notified_at TEXT, new_column INTEGER',
];
```
2. Run migration

## Error Handling

### Directory Not Writable
```
Database directory is not writable: /data
```
**Solution**: `chmod 775 /data` or run as appropriate user

### SQLite Extension Missing
```
Fatal error: Uncaught Error: Class 'PDO' not found
```
**Solution**: Install PHP PDO extension: `apt-get install php-sqlite3`

### Permission Denied
```
SQLSTATE[HY000]: General error: 14 unable to open database file
```
**Solution**: Ensure PHP process has write permission to database directory

## Configuration

Uses `Config::$dbPath` from environment:
- Default: `/data/alerts.sqlite` (Docker) or `data/alerts.sqlite` (host)
- Override: Set `DB_PATH` environment variable

## Database Files Created

After successful migration:
- `alerts.sqlite` - Main database file
- `alerts.sqlite-wal` - Write-Ahead Log (WAL mode)
- `alerts.sqlite-shm` - Shared memory file (WAL mode)

## Technical Details

### SQLite Features Used
- **WAL Mode**: `PRAGMA journal_mode=WAL`
  - Better concurrency
  - Readers don't block writers
- **Foreign Keys**: `PRAGMA foreign_keys=ON`
  - Referential integrity (not currently used but enabled for future)
- **Error Mode**: `PDO::ERRMODE_EXCEPTION`
  - Exceptions on SQL errors

### Transaction Safety
Table creation and column addition happen in implicit transactions (SQLite DDL is transactional).

## Dependencies

- PHP 8.1+
- PDO extension
- SQLite3
- `src/bootstrap.php` (Config, DB\Connection)

## Future Enhancements

Potential improvements:
- **Migration History**: Track which migrations have run
- **Rollback Support**: Ability to undo migrations
- **Seed Data**: Optionally insert test data
- **Schema Versioning**: Version number in database
- **Backup Before Migration**: Automatic backup of database before changes
