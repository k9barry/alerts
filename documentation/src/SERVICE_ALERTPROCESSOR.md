# Service/AlertProcessor.php

Processes alerts: identifies new ones, filters by geography, sends notifications.

## Location
`src/Service/AlertProcessor.php`

## Purpose
Core business logic for alert processing and notification dispatch.

## Key Methods

### diffAndQueue()
Identifies new alerts:
1. Get incoming alert IDs
2. Get active alert IDs
3. Calculate new = incoming - active
4. Queue new alerts in pending_alerts
5. Return count

### processPending()
Processes pending alerts:
1. Fetch all from pending_alerts
2. For each alert:
   - Extract SAME/UGC codes
   - Compare against Config::$weatherAlerts filter
   - If doesn't match: DELETE from pending, skip
   - If matches:
     a. Send to Pushover (if enabled)
     b. Send to ntfy (if enabled)
     c. Record in sent_alerts
     d. DELETE from pending

## Geographic Filtering
```php
$codes = array_map('strtoupper', Config::$weatherAlerts);
$same = json_decode($alert['same_array'], true);
$ugc = json_decode($alert['ugc_array'], true);
$intersects = array_intersect($codes, $same) || array_intersect($codes, $ugc);
```

If `$codes` empty, all alerts match (no filtering).

## Notification Channels
Both Pushover and ntfy can be enabled simultaneously. Results recorded independently.

## Error Handling
- Try-catch per alert
- Failures logged but don't stop queue processing
- Alert removed from pending even on failure (prevents infinite retry)

## Logging
- Info: "Queued new alerts into pending" with count
- Info/Error: Notification results

See [ARCHITECTURE.md](../overview/ARCHITECTURE.md) for detailed flow.
