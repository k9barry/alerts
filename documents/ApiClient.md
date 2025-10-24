# ApiClient Class Documentation

## Overview

The `ApiClient` class handles all communication with the weather.gov API, including rate limiting, proper headers, and error handling.

## Class: Alerts\ApiClient

### Constructor

```php
public function __construct(
    string $baseUrl,
    string $appName,
    string $appVersion,
    string $contactEmail,
    int $rateLimit,
    int $ratePeriod,
    Database $database,
    LoggerInterface $logger
)
```

Creates a new ApiClient instance.

**Parameters:**
- `$baseUrl` (string): API base URL (e.g., 'https://api.weather.gov/alerts')
- `$appName` (string): Application name for User-Agent
- `$appVersion` (string): Application version for User-Agent
- `$contactEmail` (string): Contact email for User-Agent (required by weather.gov)
- `$rateLimit` (int): Maximum API calls per period
- `$ratePeriod` (int): Rate limit period in seconds
- `$database` (Database): Database instance for rate limiting
- `$logger` (LoggerInterface): Logger instance

**User-Agent Format:**
The User-Agent is constructed as: `{appName}/{appVersion} ({contactEmail})`

Example: `alerts/1.0.0 (user@example.com)`

**Example:**
```php
$apiClient = new ApiClient(
    'https://api.weather.gov/alerts',
    'alerts',
    '1.0.0',
    'user@example.com',
    4,
    60,
    $database,
    $logger
);
```

---

### waitForRateLimit()

```php
private function waitForRateLimit(): void
```

Implements rate limiting by checking recent API call count and sleeping if necessary.

**Features:**
- Queries database for recent call count
- Sleeps for rate period if limit reached
- Logs when waiting for rate limit
- Prevents API bans

**Note:** This is a private method called automatically before each API request.

**Rate Limiting Logic:**
```
IF recent_calls >= rate_limit THEN
    sleep(rate_period)
END IF
```

---

### fetchAlerts()

```php
public function fetchAlerts(array $params = []): ?array
```

Fetches weather alerts from the API.

**Parameters:**
- `$params` (array): Optional query parameters for filtering
  - `status`: Filter by status (e.g., 'actual', 'test')
  - `message_type`: Filter by message type
  - `event`: Filter by event type
  - `area`: Filter by state/zone
  - `point`: Filter by latitude,longitude
  - And more...

**Returns:** 
- array: Array of alert features on success
- null: On failure

**API Response Structure:**
```php
// Returns the 'features' array from:
[
    'type' => 'FeatureCollection',
    'features' => [
        [
            'id' => 'urn:oid:...',
            'type' => 'Feature',
            'properties' => [...],
            'geometry' => [...]
        ],
        // ... more alerts
    ]
]
```

**Features:**
- Applies rate limiting automatically
- Uses proper User-Agent header
- Accepts GeoJSON format
- Handles HTTP redirects
- 30-second timeout
- Validates JSON response
- Records call in database

**Error Handling:**
- Returns null on cURL errors
- Returns null on HTTP errors (non-200)
- Returns null on JSON parsing errors
- All errors logged with details

**Example:**
```php
// Fetch all active alerts
$alerts = $apiClient->fetchAlerts(['status' => 'actual']);

if ($alerts !== null) {
    echo "Fetched " . count($alerts) . " alerts";
    foreach ($alerts as $alert) {
        echo $alert['properties']['event'] . "\n";
    }
} else {
    echo "Failed to fetch alerts";
}

// Fetch alerts for a specific state
$alerts = $apiClient->fetchAlerts(['area' => 'CA']);

// Fetch alerts for a specific point
$alerts = $apiClient->fetchAlerts(['point' => '39.7456,-97.0892']);
```

---

### fetchAlertById()

```php
public function fetchAlertById(string $alertId): ?array
```

Fetches a specific alert by its ID.

**Parameters:**
- `$alertId` (string): The alert identifier (e.g., 'urn:oid:2.49.0.1.840.0.xxx')

**Returns:**
- array: Alert data on success
- null: On failure

**Features:**
- Applies rate limiting automatically
- Uses proper User-Agent header
- Fetches single alert details
- Returns complete alert object (not wrapped in FeatureCollection)

**Example:**
```php
$alertId = 'urn:oid:2.49.0.1.840.0.12345';
$alert = $apiClient->fetchAlertById($alertId);

if ($alert !== null) {
    echo "Alert: " . $alert['properties']['headline'];
} else {
    echo "Alert not found or error occurred";
}
```

---

## Rate Limiting Details

### Default Configuration
- **Rate Limit**: 4 calls per minute
- **Rate Period**: 60 seconds

### How It Works
1. Before each API call, checks database for recent calls
2. If limit reached, sleeps for the full rate period
3. Records each call in database with timestamp
4. Old records cleaned up periodically

