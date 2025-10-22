# LoggerFactory Class Documentation

## Overview

The `LoggerFactory` class creates and configures Monolog logger instances for the application with multiple output handlers.

## Class: Alerts\LoggerFactory

### create()

```php
public static function create(string $name, string $logLevel, string $logPath): Logger
```

Creates a fully configured Monolog logger instance.

**Parameters:**
- `$name` (string): Logger channel name (usually application name)
- `$logLevel` (string): Minimum log level (DEBUG, INFO, WARNING, ERROR)
- `$logPath` (string): Full path to log file

**Returns:** Monolog\Logger - Configured logger instance

**Features:**
- Multiple output handlers (file, stdout, error_log)
- Custom formatting
- Automatic log directory creation
- Supports different log levels per environment

**Example:**
```php
$logger = LoggerFactory::create('alerts', 'DEBUG', '/app/logs/alerts.log');
```

---

## Log Levels

Monolog supports multiple log levels from most to least severe:

| Level | Value | When to Use | Example |
|-------|-------|-------------|---------|
| DEBUG | 100 | Detailed diagnostic info | `$logger->debug("Parsing alert ID: $id")` |
| INFO | 200 | Informational messages | `$logger->info("Fetched 25 alerts")` |
| NOTICE | 250 | Normal but significant | `$logger->notice("Rate limit approaching")` |
| WARNING | 300 | Warning conditions | `$logger->warning("Slow API response")` |
| ERROR | 400 | Error conditions | `$logger->error("Failed to parse JSON")` |
| CRITICAL | 500 | Critical conditions | `$logger->critical("Database unavailable")` |
| ALERT | 550 | Immediate action needed | `$logger->alert("Disk space critical")` |
| EMERGENCY | 600 | System unusable | `$logger->emergency("Application crashed")` |

**Log Level Filtering:**
When you set a log level, all messages at that level and above are logged.

Example: Setting level to `WARNING` will log WARNING, ERROR, CRITICAL, ALERT, and EMERGENCY, but not DEBUG, INFO, or NOTICE.

---

## Output Handlers

The factory creates three handlers for comprehensive logging:

### 1. File Handler

Writes logs to the specified file.

**Configuration:**
- Path: As specified in `$logPath` parameter
- Creates directory if it doesn't exist
- Permissions: 0755 for directories
- Rotation: Not automatic (use external log rotation)

**File Format:**
```
[2024-01-15 10:30:00] alerts.INFO: Successfully fetched 25 alerts []
[2024-01-15 10:30:01] alerts.ERROR: HTTP error: 503 []
```

### 2. STDOUT Handler

Writes logs to standard output for Docker log collection.

**Purpose:**
- Captured by Docker's logging system
- Viewable in Dozzle web interface
- Real-time monitoring
- Container log integration

**Docker Integration:**
```bash
# View logs via Docker
docker logs alerts-app

# View in Dozzle
# Navigate to http://localhost:8080
```

### 3. Error Log Handler

Writes to PHP's error_log as a fallback.

**Purpose:**
- System-level logging
- Fallback if file/stdout fail
- Integration with system logs

---

## Log Message Format

### Default Format

```
[DATETIME] CHANNEL.LEVEL: MESSAGE CONTEXT
```

**Components:**
- `DATETIME`: Y-m-d H:i:s format (e.g., 2024-01-15 10:30:00)
- `CHANNEL`: Logger name (e.g., 'alerts')
- `LEVEL`: Log level name (e.g., INFO, ERROR)
- `MESSAGE`: The log message
- `CONTEXT`: Additional context data as JSON

### Examples

**Simple message:**
```
[2024-01-15 10:30:00] alerts.INFO: Application started []
```

**With context:**
```
[2024-01-15 10:30:01] alerts.ERROR: Database error {"error":"unable to open database file"} []
```

**With extra data:**
```
[2024-01-15 10:30:02] alerts.DEBUG: Processing alert {"id":"urn:oid:123","event":"Winter Storm"} []
```

---

## Usage Examples

### Basic Usage

```php
use Alerts\LoggerFactory;

// Create logger
$logger = LoggerFactory::create('myapp', 'INFO', '/app/logs/app.log');

// Log messages
$logger->info('Application started');
$logger->warning('Rate limit approaching', ['calls' => 3]);
$logger->error('Failed to connect', ['host' => 'api.example.com']);
```

### Debug Mode

```php
// Development environment - verbose logging
$logger = LoggerFactory::create('alerts', 'DEBUG', '/app/logs/debug.log');

$logger->debug('Entering function processAlert()');
$logger->debug('Alert data received', ['count' => 5]);
$logger->debug('Database query executed', ['query' => 'INSERT INTO...']);
```

### Production Mode

```php
// Production environment - minimal logging
$logger = LoggerFactory::create('alerts', 'WARNING', '/app/logs/production.log');

// Only warnings and errors logged
$logger->info('This will not be logged');
$logger->warning('This will be logged');
$logger->error('This will be logged');
```

### With Context Data

```php
// Add context to help with debugging
$logger->error('API request failed', [
    'url' => $url,
    'status_code' => $httpCode,
    'response' => $response
]);

// Context appears in log as JSON
// [2024-01-15 10:30:00] alerts.ERROR: API request failed {"url":"https://...","status_code":500,"response":"..."} []
```

