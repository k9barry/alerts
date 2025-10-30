# scheduler.php

Main entry point for the continuous alert monitoring scheduler. Runs an infinite loop that polls weather.gov for alerts, processes them, and sends notifications.

## Location
`scripts/scheduler.php`

## Execution
```sh
php scripts/scheduler.php
```

Docker: `docker compose up -d` (runs this as main process)

## What It Does

1. **Bootstrap**: Load environment, config, logger
2. **Build Console App**: Register commands (poll, vacuum, run-scheduler)
3. **Execute run-scheduler**: Enter infinite loop
   - Fetch alerts from weather.gov
   - Store in incoming_alerts
   - Diff against active_alerts
   - Queue new alerts in pending_alerts
   - Process notifications
   - Replace active with incoming
   - Periodic VACUUM
   - Sleep for POLL_MINUTES

## Configuration
- `POLL_MINUTES`: Interval between cycles (default: 3)
- `VACUUM_HOURS`: Hours between VACUUM (default: 24)
- `API_RATE_PER_MINUTE`: Weather API rate limit (default: 4)

## Logging
JSON-formatted logs to stdout (viewable via Dozzle at http://localhost:9999)

## Error Handling
- Catches exceptions in loop
- Logs errors and continues
- Doesn't crash on transient failures

See [RUNTIME.md](../overview/RUNTIME.md) for complete details.
