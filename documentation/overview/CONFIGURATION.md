# Configuration Guide

This document describes all configuration options available in the Alerts application.

## Configuration Source

All configuration is loaded from environment variables. The application reads from:
1. Environment variables set in the shell
2. `.env` file (loaded by vlucas/phpdotenv)
3. Docker environment (via `env_file` or `environment` in docker-compose.yml)

## Configuration Loading

Configuration is loaded in `src/bootstrap.php`:
```php
Dotenv::createUnsafeImmutable($root)->safeLoad();
Config::initFromEnv();
```

`Config::initFromEnv()` reads all environment variables and populates the static properties in `src/Config.php`.

## Application Metadata

### APP_NAME
- **Type**: string
- **Default**: `"alerts"`
- **Purpose**: Application identifier used in logging and User-Agent header
- **Example**: `APP_NAME="weather-alerts"`

### APP_VERSION
- **Type**: string
- **Default**: `"0.1.0"`
- **Purpose**: Version number included in User-Agent header
- **Example**: `APP_VERSION="1.2.3"`

### APP_CONTACT_EMAIL
- **Type**: string
- **Default**: `"you@example.com"`
- **Purpose**: Contact email included in User-Agent header for API requests (weather.gov requires this)
- **Example**: `APP_CONTACT_EMAIL="admin@example.com"`
- **Important**: Weather.gov requests that you provide contact information in your User-Agent

## Timing and Rate Limits

### POLL_MINUTES
- **Type**: integer
- **Default**: `3`
- **Purpose**: Interval between alert polling cycles (in minutes)
- **Range**: Minimum 1 minute recommended
- **Example**: `POLL_MINUTES="5"`
- **Notes**: 
  - Too frequent polling may trigger rate limits
  - Weather.gov recommends no more than 10 requests per minute per IP

### API_RATE_PER_MINUTE
- **Type**: integer
- **Default**: `4`
- **Purpose**: Maximum HTTP requests to weather.gov per 60-second window
- **Range**: 1-10 (weather.gov limit is ~10/minute)
- **Example**: `API_RATE_PER_MINUTE="6"`
- **Notes**:
  - Enforced by `RateLimiter` class
  - Uses rolling 60-second window
  - Prevents API blocking

### PUSHOVER_RATE_SECONDS
- **Type**: integer
- **Default**: `2`
- **Purpose**: Minimum seconds between Pushover notification sends
- **Range**: Minimum 1 second
- **Example**: `PUSHOVER_RATE_SECONDS="3"`
- **Notes**:
  - Prevents overwhelming recipients
  - Pushover has its own rate limits (7,500/month on free tier)

### VACUUM_HOURS
- **Type**: integer
- **Default**: `24`
- **Purpose**: Hours between automatic database VACUUM operations
- **Range**: Minimum 1 hour
- **Example**: `VACUUM_HOURS="48"`
- **Notes**:
  - VACUUM reclaims disk space from deleted records
  - Optimizes database performance
  - Runs during scheduler loop

## Database

### DB_PATH
- **Type**: string
- **Default**: `"/data/alerts.sqlite"` (in Docker) or `__DIR__ . '/../data/alerts.sqlite'` (host)
- **Purpose**: Path to SQLite database file
- **Example**: `DB_PATH="/custom/path/alerts.db"`
- **Notes**:
  - Parent directory must exist and be writable
  - Automatically created by migrations
  - WAL mode files (.sqlite-wal, .sqlite-shm) created alongside

## Logging

### LOG_CHANNEL
- **Type**: string
- **Default**: `"stdout"`
- **Values**: `"stdout"` or `"file"`
- **Purpose**: Where to send log output
- **Example**: `LOG_CHANNEL="file"`
- **Notes**:
  - `stdout`: Logs to standard output (recommended for Docker/Dozzle)
  - `file`: Logs to `logs/app.log`