### Best Practices
- Use appropriate query parameters to filter results
- Cache results when possible
- Monitor logs for rate limit messages
- Adjust rate limit if API allows more calls

---

## API Query Parameters

Common parameters supported by weather.gov API:

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| status | string | Alert status | `actual`, `test` |
| message_type | string | Message type | `alert`, `update` |
| event | string | Event type | `Winter Storm Warning` |
| area | string | State/territory | `CA`, `TX` |
| point | string | Lat,long | `39.7456,-97.0892` |
| region | string | Region code | `AL`, `GL` |
| zone | string | Zone ID | `CAZ006` |
| urgency | string | Urgency level | `Immediate`, `Expected` |
| severity | string | Severity level | `Extreme`, `Severe` |
| certainty | string | Certainty level | `Observed`, `Likely` |
| limit | int | Max results | `100` |

**Example with multiple parameters:**
```php
$alerts = $apiClient->fetchAlerts([
    'status' => 'actual',
    'severity' => 'Severe',
    'area' => 'CA',
    'limit' => 50
]);
```

---

## Error Codes and Handling

### HTTP Status Codes

| Code | Meaning | Handling |
|------|---------|----------|
| 200 | Success | Returns data |
| 301/302 | Redirect | Automatically followed |
| 400 | Bad Request | Logged, returns null |
| 404 | Not Found | Logged, returns null |
| 429 | Rate Limited | Logged, returns null |
| 500 | Server Error | Logged, returns null |
| 503 | Service Unavailable | Logged, returns null |

### cURL Errors

Common cURL errors are caught and logged:
- Connection timeout
- DNS resolution failure
- SSL certificate errors
- Network unreachable

### JSON Errors

Invalid JSON responses are detected and logged with the specific error.

---

## User-Agent Requirements

The weather.gov API **requires** a User-Agent header that includes:
1. Application name and version
2. Contact email

**Why:**
- Allows NWS to contact you if there are issues
- Helps track API usage
- Required by NWS API terms of service

**Format Used:**
```
ApplicationName/Version (contact@email.com)
```

**Example:**
```
alerts/1.0.0 (john.doe@example.com)
```

---

## Logging

The ApiClient logs the following:

**DEBUG Level:**
- User-Agent configuration

**INFO Level:**
- API request URLs
- Successful fetches with alert counts
- Rate limit waits

**ERROR Level:**
- cURL errors
- HTTP errors
- JSON parsing errors
- Exception messages

**Example Log Output:**
```
[2024-01-15 10:30:00] alerts.DEBUG: API Client initialized with User-Agent: alerts/1.0.0 (user@example.com)
[2024-01-15 10:30:01] alerts.INFO: Fetching alerts from: https://api.weather.gov/alerts?status=actual
[2024-01-15 10:30:02] alerts.INFO: Successfully fetched 25 alerts
[2024-01-15 10:30:45] alerts.INFO: Rate limit reached (4/4). Waiting 60 seconds...
```

---

## Performance Tips

1. **Use Filters**: Always filter by status='actual' to get only active alerts
2. **Geographic Filtering**: Use area/point/zone to limit results
3. **Batch Processing**: Process multiple alerts per API call
4. **Cache Results**: Don't fetch the same data repeatedly
5. **Monitor Logs**: Watch for rate limit warnings

---

## Security Considerations

- Never log API responses containing sensitive data
- Validate all API responses before processing
- Use HTTPS for all API calls
- Sanitize email address in User-Agent
- Handle all errors gracefully without exposing internals

---

## Complete Example

```php
<?php

use Alerts\ApiClient;
use Alerts\Database;
use Alerts\LoggerFactory;

// Setup
$logger = LoggerFactory::create('alerts', 'DEBUG', '/app/logs/alerts.log');
$database = new Database('/app/data/alerts.db', $logger);

// Create API client
$apiClient = new ApiClient(
    'https://api.weather.gov/alerts',
    'MyWeatherApp',
    '2.0.0',
    'admin@myweatherapp.com',
    4,      // 4 calls
    60,     // per 60 seconds
    $database,
    $logger
);

// Fetch active severe alerts
$alerts = $apiClient->fetchAlerts([
    'status' => 'actual',
    'severity' => 'Severe'
]);

if ($alerts !== null) {
    foreach ($alerts as $alert) {
        $props = $alert['properties'];
        echo sprintf(
            "[%s] %s - %s\n",
            $props['severity'],
            $props['event'],
            $props['headline']
        );
    }
} else {
    echo "Failed to fetch alerts\n";
}
```

---

## Related Documentation

- [Database.md](Database.md) - Database operations
- [LoggerFactory.md](LoggerFactory.md) - Logging configuration
- [Weather.gov API Documentation](https://www.weather.gov/documentation/services-web-api)
