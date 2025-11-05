# Runtime and Scheduler

This document describes how the Alerts application runs, the scheduler's operation, and the complete execution flow.

## Application Entry Points

### 1. Scheduler (Primary)
**File**: `scripts/scheduler.php`  
**Purpose**: Continuous background process that polls and processes alerts
**Usage**:
```sh
php scripts/scheduler.php
```

**Docker Command**:
```yaml
command: ["php", "scripts/scheduler.php"]
```

### 2. One-Shot Poll
**File**: `scripts/oneshot_poll.php`  
**Purpose**: Execute single poll cycle without continuous loop
**Usage**:
```sh
php scripts/oneshot_poll.php
```

**Use Cases**:
- Testing the system
- Manual alert refresh
- Cron-based scheduling (alternative to continuous scheduler)

### 3. Web Interface
**File**: `public/index.php`  
**Purpose**: Web entry point (currently placeholder)
**Status**: Returns 404 - GUI not implemented
**Future**: CRUD interface for alert management

### 4. Database Migrations
**File**: `scripts/migrate.php`  
**Purpose**: Initialize and update database schema
**Usage**:
```sh
php scripts/migrate.php
```

**Automatic Execution**:
- Composer post-install hook
- Docker container startup (entrypoint.sh)

## Bootstrap Process

Every script starts with the same bootstrap sequence (`src/bootstrap.php`):

### 1. Autoloader
```php
require __DIR__ . '/../vendor/autoload.php';
```
Loads Composer autoloader for PSR-4 class loading.

### 2. Environment Loading
```php
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createUnsafeImmutable($root)->safeLoad();
}
```
Loads `.env` file if present, makes variables available via `getenv()` and `$_ENV`.

### 3. Directory Creation
```php
@mkdir($root . '/data', 0777, true);
@mkdir($root . '/logs', 0777, true);
```
Ensures required directories exist.

### 4. Configuration Initialization
```php
Config::initFromEnv();
```
Reads all environment variables into `Config` static properties.

### 5. Logger Initialization
```php
LoggerFactory::init();
```
Configures Monolog with JSON formatter and appropriate output stream.

## Scheduler Operation

### Continuous Scheduler Flow

```
┌──────────────────────────────────────────┐
│ START: php scripts/scheduler.php         │
└────────────────┬─────────────────────────┘
                 │
                 ↓
┌────────────────────────────────────────────────────────┐
│ Bootstrap (load env, init config, init logger)         │
└────────────────┬───────────────────────────────────────┘
                 │
                 ↓
┌────────────────────────────────────────────────────────┐
│ ConsoleApp::build()                                     │
│ - Create Symfony Console Application                   │
│ - Register commands: poll, vacuum, run-scheduler       │
└────────────────┬───────────────────────────────────────┘
                 │
                 ↓
┌────────────────────────────────────────────────────────┐
│ Execute 'run-scheduler' command                         │
└────────────────┬───────────────────────────────────────┘
                 │
                 ↓
┌─────────────────────────────────────────────────────────┐
│ INFINITE LOOP                                            │
│  ┌──────────────────────────────────────────────────┐   │
│  │ TRY                                               │   │
│  │  ┌────────────────────────────────────────────┐  │   │
│  │  │ 1. Fetch and Store Incoming                │  │   │
│  │  │    AlertFetcher.fetchAndStoreIncoming()    │  │   │
│  │  └────────────────┬───────────────────────────┘  │   │
│  │                   │                               │   │
│  │  ┌────────────────▼───────────────────────────┐  │   │
│  │  │ 2. Diff and Queue                          │  │   │
│  │  │    AlertProcessor.diffAndQueue()           │  │   │
│  │  └────────────────┬───────────────────────────┘  │   │
│  │                   │                               │   │
│  │  ┌────────────────▼───────────────────────────┐  │   │
│  │  │ 3. Process Pending                         │  │   │
│  │  │    AlertProcessor.processPending()         │  │   │
│  │  └────────────────┬───────────────────────────┘  │   │
│  │                   │                               │   │
│  │  ┌────────────────▼───────────────────────────┐  │   │
│  │  │ 4. Replace Active with Incoming            │  │   │
│  │  │    AlertsRepository.replaceActiveWith...() │  │   │
│  │  └────────────────────────────────────────────┘  │   │
│  │                                                   │   │
│  │ CATCH (Throwable)                                │   │
│  │  - Log error                                     │   │
│  │  - Continue loop (don't crash)                   │   │
│  └──────────────────────────────────────────────────┘   │
│                     │                                    │
│  ┌──────────────────▼─────────────────────────────────┐ │
│  │ Check if VACUUM needed                             │ │
│  │ If (time - lastVacuum) >= VACUUM_HOURS:            │ │
│  │   - Execute VACUUM                                 │ │
│  │   - Update lastVacuum timestamp                    │ │
│  └──────────────────┬─────────────────────────────────┘ │
│                     │                                    │
│  ┌──────────────────▼─────────────────────────────────┐ │
│  │ Sleep for POLL_MINUTES * 60 seconds                │ │
│  └──────────────────┬─────────────────────────────────┘ │
│                     │                                    │
│                     └─────────── Loop ───────────────────┤
└─────────────────────────────────────────────────────────┘
```