### LOG_LEVEL
- **Type**: string
- **Default**: `"info"`
- **Values**: `"debug"`, `"info"`, `"notice"`, `"warning"`, `"error"`, `"critical"`, `"alert"`, `"emergency"`
- **Purpose**: Minimum log level to record
- **Example**: `LOG_LEVEL="debug"`
- **Level Descriptions**:
  - `debug`: Detailed debugging information
  - `info`: Informational messages (normal operations)
  - `notice`: Normal but significant events
  - `warning`: Warning messages
  - `error`: Error conditions
  - `critical`: Critical conditions
  - `alert`: Action must be taken immediately
  - `emergency`: System is unusable

## Weather API

### WEATHER_API_URL
- **Type**: string
- **Default**: `"https://api.weather.gov/alerts/active"`
- **Purpose**: Endpoint for fetching active weather alerts
- **Example**: `WEATHER_API_URL="https://api.weather.gov/alerts/active?status=actual"`
- **Notes**:
  - Can add query parameters for filtering at source
  - Must return GeoJSON format
  - See [Weather.gov API docs](https://www.weather.gov/documentation/services-web-api)

### WEATHER_ALERT_CODES
- **Type**: string (comma/space/semicolon separated)
- **Default**: `""` (empty = all alerts)
- **Purpose**: Filter alerts by SAME or UGC codes
- **Example**: `WEATHER_ALERT_CODES="018097,018003"` or `WEATHER_ALERT_CODES="INZ034 INZ035"`
- **Notes**:
  - SAME codes: 6-digit FIPS codes (county-level)
  - UGC codes: Zone/county codes like INZ034
  - Case-insensitive matching
  - Multiple codes separated by comma, space, or semicolon
  - Leave empty to receive all alerts
  - Find codes: https://www.weather.gov/gis/ZoneCounty

### TIMEZONE
- **Type**: string (IANA timezone name)
- **Default**: `"America/Indianapolis"`
- **Purpose**: Timezone for localizing alert timestamps in notifications
- **Example**: `TIMEZONE="America/New_York"`
- **Notes**:
  - Must be valid IANA timezone identifier
  - Used by MessageBuilderTrait for formatting times
  - List of zones: https://www.php.net/manual/en/timezones.php

## SSL/TLS Configuration

### SSL_CERT_FILE
- **Type**: string (file path)
- **Default**: Not set
- **Purpose**: Path to CA certificate bundle for PHP
- **Example**: `SSL_CERT_FILE="certs/cacert.pem"`
- **Notes**:
  - Fixes "cURL error 60: SSL certificate problem"
  - Download from: https://curl.se/ca/cacert.pem
  - Required in some environments (Windows, custom certificates)

### CURL_CA_BUNDLE
- **Type**: string (file path)
- **Default**: Not set
- **Purpose**: Path to CA certificate bundle for cURL
- **Example**: `CURL_CA_BUNDLE="certs/cacert.pem"`
- **Notes**:
  - Should match SSL_CERT_FILE
  - Guzzle respects this environment variable

## Notification Channels

### Feature Flags

#### PUSHOVER_ENABLED
- **Type**: boolean
- **Default**: `"true"`
- **Purpose**: Enable/disable Pushover notifications
- **Example**: `PUSHOVER_ENABLED="false"`
- **Notes**:
  - Set to "false" to disable Pushover entirely
  - Still requires credentials to be set (but not used)

#### NTFY_ENABLED
- **Type**: boolean
- **Default**: `"false"`
- **Purpose**: Enable/disable ntfy notifications
- **Example**: `NTFY_ENABLED="true"`
- **Notes**:
  - Must set NTFY_TOPIC if enabled
  - Can run both Pushover and ntfy simultaneously

## Pushover Configuration

### PUSHOVER_API_URL
- **Type**: string (URL)
- **Default**: `"https://api.pushover.net/1/messages.json"`
- **Purpose**: Pushover API endpoint
- **Example**: `PUSHOVER_API_URL="https://api.pushover.net/1/messages.json"`
- **Notes**:
  - Rarely needs to be changed
  - Can point to custom proxy or mock server for testing

### PUSHOVER_USER
- **Type**: string
- **Default**: `"u-example"`
- **Purpose**: Your Pushover user key
- **Example**: `PUSHOVER_USER="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"`
- **Required**: Yes (if PUSHOVER_ENABLED=true)
- **Notes**:
  - Get from: https://pushover.net/
  - 30 characters, starts with 'u'
  - Can also be a group key

### PUSHOVER_TOKEN
- **Type**: string
- **Default**: `"t-example"`
- **Purpose**: Your Pushover application token
- **Example**: `PUSHOVER_TOKEN="azGDORePK8gMaC0QOYAMyEEuzJnyUi"`
- **Required**: Yes (if PUSHOVER_ENABLED=true)
- **Notes**:
  - Get from: https://pushover.net/apps/build
  - 30 characters, starts with 'a'
  - Create an application to get this token

## ntfy Configuration

### NTFY_BASE_URL
- **Type**: string (URL)
- **Default**: `"https://ntfy.sh"`
- **Purpose**: Base URL of ntfy server
- **Example**: `NTFY_BASE_URL="https://ntfy.example.com"`
- **Notes**:
  - Use public ntfy.sh or self-hosted instance
  - Do not include topic in URL
  - Self-hosted: https://docs.ntfy.sh/install/

### NTFY_TOPIC
- **Type**: string
- **Default**: `""` (empty)
- **Purpose**: Topic name for publishing notifications
- **Example**: `NTFY_TOPIC="weather_alerts_home"`
- **Required**: Yes (if NTFY_ENABLED=true)
- **Character Set**: Letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-) only
- **Notes**:
  - Choose unique topic name
  - Public topics are visible to anyone who guesses the name
  - Use authentication for security
  - Invalid characters will cause configuration errors

