# Logging/LoggerFactory.php

Initializes and provides Monolog logger instance.

## Location
`src/Logging/LoggerFactory.php`

## Purpose
Centralized logger configuration and access.

## Usage
```php
use App\Logging\LoggerFactory;

$logger = LoggerFactory::get();
$logger->info('Message', ['context' => 'data']);
$logger->error('Error occurred', ['exception' => $e->getMessage()]);
```

## Features
- **JSON Formatting**: Structured logs for easy parsing
- **Introspection**: Adds file/line/class/function context
- **Configurable Output**: stdout (Docker) or file
- **Configurable Level**: debug, info, warning, error, etc.

## Configuration
- `LOG_CHANNEL`: "stdout" or "file"
- `LOG_LEVEL`: "debug", "info", "warning", "error", etc.

## Log Format
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

## Implementation
- Singleton pattern (one logger instance)
- Monolog Logger with StreamHandler
- IntrospectionProcessor for debugging context
- JsonFormatter for structured output

## Viewing Logs
- **Dozzle** (Docker): http://localhost:9999
- **File**: `logs/app.log` (if LOG_CHANNEL=file)
- **Command line**: `docker compose logs -f alerts`
