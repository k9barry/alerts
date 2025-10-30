# Service/NtfyNotifier.php

Sends notifications via ntfy (open-source push notification service).

## Location
`src/Service/NtfyNotifier.php`

## Purpose
Alternative/additional notification channel using ntfy.sh or self-hosted ntfy server.

## Key Method

### send(string $title, string $message, array $options)
Sends HTTP POST to ntfy topic:
- **Title**: Via X-Title header (max 200 chars)
- **Message**: HTTP body (max 4096 chars)
- **Options**: Priority, tags, click action

## Options
```php
[
    'tags' => ['warning', 'weather'],    // Display tags
    'priority' => 3,                      // 1-5 (3=default/high)
    'click' => 'https://...',            // Clickable URL
]
```

## Authentication
Supports three methods:
1. **Bearer Token**: `NTFY_TOKEN` (preferred)
2. **Basic Auth**: `NTFY_USER` + `NTFY_PASSWORD`
3. **None**: Public topics

## Message Format
- **Title**: Event name (e.g., "Tornado Warning")
- **Message**: Headline with details
- **Click**: URL to NWS alert page

## Configuration
- `NTFY_ENABLED`: Enable/disable
- `NTFY_BASE_URL`: Server URL (default: https://ntfy.sh)
- `NTFY_TOPIC`: Topic name (required)
- `NTFY_TOKEN`: Bearer token
- `NTFY_USER`, `NTFY_PASSWORD`: Basic auth
- `NTFY_TITLE_PREFIX`: Optional prefix

## Implementation
Direct HTTP POST with Guzzle (no SDK needed).

## Error Handling
- Single attempt (no retry)
- Logs errors but doesn't throw
- Returns void

See https://docs.ntfy.sh for ntfy documentation.