### NTFY_TOKEN
- **Type**: string
- **Default**: Not set (null)
- **Purpose**: Bearer token for authentication
- **Example**: `NTFY_TOKEN="tk_AgQdq7mVBoFD37zQVN29RhuMzNIz2"`
- **Required**: No (one of TOKEN or USER+PASSWORD for authenticated servers)
- **Notes**:
  - Preferred authentication method
  - Create token in ntfy web UI or CLI
  - Takes precedence over USER+PASSWORD if both set

### NTFY_USER
- **Type**: string
- **Default**: Not set (null)
- **Purpose**: Username for Basic authentication
- **Example**: `NTFY_USER="myusername"`
- **Required**: No (use with NTFY_PASSWORD for Basic auth)
- **Notes**:
  - Alternative to bearer token
  - Must be used with NTFY_PASSWORD
  - NTFY_TOKEN takes precedence if both set

### NTFY_PASSWORD
- **Type**: string
- **Default**: Not set (null)
- **Purpose**: Password for Basic authentication
- **Example**: `NTFY_PASSWORD="mypassword"`
- **Required**: No (use with NTFY_USER for Basic auth)
- **Notes**:
  - Alternative to bearer token
  - Must be used with NTFY_USER
  - NTFY_TOKEN takes precedence if both set

### NTFY_TITLE_PREFIX
- **Type**: string
- **Default**: Not set (null)
- **Purpose**: Prefix added to all notification titles
- **Example**: `NTFY_TITLE_PREFIX="[Weather]"`
- **Notes**:
  - Useful for distinguishing alert sources
  - Added before the event name
  - Space automatically added after prefix

## Configuration Validation

The `Config::initFromEnv()` method performs basic validation:

1. **Type Casting**: All values cast to appropriate types (int, bool, string, array)
2. **Defaults**: Missing values receive sensible defaults
3. **Boolean Parsing**: Strings "true"/"false" converted to booleans
4. **Array Parsing**: Comma/space/semicolon separated strings split into arrays
5. **Normalization**: Values trimmed and normalized

## Configuration Examples

### Minimal Configuration (Pushover Only)
```env
APP_CONTACT_EMAIL="admin@example.com"
PUSHOVER_USER="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"
PUSHOVER_TOKEN="azGDORePK8gMaC0QOYAMyEEuzJnyUi"
```

