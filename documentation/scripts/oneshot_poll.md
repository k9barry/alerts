# oneshot_poll.php

Executes a single alert poll cycle and exits. Useful for testing, manual refreshes, or cron-based scheduling.

## Location
`scripts/oneshot_poll.php`

## Execution
```sh
php scripts/oneshot_poll.php
```

## What It Does
1. Fetch alerts from weather.gov API
2. Store in incoming_alerts table
3. Identify new alerts (diff against active)
4. Queue new alerts in pending_alerts
5. Send notifications
6. Update active_alerts
7. Exit

## Output
```
One-shot poll complete. Stored/updated alerts: 3
```

## Use Cases

### Testing
```sh
php scripts/oneshot_poll.php
```

### Cron-Based Scheduling
```cron
*/5 * * * * cd /opt/alerts && php scripts/oneshot_poll.php >> /var/log/alerts.log 2>&1
```

### Manual Refresh
Force immediate check for new alerts

## vs scheduler.php

| Feature | oneshot_poll | scheduler |
|---------|-------------|-----------|
| Execution | Single cycle | Infinite loop |
| VACUUM | No | Yes (periodic) |
| Error Recovery | Exits | Logs & continues |
| Use Case | Testing, cron | Production daemon |

See [RUNTIME.md](../overview/RUNTIME.md) for more details.
