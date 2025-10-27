# src/Http/RateLimiter.php

Purpose: Enforce a maximum number of calls per minute.

Behavior:
- Maintains a sliding window of timestamps.
- await() sleeps if the number of calls in the last 60 seconds would exceed the configured limit.

Usage:
- $limiter = new RateLimiter($maxPerMinute); $limiter->await(); before making a call.
