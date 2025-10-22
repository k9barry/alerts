# Database Schema Documentation

## Overview

The SQLite database consists of three main tables for storing weather alerts, affected zones, and API call tracking.

## Entity Relationship Diagram

```
┌─────────────────┐
│     alerts      │
│─────────────────│
│ id (PK)        │◄──┐
│ type            │   │
│ geometry_type   │   │
│ area_desc       │   │
│ sent            │   │
│ effective       │   │
│ onset           │   │
│ expires         │   │
│ ends            │   │
│ status          │   │
│ message_type    │   │
│ category        │   │
│ severity        │   │
│ certainty       │   │
│ urgency         │   │
│ event           │   │
│ sender          │   │
│ sender_name     │   │
│ headline        │   │
│ description     │   │
│ instruction     │   │
│ response        │   │
│ parameters      │   │
│ raw_json        │   │
│ created_at      │   │
│ updated_at      │   │
└─────────────────┘   │
                      │
                      │
        ┌─────────────────┐
        │  alert_zones    │
        │─────────────────│
        │ id (PK)        │
        │ alert_id (FK)  ├──┘
        │ zone_id        │
        └─────────────────┘

┌─────────────────┐
│   api_calls     │
│─────────────────│
│ id (PK)        │
│ called_at      │
│ success        │
│ alert_count    │
│ error_message  │
└─────────────────┘
```

---

## Table: alerts

Main table storing weather alert information from the NWS API.

### Columns

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| **id** | TEXT | No | Primary key - Unique alert identifier from API (e.g., 'urn:oid:2.49.0.1.840.0.xxx') |
| type | TEXT | Yes | GeoJSON type (usually 'Feature') |
| geometry_type | TEXT | Yes | Geometry type (Polygon, Point, MultiPolygon, etc.) |
| area_desc | TEXT | Yes | Human-readable description of affected area |
| sent | DATETIME | Yes | ISO 8601 timestamp when alert was sent |
| effective | DATETIME | Yes | When alert becomes effective |
| onset | DATETIME | Yes | Expected onset time of event |
| expires | DATETIME | Yes | When alert expires |
| ends | DATETIME | Yes | Expected end time of event |
| status | TEXT | Yes | Alert status (Actual, Test, System, Draft) |
| message_type | TEXT | Yes | Message type (Alert, Update, Cancel, Ack, Error) |
| category | TEXT | Yes | Event category (Met, Geo, Safety, Security, etc.) |
| severity | TEXT | Yes | Severity level (Extreme, Severe, Moderate, Minor, Unknown) |
| certainty | TEXT | Yes | Certainty level (Observed, Likely, Possible, Unlikely, Unknown) |
| urgency | TEXT | Yes | Response urgency (Immediate, Expected, Future, Past, Unknown) |
| event | TEXT | Yes | Event type (e.g., 'Winter Storm Warning', 'Tornado Watch') |
| sender | TEXT | Yes | Sender identifier |
| sender_name | TEXT | Yes | Human-readable sender name |
| headline | TEXT | Yes | Brief headline of alert |
| description | TEXT | Yes | Detailed description of event |
| instruction | TEXT | Yes | Safety instructions |
| response | TEXT | Yes | Recommended response (Shelter, Evacuate, Prepare, etc.) |
| parameters | TEXT | Yes | Additional parameters as JSON |
| raw_json | TEXT | Yes | Complete raw API response as JSON |
| created_at | DATETIME | No | First insert timestamp (auto-set) |
| updated_at | DATETIME | No | Last update timestamp (auto-set on each update) |

### Indexes

- `idx_alerts_event`: Index on `event` column for fast event type lookups
- `idx_alerts_severity`: Index on `severity` for filtering by severity
- `idx_alerts_expires`: Index on `expires` for finding active/expired alerts
- `idx_alerts_sent`: Index on `sent` for chronological queries

### Example Data

```sql
INSERT INTO alerts (id, event, severity, headline, sent, expires, area_desc)
VALUES (
    'urn:oid:2.49.0.1.840.0.12345',
    'Winter Storm Warning',
    'Severe',
    'Winter Storm Warning issued for Northern Colorado',
    '2024-01-15T10:00:00-07:00',
    '2024-01-16T18:00:00-07:00',
    'Northern Colorado; Denver Metro'
);
```

---

## Table: alert_zones

Junction table storing affected geographic zones for each alert.

### Columns

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| **id** | INTEGER | No | Primary key - Auto-incrementing |
| alert_id | TEXT | No | Foreign key to alerts.id |
| zone_id | TEXT | No | Zone identifier (extracted from zone URL) |

### Constraints

- Foreign key: `alert_id` references `alerts(id)` with CASCADE delete
- Unique constraint: `(alert_id, zone_id)` prevents duplicate zone entries

### Indexes

- `idx_zones_alert_id`: Index on `alert_id` for fast zone lookups by alert

### Example Data

```sql
INSERT INTO alert_zones (alert_id, zone_id)
VALUES 
    ('urn:oid:2.49.0.1.840.0.12345', 'COZ040'),
    ('urn:oid:2.49.0.1.840.0.12345', 'COZ041'),
    ('urn:oid:2.49.0.1.840.0.12345', 'COZ042');
```

---

## Table: api_calls

Tracks API calls for rate limiting and monitoring.

### Columns

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| **id** | INTEGER | No | Primary key - Auto-incrementing |
| called_at | DATETIME | No | Timestamp of API call (auto-set) |
| success | INTEGER | No | 1 if successful, 0 if failed (default: 1) |
| alert_count | INTEGER | No | Number of alerts received (default: 0) |
| error_message | TEXT | Yes | Error message if call failed |

