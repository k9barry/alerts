# cleanup_old_sent_alerts.php

## Purpose

Cleanup old `sent_alerts` records to manage database size and maintain optimal performance.

This script provides automatic maintenance for the `sent_alerts` table, which serves as a permanent audit trail of all notification attempts. As this table grows over time, the script removes records older than a specified retention period (default: 30 days) while preserving recent notification history.

## Location

`scripts/cleanup_old_sent_alerts.php`

## Usage

### Basic Usage (Default 30-day Retention)
```bash
php scripts/cleanup_old_sent_alerts.php
```

### Custom Retention Period
```bash
# Delete records older than 90 days
php scripts/cleanup_old_sent_alerts.php 90

# Delete records older than 7 days
php scripts/cleanup_old_sent_alerts.php 7
```

### Docker Usage
```bash
docker exec alerts php scripts/cleanup_old_sent_alerts.php
docker exec alerts php scripts/cleanup_old_sent_alerts.php 90
```

## Automatic Execution

The cleanup script is **automatically executed** by the scheduler during the VACUUM maintenance window:

- **Frequency**: Every 24 hours (configurable via `VACUUM_HOURS` environment variable)
- **Retention**: 30 days (hard-coded in scheduler)
- **Timing**: Runs before VACUUM operation to maximize space recovery
- **Location**: Integrated in `src/Scheduler/ConsoleApp.php`

### Maintenance Sequence

When the scheduler performs maintenance (every 24 hours by default):

1. **Cleanup old sent_alerts** (removes records older than 30 days)
2. **VACUUM database** (reclaims disk space from deleted records)
3. **Rotate user backups** (maintains up to 10 recent backup files)

## What It Does

1. **Count Records**: Determines how many records will be deleted
2. **Delete Old Records**: Removes `sent_alerts` records where `notified_at < cutoff_date`
3. **VACUUM Database**: Reclaims disk space from deleted records
4. **Log Results**: Records operation details in structured logs

### Records Affected

- **Deleted**: Records with `notified_at` older than the retention period
- **Preserved**: Recent records within the retention period
- **Ignored**: Records with `NULL` notified_at (these are not deleted)

## Output

### Success (Records Found)
```
Cleaning sent_alerts records older than 30 days (before 2025-12-18 17:09:04)...
Found 450 records to delete.
Deleted 450 records.
Running VACUUM on database to reclaim space...
Database vacuum complete.
Cleanup successful.
```

### Success (No Records)
```
No records older than 30 days found. Nothing to cleanup.
```

### Error
```
Error: SQLSTATE[HY000]: General error: 1 no such table: sent_alerts
```

## Logging

All operations are logged to the application log with structured context:

### Info Logs
```json
{
  "message": "Starting cleanup of old sent_alerts records",
  "context": {
    "retention_days": 30,
    "cutoff_date": "2025-12-18 17:09:04",
    "records_to_delete": 450
  }
}
```

### Error Logs
```json
{
  "message": "Cleanup failed",
  "context": {
    "error": "SQLSTATE[HY000]: General error",
    "trace": "..."
  }
}
```

## Database Impact

### Before Cleanup
- `sent_alerts` table contains all historical notification records
- Database file size continues to grow
- Query performance may degrade with very large tables

### After Cleanup
- Old records (>30 days) are removed
- VACUUM operation compacts the database file
- Disk space is reclaimed
- Recent notification history is preserved

## Retention Period Considerations

### 30 Days (Default)
- **Pros**: Balances audit trail with database size
- **Cons**: May be too short for some compliance requirements
- **Use Case**: Personal use, general monitoring

### 90 Days
- **Pros**: Longer audit trail, better for troubleshooting
- **Cons**: Larger database size
- **Use Case**: Organizations with 90-day retention policies

### Custom Period
- Set based on your specific requirements
- Consider compliance, storage capacity, and query performance
- Can be adjusted per manual execution

## Transaction Safety

The script uses database transactions to ensure atomicity:

```php
$db->beginTransaction();
$stmt = $db->prepare('DELETE FROM sent_alerts WHERE notified_at < :cutoff');
$stmt->execute([':cutoff' => $cutoffDate]);
$deletedCount = $stmt->rowCount();
$db->commit();
```

If any error occurs during deletion:
- Transaction is rolled back
- No records are deleted
- Database remains in consistent state

## Performance

### Execution Time
- **Small database** (<1000 records): < 1 second
- **Medium database** (1000-10000 records): 1-5 seconds
- **Large database** (>10000 records): 5-30 seconds

### VACUUM Impact
- Briefly locks the database
- Other operations wait during VACUUM
- WAL mode minimizes impact on readers

## Monitoring

### Check Last Cleanup
```sql
-- Check when cleanup last ran (look for log entries)
SELECT * FROM logs WHERE message LIKE '%cleanup%' ORDER BY datetime DESC LIMIT 10;
```

### Estimate Records to Delete
```sql
-- Count records older than 30 days
SELECT COUNT(*) FROM sent_alerts 
WHERE notified_at < date('now', '-30 days');
```

### Check Database Size
```bash
# Linux/macOS
ls -lh data/alerts.sqlite

# Or via SQL
SELECT page_count * page_size / 1024.0 / 1024.0 as size_mb 
FROM pragma_page_count(), pragma_page_size();
```

## Troubleshooting

### "No such table: sent_alerts"
**Cause**: Database migrations haven't been run
**Solution**: Run migrations first
```bash
php scripts/migrate.php
```

### Cleanup Takes Too Long
**Cause**: Large number of old records
**Solution**: 
1. Run cleanup manually during low-traffic period
2. Consider shorter retention period
3. Add index on `notified_at` column

### Database Locked Error
**Cause**: Another process is using the database
**Solution**:
1. Ensure only one scheduler instance is running
2. Stop other database operations during maintenance
3. WAL mode should prevent most locking issues

## Related Scripts

- **`remove_sent_alerts.php`**: Deletes ALL sent_alerts records (use with caution)
- **`migrate.php`**: Creates/updates database schema
- **`scheduler.php`**: Runs continuous polling loop with automatic cleanup

## See Also

- [Database Schema Documentation](../overview/DATABASE.md)
- [Scheduler Documentation](scheduler.md)
- [Migration Documentation](migrate.md)
