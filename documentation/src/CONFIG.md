# Config.php

Application configuration container that loads and stores all environment variables as static properties.

## Location
`src/Config.php`

## Purpose
- Centralized configuration management
- Type-safe access to settings
- Single source of truth for all configuration

## Usage
```php
use App\Config;

// Initialize from environment (done in bootstrap.php)
Config::initFromEnv();

// Access configuration
$pollInterval = Config::$pollMinutes;
$apiUrl = Config::$weatherApiUrl;
$codes = Config::$weatherAlerts;
```

## Properties

### Application
- `$appName`: Application name (default: "alerts")
- `$appVersion`: Version number (default: "0.1.0")
- `$contactEmail`: Contact email for API User-Agent

### Timing & Limits
- `$pollMinutes`: Poll interval (default: 3)
- `$apiRatePerMinute`: Weather API rate limit (default: 4)
- `$pushoverRateSeconds`: Pushover notification pacing (default: 2)
- `$vacuumHours`: Database VACUUM interval (default: 24)

### Database
- `$dbPath`: SQLite database path

### Logging
- `$logChannel`: stdout or file
- `$logLevel`: debug, info, warning, error, etc.

### APIs
- `$weatherApiUrl`: Weather.gov endpoint
- `$pushoverApiUrl`: Pushover endpoint

### Notifications
- `$pushoverUser`: Pushover user key
- `$pushoverToken`: Pushover app token
- `$pushoverEnabled`: Enable Pushover (bool)
- `$ntfyEnabled`: Enable ntfy (bool)
- `$ntfyBaseUrl`: ntfy server URL
- `$ntfyTopic`: ntfy topic name
- `$ntfyUser`, `$ntfyPassword`, `$ntfyToken`: ntfy auth
- `$ntfyTitlePrefix`: Optional prefix for ntfy titles

### Filtering
- `$weatherAlerts`: Array of SAME/UGC codes
- `$timezone`: IANA timezone for timestamp localization

## Initialization

Called in `src/bootstrap.php`:
```php
Config::initFromEnv();
```

Reads from environment and populates static properties with type casting and defaults.

## Implementation Details
- Static class (no instantiation needed)
- Immutable after initialization
- Uses `env()` helper for reading environment
- Parses arrays from comma/space/semicolon-separated strings
- Converts string "true"/"false" to booleans

See [CONFIGURATION.md](../overview/CONFIGURATION.md) for all configuration options.