### Indexes

- `idx_api_calls_time`: Index on `called_at` for time-based queries

### Example Data

```sql
-- Successful call
INSERT INTO api_calls (success, alert_count)
VALUES (1, 25);

-- Failed call
INSERT INTO api_calls (success, alert_count, error_message)
VALUES (0, 0, 'HTTP 503 Service Unavailable');
```

---

## Data Types

### DATETIME Format

All datetime fields use ISO 8601 format:
```
YYYY-MM-DDTHH:MM:SS±HH:MM
```

Example: `2024-01-15T10:30:00-07:00`

### JSON Storage

JSON data is stored as TEXT:
- `parameters`: Additional alert parameters
- `raw_json`: Complete API response

Example:
```json
{
  "VTEC": ["/O.NEW.KBOU.WS.W.0001.240115T1700Z-240116T0100Z/"],
  "PIL": "BOUWS",
  "BLOCKCHANNEL": ["EAS", "NWEM", "CMAS"]
}
```

---

## Common Queries

### Find Active Alerts

```sql
SELECT id, event, severity, headline, expires
FROM alerts
WHERE datetime(expires) > datetime('now')
ORDER BY severity DESC, sent DESC;
```

### Count Alerts by Event Type

```sql
SELECT event, COUNT(*) as count
FROM alerts
GROUP BY event
ORDER BY count DESC;
```

### Find Severe Weather Alerts

```sql
SELECT event, severity, headline, area_desc
FROM alerts
WHERE severity IN ('Extreme', 'Severe')
  AND datetime(expires) > datetime('now')
ORDER BY sent DESC;
```

### Get Alert with Zones

```sql
SELECT a.id, a.event, a.headline, GROUP_CONCAT(z.zone_id) as zones
FROM alerts a
LEFT JOIN alert_zones z ON a.id = z.alert_id
WHERE a.id = 'urn:oid:2.49.0.1.840.0.12345'
GROUP BY a.id;
```

### API Call Statistics (Last 24 Hours)

```sql
SELECT 
    COUNT(*) as total_calls,
    SUM(success) as successful_calls,
    COUNT(*) - SUM(success) as failed_calls,
    SUM(alert_count) as total_alerts,
    AVG(alert_count) as avg_alerts_per_call
FROM api_calls
WHERE called_at > datetime('now', '-24 hours');
```

### Most Common Errors

```sql
SELECT error_message, COUNT(*) as count
FROM api_calls
WHERE success = 0 AND error_message IS NOT NULL
GROUP BY error_message
ORDER BY count DESC;
```

### Recent API Call Rate

```sql
SELECT COUNT(*) as calls_last_minute
FROM api_calls
WHERE called_at > datetime('now', '-60 seconds');
```

---

## Database Maintenance

### Vacuum Database

Reclaim unused space:
```sql
VACUUM;
```

### Analyze for Query Optimization

Update statistics for query planner:
```sql
ANALYZE;
```

### Check Database Integrity

```sql
PRAGMA integrity_check;
```

### View Table Sizes

```sql
SELECT 
    name,
    SUM("pgsize") as size_bytes
FROM "dbstat"
WHERE aggregate = TRUE
GROUP BY name
ORDER BY size_bytes DESC;
```

### Cleanup Old Data

Remove expired alerts (older than 30 days):
```sql
DELETE FROM alerts
WHERE datetime(expires) < datetime('now', '-30 days');
```

Remove old API call records (older than 7 days):
```sql
DELETE FROM api_calls
WHERE called_at < datetime('now', '-7 days');
```

---

## SQLite Configuration

### PRAGMA Settings

The application sets these PRAGMA directives:

```sql
-- Enable Write-Ahead Logging for better concurrency
PRAGMA journal_mode=WAL;

-- Enable foreign key constraints
PRAGMA foreign_keys=ON;
```

### WAL Mode Benefits

- Better concurrency (readers don't block writers)
- Better performance for most use cases
- Atomic commits

### File Structure

With WAL mode enabled:
- `alerts.db` - Main database file
- `alerts.db-wal` - Write-ahead log
- `alerts.db-shm` - Shared memory file

---

## Backup Procedures

### Simple Backup

```bash
cp data/alerts.db data/alerts-backup-$(date +%Y%m%d).db
```

### SQLite Backup Command

```bash
sqlite3 data/alerts.db ".backup data/alerts-backup.db"
```

### Export to SQL

```bash
sqlite3 data/alerts.db .dump > alerts-backup.sql
```

### Restore from Backup

```bash
cp data/alerts-backup.db data/alerts.db
```

---

## Security Considerations

### File Permissions

```bash
chmod 755 data/
chmod 644 data/alerts.db
```

### SQL Injection Prevention

The application uses **prepared statements** for all queries:

```php
$stmt = $pdo->prepare("SELECT * FROM alerts WHERE id = :id");
$stmt->execute([':id' => $alertId]);
```

Never use string concatenation for SQL queries.

---

## Performance Tips

1. **Indexes**: Already optimized with indexes on frequently queried columns
2. **Cleanup**: Regularly remove old data to maintain performance
3. **VACUUM**: Run periodically to reclaim space
4. **ANALYZE**: Update statistics after significant data changes
5. **WAL Mode**: Already enabled for better concurrency

---

## Related Documentation

- [Database.md](Database.md) - Database class API documentation
- [Configuration.md](Configuration.md) - Configuration details
- [SQLite Documentation](https://www.sqlite.org/docs.html)