### Detailed Step Breakdown

#### Step 1: Fetch and Store Incoming

**Class**: `Service\AlertFetcher`  
**Method**: `fetchAndStoreIncoming()`

**Actions**:
1. Call `WeatherClient::fetchActive()`
   - Check rate limiter (wait if necessary)
   - Send HTTP GET to weather.gov API
   - Include ETag/Last-Modified headers if available
   - Handle 304 Not Modified (no changes)
   - Parse JSON response
2. Extract features from GeoJSON
3. For each feature:
   - Extract alert ID
   - Extract SAME codes from `geocode.SAME`
   - Extract UGC codes from `geocode.UGC`
   - Store full feature JSON
4. Call `AlertsRepository::replaceIncoming()`
   - Begin transaction
   - DELETE all from incoming_alerts
   - INSERT all new alerts
   - Commit transaction

**Logging**:
- Info: "Stored incoming alerts" with count
- Info: "No changes from API (0 features)" if empty response

**Error Handling**:
- HTTP errors return empty features array (fail-safe)
- Database errors roll back transaction and throw

#### Step 2: Diff and Queue

**Class**: `Service\AlertProcessor`  
**Method**: `diffAndQueue()`

**Actions**:
1. Get all IDs from incoming_alerts
2. Get all IDs from active_alerts
3. Calculate new IDs: `incoming - active`
4. If new IDs exist:
   - SELECT full records from incoming_alerts for new IDs
   - INSERT OR IGNORE into pending_alerts
5. Return count of queued alerts

**Logging**:
- Info: "Queued new alerts into pending" with count

**Purpose**: Identifies alerts that are new since last cycle

#### Step 3: Process Pending

**Class**: `Service\AlertProcessor`  
**Method**: `processPending()`

**Actions**:
1. SELECT all from pending_alerts
2. For each pending alert:
   - Extract SAME and UGC codes
   - Compare against `Config::$weatherAlerts` filter
   - If doesn't match filter: DELETE from pending, skip
   - If matches filter:
     a. Build notification message (via MessageBuilderTrait)
     b. Send to Pushover (if enabled):
        - Retry up to 3 times
        - Pace with configured delay
        - Record request ID
     c. Send to ntfy (if enabled):
        - Single attempt (no retry)
        - Custom priority and tags
        - Clickable URL to NWS details
     d. Record result in sent_alerts:
        - INSERT OR REPLACE (same alert ID overwrites)
        - Store full alert data + notification metadata
     e. DELETE from pending (always, even on failure)

**Logging**:
- Info: Pushover send result (status, attempts, error)
- Info: Ntfy notification sent
- Error: Ntfy send failures
- Error: Failed processing pending alert

**Error Handling**:
- Wrapped in try-catch per alert
- Failures logged but don't stop processing queue
- Alert removed from pending even on failure (prevents infinite retry)

#### Step 4: Replace Active with Incoming

**Class**: `Repository\AlertsRepository`  
**Method**: `replaceActiveWithIncoming()`

**Actions**:
1. Begin transaction
2. DELETE all from active_alerts
3. INSERT all from incoming_alerts into active_alerts
4. Commit transaction

**Purpose**: Update the baseline for next diff cycle

**Logging**: None (silent success)

**Error Handling**: Transaction rollback on error

#### Step 5: Periodic VACUUM

**Frequency**: Every `VACUUM_HOURS` (default 24)

**Actions**:
1. Check elapsed time since last VACUUM
2. If >= threshold:
   - Execute `VACUUM` SQL command
   - Update `lastVacuum` timestamp
   - Log completion

**Purpose**: 
- Reclaim disk space from deleted records
- Optimize database structure
- Rebuild indexes

**Logging**:
- Info: "Database vacuum complete (scheduled)"
- Error: "Vacuum error" on failure

**Error Handling**: 
- Caught and logged
- Doesn't crash scheduler loop

#### Step 6: Sleep

**Duration**: `Config::$pollMinutes * 60` seconds (default 180)

