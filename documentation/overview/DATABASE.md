# Database Schema

The Alerts application uses SQLite as its database engine with four main tables for tracking weather alerts through their lifecycle.

## Database Technology

### SQLite Configuration
- **Engine**: SQLite 3
- **Journal Mode**: WAL (Write-Ahead Logging)
- **Foreign Keys**: Enabled
- **Error Mode**: Exception
- **Connection**: Singleton pattern via `DB\Connection`

### WAL Mode Benefits
- Better concurrency (readers don't block writers)
- Better performance for most workloads
- Atomic commits
- Creates additional files: `.sqlite-wal` and `.sqlite-shm`

## Table Overview

The application uses four tables to track alert lifecycle:

```
┌─────────────────┐
│ incoming_alerts │ ← Latest API snapshot
└────────┬────────┘
         │ diff
         ↓
┌────────────────┐
│ active_alerts  │ ← Currently tracked
└────────────────┘
         │
         │ new = incoming - active
         ↓
┌────────────────┐
│ pending_alerts │ ← Queued for notification
└────────┬───────┘
         │ process
         ↓
┌───────────────┐
│ sent_alerts   │ ← Permanent history
└───────────────┘
```

## Common Alert Columns

All four tables share these core columns (from weather.gov alert properties):

### Identity
- **id** (TEXT PRIMARY KEY): Unique alert identifier (usually a URL)

### Classification
- **type** (TEXT): GeoJSON type (usually "Feature")
- **status** (TEXT): Alert status (Actual, Exercise, System, Test, Draft)
- **msg_type** (TEXT): Message type (Alert, Update, Cancel, Ack, Error)
- **category** (TEXT): Event category (Met, Geo, Safety, Security, Rescue, Fire, Health, Env, Transport, Infra, CBRNE, Other)

### Severity and Urgency
- **severity** (TEXT): Severity level (Extreme, Severe, Moderate, Minor, Unknown)
- **certainty** (TEXT): Certainty level (Observed, Likely, Possible, Unlikely, Unknown)
- **urgency** (TEXT): Urgency level (Immediate, Expected, Future, Past, Unknown)

### Description
- **event** (TEXT): Event type (e.g., "Tornado Warning", "Flood Watch")
- **headline** (TEXT): Brief headline
- **description** (TEXT): Detailed description
- **instruction** (TEXT): Recommended actions

### Geography
- **area_desc** (TEXT): Human-readable area description
- **same_array** (TEXT): JSON array of SAME codes (6-digit FIPS codes)
- **ugc_array** (TEXT): JSON array of UGC zone/county codes

### Timing
- **sent** (TEXT): ISO 8601 timestamp when alert was sent
- **effective** (TEXT): ISO 8601 timestamp when alert becomes effective
- **onset** (TEXT): ISO 8601 timestamp when event begins
- **expires** (TEXT): ISO 8601 timestamp when alert expires
- **ends** (TEXT): ISO 8601 timestamp when event ends

### Complete Data
- **json** (TEXT NOT NULL): Full GeoJSON feature as JSON string

## Table-Specific Details

### incoming_alerts

**Purpose**: Temporary snapshot of the latest API fetch

**Additional Columns**:
- **received_at** (TEXT DEFAULT CURRENT_TIMESTAMP): When this snapshot was received

**Characteristics**:
- Completely replaced on each API fetch (DELETE + INSERT)
- Empty only if API returns no alerts or fetch fails
- Preserved if API returns 304 Not Modified or 0 features

**Queries**:
```sql
-- View all incoming alerts
SELECT id, event, severity, area_desc FROM incoming_alerts;

-- Count incoming alerts
SELECT COUNT(*) FROM incoming_alerts;

-- Find specific alert
SELECT * FROM incoming_alerts WHERE id = ?;
```

### active_alerts

**Purpose**: Currently tracked alerts (baseline for diff)

**Additional Columns**:
- **updated_at** (TEXT DEFAULT CURRENT_TIMESTAMP): Last update timestamp

**Characteristics**:
- Replaced with incoming_alerts after each poll cycle
- Represents the "known" state
- Used for diffing to identify new alerts

**Lifecycle**:
1. Initially empty
2. After first poll: Populated with incoming alerts
3. On subsequent polls: Replaced with incoming after processing

**Queries**:
```sql
-- View all active alerts
SELECT id, event, severity, area_desc FROM active_alerts;

-- Find alerts not in incoming (expired)
SELECT id FROM active_alerts 
WHERE id NOT IN (SELECT id FROM incoming_alerts);

-- Count active alerts
SELECT COUNT(*) FROM active_alerts;
```

### pending_alerts

**Purpose**: Queue of new alerts waiting for notification

**Additional Columns**:
- **created_at** (TEXT DEFAULT CURRENT_TIMESTAMP): When queued

**Characteristics**:
- Populated by `queuePendingForNew()` with alerts in incoming but not active
- Processed sequentially
- Removed after notification attempt (success or failure)
- Uses INSERT OR IGNORE to prevent duplicates

**Lifecycle**:
1. New alert identified → INSERT into pending_alerts
2. Geographic filter applied
3. Notification sent
4. DELETE from pending_alerts (always, even on failure)

**Queries**:
```sql
-- View pending notifications
SELECT id, event, severity, area_desc FROM pending_alerts;

-- Count pending
SELECT COUNT(*) FROM pending_alerts;

-- View oldest pending
SELECT * FROM pending_alerts 
ORDER BY created_at ASC LIMIT 1;
```

### sent_alerts

**Purpose**: Permanent record of all notification attempts

**Additional Columns**:
- **notified_at** (TEXT): Timestamp when notification was sent
- **result_status** (TEXT): Outcome status (success, failure, processed)
- **result_attempts** (INTEGER NOT NULL DEFAULT 0): Number of retry attempts
- **result_error** (TEXT): Error message if failed
- **pushover_request_id** (TEXT): Pushover API request ID
- **user_id** (INTEGER): Reserved for future multi-user support

**Characteristics**:
- Never deleted (permanent audit trail)
- One record per alert ID (INSERT OR REPLACE)
- Contains full alert data plus notification metadata
- Grows over time (periodic cleanup may be needed)

**Queries**:
```sql
-- View recent notifications
SELECT id, event, result_status, notified_at 
FROM sent_alerts 
ORDER BY notified_at DESC LIMIT 10;

-- Count successful notifications
SELECT COUNT(*) FROM sent_alerts 
WHERE result_status = 'success';

-- Find failed notifications
SELECT id, event, result_error 
FROM sent_alerts 
WHERE result_status = 'failure';

-- Notification statistics
SELECT result_status, COUNT(*) as count 
FROM sent_alerts 
GROUP BY result_status;

-- Alerts sent today
SELECT * FROM sent_alerts 
WHERE DATE(notified_at) = DATE('now');
```

## Data Types and Storage

### TEXT Fields
- All string data stored as TEXT (SQLite's flexible string type)
- UTF-8 encoding
- No maximum length (SQLite supports up to ~1GB per field)

### JSON Storage
- `same_array` and `ugc_array` stored as JSON strings
- `json` field contains complete GeoJSON feature
- Queried using `json_decode()` in PHP (not SQLite JSON functions)

### Timestamps
- Stored as ISO 8601 strings (e.g., "2025-10-30T14:30:00Z")
- CURRENT_TIMESTAMP produces format: "YYYY-MM-DD HH:MM:SS"
- PHP handles parsing with `DateTimeImmutable`

## Indexes

### Primary Keys
- All tables: PRIMARY KEY on `id` (TEXT)
- Automatically indexed by SQLite
- Ensures uniqueness

### Additional Indexes
Currently none, but potential additions:
- `CREATE INDEX idx_sent_notified ON sent_alerts(notified_at)`
- `CREATE INDEX idx_pending_created ON pending_alerts(created_at)`

## Database Migrations

### Migration Script
Location: `scripts/migrate.php`

**Features**:
- Idempotent (safe to run multiple times)
- Creates tables if not exist
- Adds missing columns to existing tables
- Validates table structure
- Reports missing/unexpected tables

**Automatic Execution**:
- Runs on `composer install` (post-install-cmd hook)
- Runs on Docker container start (entrypoint.sh)
- Can be run manually: `php scripts/migrate.php`

**Migration Strategy**:
```php
// Create table if not exists
CREATE TABLE IF NOT EXISTS table_name (...)

// Add column if not exists (SQLite doesn't support IF NOT EXISTS for columns)
PRAGMA table_info('table_name')
ALTER TABLE table_name ADD COLUMN new_column TYPE
```

### Schema Evolution
To add a new column:
1. Edit `scripts/migrate.php`
2. Add to `$alertColumns` (for all tables) or table-specific extras
3. Run migration
4. Update application code to use new column

## Database Operations

### Transactions
All multi-statement operations wrapped in transactions:
```php
$this->db->beginTransaction();
try {
    // Multiple operations
    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}
```

### Prepared Statements
All queries use prepared statements:
```php
$stmt = $this->db->prepare('SELECT * FROM alerts WHERE id = :id');
$stmt->execute([':id' => $alertId]);
```

### Bulk Operations
Efficient bulk inserts:
```php
$stmt = $this->db->prepare('INSERT INTO ...');
foreach ($rows as $row) {
    $stmt->execute($row);
}
```

## Database Maintenance

### VACUUM
- **Purpose**: Reclaim space from deleted records, optimize database
- **Frequency**: Every 24 hours (configurable via `VACUUM_HOURS`)
- **Execution**: Automatic during scheduler loop
- **Manual**: `php scripts/migrate.php` or SQL: `VACUUM;`
- **Impact**: Temporarily locks database during operation

### Backup
```sh
# SQLite built-in backup
sqlite3 data/alerts.sqlite ".backup data/alerts_backup.sqlite"

# Or simple file copy (only when app is stopped)
cp data/alerts.sqlite data/alerts_backup.sqlite
```

### Size Management
- `sent_alerts` grows indefinitely
- Consider periodic cleanup of old records:
  ```sql
  DELETE FROM sent_alerts 
  WHERE notified_at < date('now', '-90 days');
  
  VACUUM;
  ```

## Database Queries for Monitoring

### Current State
```sql
-- Counts for all tables
SELECT 'incoming' as table_name, COUNT(*) as count FROM incoming_alerts
UNION ALL
SELECT 'active', COUNT(*) FROM active_alerts
UNION ALL
SELECT 'pending', COUNT(*) FROM pending_alerts
UNION ALL
SELECT 'sent', COUNT(*) FROM sent_alerts;
```

### Recent Activity
```sql
-- Last 10 notifications
SELECT 
    notified_at, 
    event, 
    severity, 
    result_status 
FROM sent_alerts 
ORDER BY notified_at DESC 
LIMIT 10;
```

### Alert Types
```sql
-- Count by event type
SELECT event, COUNT(*) as count 
FROM sent_alerts 
GROUP BY event 
ORDER BY count DESC;
```

### Notification Success Rate
```sql
-- Success vs failure
SELECT 
    result_status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM sent_alerts), 2) as percentage
FROM sent_alerts
GROUP BY result_status;
```

## Database File Locations

### Default Paths
- **In Docker**: `/data/alerts.sqlite`
- **On Host**: `./data/alerts.sqlite`

### Related Files
- **alerts.sqlite**: Main database file
- **alerts.sqlite-wal**: Write-ahead log (WAL mode)
- **alerts.sqlite-shm**: Shared memory file (WAL mode)

### Permissions
- Database directory must be writable
- Database file must be writable
- WAL and SHM files created automatically

## Troubleshooting

### Database Locked
**Cause**: Multiple writers or long transaction
**Solution**:
- Ensure only one scheduler instance running
- Check for zombie processes
- Restart application
- WAL mode helps prevent this

### Disk Full
**Cause**: sent_alerts growing too large, WAL not checkpointing
**Solution**:
- Run VACUUM
- Delete old sent_alerts records
- Increase disk space
- Force WAL checkpoint: `PRAGMA wal_checkpoint(FULL);`

### Corruption
**Cause**: Power loss, disk failure, improper shutdown
**Solution**:
- Restore from backup
- Try SQLite integrity check: `PRAGMA integrity_check;`
- Rebuild: export data, recreate database

### Missing Tables
**Cause**: Migrations not run
**Solution**: `php scripts/migrate.php`

## Performance Considerations

### Current Scale
- Designed for personal/small team use
- Handles dozens of alerts per day
- Thousands of historical records
- Single geographic region

### Optimization Strategies
1. **Indexes**: Add for frequently queried columns
2. **Archiving**: Move old sent_alerts to archive table
3. **Batch Processing**: Already implemented for inserts
4. **WAL Mode**: Already enabled
5. **VACUUM**: Regular maintenance

### When to Consider Other Databases
- **PostgreSQL**: Multi-user, high concurrency
- **MySQL/MariaDB**: Cross-platform replication
- **TimescaleDB**: Time-series analysis

## Future Enhancements

Potential schema additions:
- User preferences table (geographic filters per user)
- Notification log table (separate from alerts)
- Alert acknowledgment tracking
- Alert comments/notes
- Scheduled maintenance windows (silence periods)
- Alert grouping/deduplication metadata
