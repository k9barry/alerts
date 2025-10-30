# Http/RateLimiter.php

In-process rate limiter for HTTP requests using a rolling time window.

## Location
`src/Http/RateLimiter.php`

## Purpose
Prevents exceeding weather.gov API rate limits by tracking request timestamps and sleeping when necessary.

## Usage
```php
use App\Http\RateLimiter;

$limiter = new RateLimiter(4); // 4 requests per minute
$limiter->await(); // Blocks if rate limit would be exceeded
// Make HTTP request here
```

## Algorithm
1. Remove timestamps older than 60 seconds (rolling window)
2. If count >= max allowed, sleep until earliest timestamp ages out
3. Record new timestamp

## Configuration
`Config::$apiRatePerMinute` (default: 4)

## Implementation Details
- In-memory array of timestamps
- Microsecond precision
- Automatic cleanup of old timestamps
- CPU-efficient (sleeps instead of busy-waiting)

## Limitations
- Single process only (not shared across instances)
- Lost on process restart
- No persistence

For multi-instance deployment, use Redis-based rate limiter.