### Dual Channel Configuration
```env
APP_CONTACT_EMAIL="admin@example.com"
PUSHOVER_ENABLED="true"
PUSHOVER_USER="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"
PUSHOVER_TOKEN="azGDORePK8gMaC0QOYAMyEEuzJnyUi"
NTFY_ENABLED="true"
NTFY_TOPIC="weather_alerts"
NTFY_TOKEN="tk_AgQdq7mVBoFD37zQVN29RhuMzNIz2"
```

### Geographic Filtering
```env
APP_CONTACT_EMAIL="admin@example.com"
WEATHER_ALERT_CODES="018097,018003,INZ034,INZ035"
TIMEZONE="America/Indianapolis"
PUSHOVER_USER="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"
PUSHOVER_TOKEN="azGDORePK8gMaC0QOYAMyEEuzJnyUi"
```

### High-Volume Configuration
```env
APP_CONTACT_EMAIL="admin@example.com"
POLL_MINUTES="1"
API_RATE_PER_MINUTE="8"
PUSHOVER_RATE_SECONDS="1"
VACUUM_HOURS="12"
PUSHOVER_USER="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"
PUSHOVER_TOKEN="azGDORePK8gMaC0QOYAMyEEuzJnyUi"
```

### Debug Configuration
```env
LOG_CHANNEL="file"
LOG_LEVEL="debug"
APP_CONTACT_EMAIL="admin@example.com"
PUSHOVER_USER="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"
PUSHOVER_TOKEN="azGDORePK8gMaC0QOYAMyEEuzJnyUi"
```

## Environment-Specific Configuration

### Development
- Use `.env` file (not committed to git)
- Set `LOG_LEVEL="debug"`
- Consider `LOG_CHANNEL="file"` for easier viewing
- Lower `POLL_MINUTES` for faster testing

### Production
- Use Docker environment variables or secrets
- Set `LOG_LEVEL="info"` or `"warning"`
- Use `LOG_CHANNEL="stdout"` for log aggregation
- Set appropriate polling intervals
- Enable SSL certificate validation
- Use strong, unique credentials

### Testing
- Mock HTTP clients (not configured via env vars)
- Use in-memory database (not configured via env vars)
- Short timeouts for faster test execution

## Security Best Practices

1. **Never Commit Secrets**: Add `.env` to `.gitignore`
2. **Use Environment-Specific Files**: `.env.local`, `.env.production`
3. **Restrict File Permissions**: `chmod 600 .env`
4. **Rotate Credentials**: Change tokens periodically
5. **Use Docker Secrets**: For production Docker deployments
6. **Validate Input**: Application validates all configuration
7. **Least Privilege**: Use read-only credentials where possible

## Troubleshooting Configuration

### Check Current Configuration

View loaded configuration (not recommended for production with secrets):
```php
var_dump(Config::$pushoverUser);
echo Config::$logLevel;
```

### Common Issues

**Problem**: Configuration not loading
- **Solution**: Check `.env` file exists and is readable
- **Solution**: Verify file has no UTF-8 BOM
- **Solution**: Check for syntax errors (unquoted special characters)

**Problem**: Boolean not working
- **Solution**: Use lowercase "true"/"false" strings
- **Example**: `PUSHOVER_ENABLED="true"` not `PUSHOVER_ENABLED=true` or `PUSHOVER_ENABLED="TRUE"`

**Problem**: Array parsing
- **Solution**: Use comma, space, or semicolon separators
- **Solution**: Avoid quotes around individual items
- **Example**: `CODES="123,456"` not `CODES="123","456"`

**Problem**: Rate limits not working
- **Solution**: Ensure values are positive integers
- **Solution**: Check logs for rate limiter messages

## Adding New Configuration

To add a new configuration option:

1. Add to `.env.example` with description
2. Add static property to `Config` class
3. Add to `Config::initFromEnv()` method
4. Update this documentation
5. Add validation if necessary
6. Update tests if applicable
