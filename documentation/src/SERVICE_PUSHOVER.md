# Service/PushoverNotifier.php

Sends rich push notifications via Pushover API.

## Location
`src/Service/PushoverNotifier.php`

## Purpose
Delivers weather alerts to users via Pushover with retry logic and rate limiting.

## Key Method

### notifyDetailed(array $alertRow)
Sends notification with retry:
1. Build title and message (via MessageBuilderTrait)
2. Pace requests (sleep to maintain minimum gap)
3. Send HTTP POST to Pushover API
4. Retry up to 3 times on failure
5. Return result metadata

## Message Format
- **Title**: `[EVENT] Headline`
- **Message**: Severity/Certainty/Urgency, times, description, instructions
- **URL**: Link to NWS alert details (if ID is URL)

## Rate Limiting
Tracks `lastSentAt` timestamp, sleeps to maintain configured gap (default 2 seconds).

## Retry Logic
- Up to 3 attempts
- Logs each attempt
- Returns success/failure status with attempt count

## Configuration
- `PUSHOVER_API_URL`: API endpoint
- `PUSHOVER_USER`: User key
- `PUSHOVER_TOKEN`: App token
- `PUSHOVER_RATE_SECONDS`: Pacing delay
- `PUSHOVER_ENABLED`: Enable/disable

## Response Handling
- HTTP 200: Success, extract request ID
- Other: Parse error message from JSON response

## Return Value
```php
[
    'status' => 'success' or 'failure',
    'attempts' => 1-3,
    'error' => null or error message,
    'request_id' => 'pushover-request-id' or null
]
```