**Actions**:
- `sleep($pollSecs)`
- Blocks execution for configured interval
- CPU-efficient (doesn't busy-wait)

**Notes**:
- Minimum 60 seconds (enforced by `max(60, ...)`)
- Configurable via `POLL_MINUTES` environment variable

## One-Shot Poll Operation

Executes a single iteration of the scheduler loop:

```
┌──────────────────────────────────┐
│ Bootstrap                        │
└────────────┬─────────────────────┘
             │
             ↓
┌────────────────────────────────────┐
│ Instantiate Services               │
│ - AlertFetcher                     │
│ - AlertProcessor                   │
│ - AlertsRepository                 │
└────────────┬───────────────────────┘
             │
             ↓
┌─────────────────────────────────────────┐
│ Execute Poll Cycle                      │
│ 1. fetchAndStoreIncoming()              │
│ 2. diffAndQueue()                       │
│ 3. processPending()                     │
│ 4. replaceActiveWithIncoming()          │
└─────────────────┬───────────────────────┘
                  │
                  ↓
┌─────────────────────────────────────────┐
│ EXIT                                    │
└─────────────────────────────────────────┘
```

**Use Cases**:
- Testing the complete workflow
- Manual refresh on demand
- Cron-based scheduling (run every N minutes via cron instead of internal loop)

**Example Cron**:
```cron
*/3 * * * * cd /path/to/alerts && php scripts/oneshot_poll.php
```

## Symfony Console Commands

The application defines three console commands:

### 1. poll
**Name**: `poll`  
**Description**: Execute single poll cycle  
**Usage**: Called internally by one-shot script  
**Implementation**: Anonymous class in ConsoleApp.php

```php
$app->add(new class('poll') extends Command {
    protected function execute(...): int {
        $fetcher->fetchAndStoreIncoming();
        $processor->diffAndQueue();
        $processor->processPending();
        return Command::SUCCESS;
    }
});
```

### 2. vacuum
**Name**: `vacuum`  
**Description**: Execute database VACUUM  
**Usage**: Manual database maintenance  
**Implementation**: Anonymous class in ConsoleApp.php

```php
$app->add(new class('vacuum') extends Command {
    protected function execute(...): int {
        Connection::get()->exec('VACUUM');
        LoggerFactory::get()->info('Database vacuum complete');
        return Command::SUCCESS;
    }
});
```

**Manual Execution**:
```sh
php scripts/scheduler.php vacuum
```

### 3. run-scheduler
**Name**: `run-scheduler`  
**Description**: Continuous scheduler loop  
**Usage**: Default command in scheduler.php  
**Implementation**: Anonymous class with infinite loop

## Process Management

### Running as Daemon

**Docker**: 
- Container runs scheduler as main process (PID 1)
- Docker handles restart on crash (if `restart: unless-stopped`)
- Logs to stdout (viewable via Dozzle)

**Systemd** (Linux host):
```ini
[Unit]
Description=Alerts Weather Scheduler
After=network.target

[Service]
Type=simple
User=alerts
WorkingDirectory=/opt/alerts
ExecStart=/usr/bin/php /opt/alerts/scripts/scheduler.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

**Supervisor** (Alternative):
```ini
[program:alerts-scheduler]
command=/usr/bin/php /opt/alerts/scripts/scheduler.php
directory=/opt/alerts
user=alerts
autostart=true
autorestart=true
stderr_logfile=/var/log/alerts-scheduler.err.log
stdout_logfile=/var/log/alerts-scheduler.out.log
```

### Stopping the Scheduler

**Docker**:
```sh
docker compose stop alerts
# or
docker compose down
```

**Direct Process**:
```sh
# Find PID
ps aux | grep scheduler.php

# Send SIGTERM
kill <PID>

# Force kill (if unresponsive)
kill -9 <PID>
```

**Graceful Shutdown**: 
- PHP sleep() is interruptible by signals
- SIGTERM will interrupt sleep and exit on next loop check
- No graceful shutdown currently implemented (future enhancement)

## Error Handling and Recovery

### Crash Prevention

**Scheduler Loop**:
- Entire poll cycle wrapped in try-catch
- Errors logged but don't crash loop
- Continues to next cycle after error

**Example**:
```php
try {
    $fetcher->fetchAndStoreIncoming();
    $processor->diffAndQueue();
    $processor->processPending();
    $repo->replaceActiveWithIncoming();
} catch (\Throwable $e) {
    LoggerFactory::get()->error('Scheduler tick error', [
        'error' => $e->getMessage()
    ]);
}
```

### Failure Scenarios

**API Unavailable**:
- WeatherClient returns empty features array
- Incoming not replaced (preserves last known state)
- Next cycle retries automatically

**Database Locked**:
- Transaction fails and rolls back
- Error logged
- Next cycle retries

**Notification Failure**:
- Pushover: Retries up to 3 times
- ntfy: Single attempt (logs error)
- Alert still recorded in sent_alerts
- Alert removed from pending (prevents infinite retry)

**Out of Memory**:
- PHP crashes
- Docker restarts container
- Systemd/Supervisor restarts process
- On restart: re-runs migration, resumes scheduling

## Performance Characteristics

### Resource Usage

**CPU**:
- Low during sleep (near 0%)
- Spike during poll cycle (parsing JSON, database writes)
- Typical cycle: <1 second on modest hardware

**Memory**:
- Baseline: ~10-20 MB (PHP + dependencies)
- Peak: ~30-50 MB during large alert fetch
- Stable (no memory leaks in testing)

**Disk I/O**:
- Writes on each cycle (incoming_alerts, active_alerts, pending_alerts)
- Reads for diff operations
- WAL mode improves write performance
- VACUUM causes temporary spike

**Network**:
- One HTTP request per poll cycle (to weather.gov)
- Additional requests for notifications (Pushover, ntfy)
- Typical: <100 KB per cycle

### Scalability

**Current Design**:
- Single process
- Single geographic region (or all US)
- Dozens of alerts per day
- Works well for personal/small team use

**Bottlenecks**:
- API rate limits (4-10 requests/minute)
- Notification pacing (2 seconds per Pushover message)
- SQLite write serialization

**Scaling Strategies**:
1. **More Regions**: Run multiple instances with different filters
2. **Higher Volume**: Increase rate limits if allowed
3. **Faster Notifications**: Parallel notification sending (already implemented)
4. **Multiple Schedulers**: Requires shared state (Redis for rate limiter)

## Monitoring and Observability

### Logs

**Structured JSON**:
```json
{
  "message": "Stored incoming alerts",
  "context": {"count": 5},
  "level": "info",
  "datetime": "2025-10-30T14:30:00+00:00",
  "extra": {
    "file": "/app/src/Service/AlertFetcher.php",
    "line": 59,
    "class": "App\\Service\\AlertFetcher",
    "function": "fetchAndStoreIncoming"
  }
}
```

**Key Log Messages**:
- "Stored incoming alerts" - Fetch successful
- "Queued new alerts into pending" - New alerts detected
- "Pushover send result" - Notification sent
- "Ntfy notification sent" - Ntfy delivery
- "Scheduler tick error" - Cycle failed
- "Database vacuum complete" - Maintenance done

### Metrics (Manual)

Query database for metrics:
```sql
-- Alerts processed today
SELECT COUNT(*) FROM sent_alerts 
WHERE DATE(notified_at) = DATE('now');

-- Success rate
SELECT 
    result_status, 
    COUNT(*) * 100.0 / (SELECT COUNT(*) FROM sent_alerts) as pct
FROM sent_alerts 
GROUP BY result_status;

-- Active alerts right now
SELECT COUNT(*) FROM incoming_alerts;
```

### Health Checks

**Simple Check**:
```sh
# Check if process running
ps aux | grep scheduler.php

# Check recent logs
docker compose logs --tail=50 alerts

# Check database accessible
sqlite3 data/alerts.sqlite "SELECT COUNT(*) FROM incoming_alerts"
```

**Advanced Check** (future):
- HTTP endpoint returning last poll time
- Prometheus metrics exporter
- Dead man's switch (external service monitors last poll)

## Troubleshooting Runtime Issues

### Scheduler Not Running

**Check**:
```sh
docker compose ps
```

**Fix**:
```sh
docker compose up -d alerts
```

### No New Alerts Detected

**Possible Causes**:
1. No active alerts in monitored area
2. Geographic filter too restrictive
3. API returning same data (304 Not Modified)

**Check**:
- View Dozzle logs for "No changes from API"
- Visit https://alerts.weather.gov to verify alerts exist
- Check user zone configuration in the web UI to ensure zones are selected
- Verify users have zones matching active alerts in the area

### High CPU Usage

**Possible Causes**:
1. POLL_MINUTES set too low
2. Tight loop due to error
3. VACUUM taking too long

**Check**:
- View logs for frequent errors
- Increase POLL_MINUTES
- Check database size (may need cleanup)

### Database Growing Too Large

**Cause**: sent_alerts never purged

**Solution**:
```sql
DELETE FROM sent_alerts 
WHERE notified_at < date('now', '-30 days');

VACUUM;
```

### Memory Leak Suspected

**Check**:
- Monitor container memory over time: `docker stats alerts`
- Check for unclosed resources in code
- Restart container as temporary fix

## Future Runtime Enhancements

1. **Graceful Shutdown**: Handle SIGTERM properly, finish current cycle
2. **Health Check Endpoint**: HTTP endpoint for monitoring
3. **Metrics Export**: Prometheus exporter
4. **Configuration Reload**: SIGHUP to reload config without restart
5. **Parallel Processing**: Process multiple alerts simultaneously
6. **Distributed Mode**: Multiple schedulers with leader election
7. **Backpressure Handling**: Slow down if notification queue backs up
8. **Circuit Breaker**: Stop calling failing APIs temporarily
