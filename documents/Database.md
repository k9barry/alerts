# Database Class Documentation

## Overview

The `Database` class manages all SQLite database operations for the weather alerts system.

## Class: Alerts\Database

### Constructor

```php
public function __construct(string $dbPath, LoggerInterface $logger)
```

Creates a new Database instance.

**Parameters:**
- `$dbPath` (string): Full path to the SQLite database file
- `$logger` (LoggerInterface): Logger instance for recording operations

**Example:**
```php
$database = new Database('/app/data/alerts.db', $logger);
```

---

### getConnection()

```php
public function getConnection(): PDO
```

Returns a PDO connection to the SQLite database, creating it if it doesn't exist.

**Returns:** PDO instance

**Throws:** PDOException if connection fails

**Features:**
- Creates database directory if needed
- Enables Write-Ahead Logging (WAL)
- Enables foreign key constraints
- Sets error mode to exceptions

**Example:**
```php
$pdo = $database->getConnection();
```

---

### initializeSchema()

```php
public function initializeSchema(): void
```

Creates the database schema if it doesn't already exist.

**Tables Created:**

1. **alerts**: Main table for storing weather alert data
   - Stores all alert properties from the API
   - Uses alert ID as primary key
   - Includes timestamps for tracking

2. **alert_zones**: Stores affected geographic zones
   - Links alerts to their affected zones
   - Prevents duplicate zone entries

3. **api_calls**: Tracks API call history
   - Used for rate limiting
   - Records success/failure
   - Stores error messages

**Indexes Created:**
- `idx_alerts_event`: Fast lookups by event type
- `idx_alerts_severity`: Fast lookups by severity
- `idx_alerts_expires`: Fast lookups by expiration
- `idx_alerts_sent`: Fast lookups by send time
- `idx_zones_alert_id`: Fast zone lookups
- `idx_api_calls_time`: Fast time-based queries

**Throws:** PDOException if schema creation fails

**Example:**
```php
$database->initializeSchema();
```

---

### upsertAlert()

```php
public function upsertAlert(array $alert): bool
```

Inserts a new alert or updates an existing one based on alert ID.

**Parameters:**
- `$alert` (array): Alert data from the weather.gov API in GeoJSON format

**Returns:** bool - True if successful, false otherwise

**Features:**
- Uses INSERT OR REPLACE for upsert behavior
- Automatically updates `updated_at` timestamp
- Stores complete raw JSON for reference
- Handles missing fields gracefully
- Processes affected zones

**Expected Alert Structure:**
```php
[
    'id' => 'urn:oid:2.49.0.1.840.0.xxx',
    'type' => 'Feature',
    'geometry' => [...],
    'properties' => [
        'event' => 'Winter Storm Warning',
        'severity' => 'Severe',
        'headline' => 'Winter Storm Warning issued...',
        'description' => 'Detailed description...',
        'affectedZones' => ['https://api.weather.gov/zones/...'],
        // ... more fields
    ]
]
```

**Example:**
```php
$success = $database->upsertAlert($alertData);
if ($success) {
    echo "Alert stored successfully";
}
```

---

### insertAlertZones()

```php
private function insertAlertZones(string $alertId, array $zones): void
```

Stores the affected zones for an alert.

**Parameters:**
- `$alertId` (string): Alert identifier
- `$zones` (array): Array of zone URLs from the API

**Features:**
- Extracts zone ID from URL
- Uses INSERT OR IGNORE to prevent duplicates
- Continues processing even if individual zones fail

**Note:** This is a private method called automatically by `upsertAlert()`.

---

### recordApiCall()

```php
public function recordApiCall(bool $success, int $alertCount = 0, ?string $errorMessage = null): void
```

Records an API call for rate limiting and monitoring purposes.

**Parameters:**
- `$success` (bool): Whether the API call succeeded
- `$alertCount` (int): Number of alerts received (default: 0)
- `$errorMessage` (string|null): Error message if call failed (default: null)

**Example:**
```php
// Successful call
$database->recordApiCall(true, 25);

// Failed call
$database->recordApiCall(false, 0, 'HTTP 503 Service Unavailable');
```

---

### getRecentApiCallCount()

```php
public function getRecentApiCallCount(int $seconds): int
```

Returns the number of API calls made within the specified time period.

**Parameters:**
- `$seconds` (int): Time period in seconds to check

**Returns:** int - Number of API calls made

**Example:**
```php
// Check calls in last minute
$calls = $database->getRecentApiCallCount(60);
echo "API calls in last minute: $calls";
```

---

### cleanupOldApiCalls()

```php
public function cleanupOldApiCalls(): void
```

Removes API call records older than 24 hours to prevent table bloat.

**Features:**
- Runs automatically during application execution
- Maintains 24 hours of history
- Helps prevent database growth

**Example:**
```php
$database->cleanupOldApiCalls();
```

---

## Database Schema Details

### alerts Table

| Column | Type | Description |
|--------|------|-------------|
| id | TEXT PRIMARY KEY | Unique alert identifier |
| type | TEXT | GeoJSON type (usually "Feature") |
| geometry_type | TEXT | Geometry type (Polygon, Point, etc.) |
| area_desc | TEXT | Human-readable affected area |
| sent | DATETIME | When alert was sent |
| effective | DATETIME | When alert becomes effective |
| onset | DATETIME | Expected onset time |
| expires | DATETIME | When alert expires |
| ends | DATETIME | Expected end time |
| status | TEXT | Alert status (Actual, Test, etc.) |
| message_type | TEXT | Message type (Alert, Update, etc.) |
| category | TEXT | Event category |
| severity | TEXT | Severity level |
| certainty | TEXT | Certainty level |
| urgency | TEXT | Response urgency |
| event | TEXT | Event type |
| sender | TEXT | Sender identifier |
| sender_name | TEXT | Sender name |
| headline | TEXT | Alert headline |
| description | TEXT | Detailed description |
| instruction | TEXT | Safety instructions |
| response | TEXT | Recommended response |
| parameters | TEXT | Additional parameters (JSON) |
| raw_json | TEXT | Complete raw API response |
| created_at | DATETIME | First insert timestamp |
| updated_at | DATETIME | Last update timestamp |

### alert_zones Table

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PRIMARY KEY | Auto-increment ID |
| alert_id | TEXT | Foreign key to alerts.id |
| zone_id | TEXT | Zone identifier |

### api_calls Table

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PRIMARY KEY | Auto-increment ID |
| called_at | DATETIME | Timestamp of call |
| success | INTEGER | 1 if successful, 0 if failed |
| alert_count | INTEGER | Number of alerts received |
| error_message | TEXT | Error message if failed |

---

## Error Handling

All methods handle errors gracefully:
- Database connection errors are logged and thrown
- Schema errors are logged and thrown
- Insert/update errors are logged and return false
- Individual zone insert failures are logged but don't stop processing

## Performance Considerations

- WAL mode enabled for better concurrent access
- Indexes on frequently queried columns
- Prepared statements prevent SQL injection
- Old API records cleaned up automatically

## Security Features

- Foreign key constraints enabled
- Prepared statements for all queries
- No direct user input in queries
- Error messages don't expose sensitive data