### Logging Exceptions

```php
try {
    // Some operation
    $result = dangerousOperation();
} catch (Exception $e) {
    $logger->error('Operation failed', [
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}
```

---

## Configuration

### Environment-Based Configuration

```php
// Load from configuration
$config = require 'config/config.php';

$logger = LoggerFactory::create(
    $config['app']['name'],
    $config['logging']['level'],
    $config['logging']['path']
);
```

### Log Level by Environment

```php
// Determine log level based on environment
$env = getenv('APP_ENV');

$logLevel = match($env) {
    'production' => 'WARNING',
    'staging' => 'INFO',
    'development' => 'DEBUG',
    default => 'INFO'
};

$logger = LoggerFactory::create('alerts', $logLevel, '/app/logs/alerts.log');
```

---

## Log File Management

### Automatic Directory Creation

The factory automatically creates the log directory:

```php
// If /app/logs doesn't exist, it will be created
$logger = LoggerFactory::create('alerts', 'INFO', '/app/logs/app.log');
```

### Log Rotation

The factory doesn't include automatic rotation. Use external tools:

**Using logrotate (Linux):**
```
/app/logs/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    create 0644 www-data www-data
}
```

**Using Docker:**
```yaml
services:
  alerts:
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"
```

### Permissions

```bash
# Ensure proper permissions
chmod 755 /app/logs
chmod 644 /app/logs/*.log
```

---

## Docker Integration

### Viewing Logs in Docker

```bash
# View all logs
docker logs alerts-app

# Follow logs in real-time
docker logs -f alerts-app

# View last 100 lines
docker logs --tail 100 alerts-app

# View logs with timestamps
docker logs -t alerts-app
```

### Dozzle Integration

Dozzle automatically captures the STDOUT handler output:

1. Navigate to http://localhost:8080
2. Select the 'alerts-app' container
3. View real-time logs
4. Filter by log level
5. Search log messages

### Log Aggregation

For production systems, consider:
- ELK Stack (Elasticsearch, Logstash, Kibana)
- Splunk
- Graylog
- CloudWatch Logs (AWS)
- Stackdriver Logging (GCP)

---

## Performance Considerations

### Log Level Impact

- **DEBUG**: High volume, use only in development
- **INFO**: Moderate volume, good for production
- **WARNING**: Low volume, minimal impact
- **ERROR**: Very low volume (hopefully!)

### Buffering

Monolog buffers logs before writing:
- Reduces I/O operations
- Improves performance
- Automatic flushing on shutdown

### Asynchronous Logging

For high-performance requirements:
```php
use Monolog\Handler\FingersCrossedHandler;

// Only write logs if ERROR or above occurs
$handler = new FingersCrossedHandler(
    new StreamHandler($logPath),
    Logger::ERROR
);
```

---

## Troubleshooting

### Logs Not Appearing

**Check file permissions:**
```bash
ls -la /app/logs/
```

**Check log level:**
```php
// Ensure level is low enough
$logger = LoggerFactory::create('alerts', 'DEBUG', '/app/logs/alerts.log');
```

**Check handlers:**
```php
// Verify handlers are attached
$handlers = $logger->getHandlers();
var_dump(count($handlers)); // Should be 3
```

### Log Directory Not Created

```php
// Ensure parent directory exists and is writable
$logPath = '/app/logs/alerts.log';
$logDir = dirname($logPath);

if (!is_writable(dirname($logDir))) {
    throw new Exception("Cannot create log directory: $logDir");
}
```

### Logs Too Verbose

```php
// Increase minimum log level
$logger = LoggerFactory::create('alerts', 'WARNING', '/app/logs/alerts.log');
// Now only WARNING, ERROR, CRITICAL, ALERT, EMERGENCY will be logged
```

---

## Best Practices

1. **Use Appropriate Levels**: Don't log everything as ERROR
2. **Include Context**: Add relevant data to help debugging
3. **Structured Logging**: Use consistent message formats
4. **Don't Log Sensitive Data**: Never log passwords, API keys, etc.
5. **Log Actions, Not States**: "Failed to connect" not "Connection is null"
6. **Use Unique Messages**: Make searching easier
7. **Include Identifiers**: Log IDs, usernames, etc.
8. **Performance**: Avoid logging in tight loops
9. **Rotation**: Implement log rotation in production
10. **Monitoring**: Set up alerts for ERROR level logs

---

## Example: Complete Application

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Alerts\LoggerFactory;

// Create logger based on environment
$env = getenv('APP_ENV') ?: 'development';
$logLevel = $env === 'production' ? 'WARNING' : 'DEBUG';

$logger = LoggerFactory::create(
    'alerts',
    $logLevel,
    __DIR__ . '/logs/alerts.log'
);

$logger->info('Application starting', ['env' => $env]);

try {
    // Application logic
    $logger->debug('Connecting to API');
    
    // ... some operation ...
    
    $logger->info('Operation completed successfully', ['count' => 42]);
    
} catch (Exception $e) {
    $logger->error('Application error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit(1);
}

$logger->info('Application finished');
```

---

## Related Documentation

- [Monolog Documentation](https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md)
- [PSR-3 Logger Interface](https://www.php-fig.org/psr/psr-3/)
- [Docker Logging](https://docs.docker.com/config/containers/logging/)
