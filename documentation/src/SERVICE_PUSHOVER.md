# src/Service/PushoverNotifier.php

Purpose: Send alert notifications to Pushover with pacing and retries.

Behavior:
- Uses a Guzzle client with http_errors disabled.
- Enforces a minimum delay between sends using pace() based on Config::$pushoverRateSeconds.
- notifyDetailed(alertRow): builds a title and message from properties, includes a URL if id is http(s), and posts to Pushover.
  - Retries up to 3 times until a 200 response is received.
  - Parses API error messages when available, collects status, attempts, error, and request id.
  - Logs the result and returns the result array for persistence.

Usage:
- $notifier = new PushoverNotifier(); $result = $notifier->notifyDetailed($row);
