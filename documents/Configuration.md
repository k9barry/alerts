# Configuration Documentation

## Overview

The application uses environment variables for configuration, allowing easy customization without modifying code.

## Environment Variables

### Application Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `alerts` | Application name used in logs and User-Agent |
| `APP_VERSION` | `1.0.0` | Application version for User-Agent |
| `APP_ENV` | `development` | Environment: `development`, `staging`, or `production` |

### Contact Information

| Variable | Required | Description |
|----------|----------|-------------|
| `CONTACT_EMAIL` | **Yes** | Contact email for API User-Agent (required by weather.gov) |

**Important**: You must set `CONTACT_EMAIL` to your actual email address. This is required by the National Weather Service API terms of use.

### Database Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_PATH` | `/app/data/alerts.db` | Full path to SQLite database file |

**Docker**: The default path `/app/data/alerts.db` is mapped to `./data/alerts.db` on the host.

**Local**: Use `./data/alerts.db` for local development.

### API Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `API_BASE_URL` | `https://api.weather.gov/alerts` | Base URL for weather.gov alerts API |
| `API_RATE_LIMIT` | `4` | Maximum API calls per period |
| `API_RATE_PERIOD` | `60` | Rate limit period in seconds |

**Rate Limiting**: The default is 4 calls per 60 seconds (4 per minute). Do not exceed weather.gov's rate limits to avoid being blocked.

### Logging Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `LOG_LEVEL` | `DEBUG` | Minimum log level: DEBUG, INFO, WARNING, ERROR |
| `LOG_PATH` | `/app/logs/alerts.log` | Full path to log file |

**Log Levels**:
- `DEBUG`: Detailed diagnostic information (development)
- `INFO`: General informational messages (production)
- `WARNING`: Warning messages
- `ERROR`: Error conditions only

**Docker**: The default path `/app/logs/alerts.log` is mapped to `./logs/alerts.log` on the host.

**Local**: Use `./logs/alerts.log` for local development.

### Timezone

| Variable | Default | Description |
|----------|---------|-------------|
| `TZ` | `America/New_York` | Timezone for timestamps |

---

## Configuration Files

### .env File

The `.env` file in the root directory contains environment-specific configuration.

**Setup**:
```bash
cp .env.example .env
nano .env  # Edit with your values
```

**Example**:
```env
APP_NAME=alerts
APP_VERSION=1.0.0
APP_ENV=development

CONTACT_EMAIL=your-email@example.com

DB_PATH=/app/data/alerts.db

API_BASE_URL=https://api.weather.gov/alerts
API_RATE_LIMIT=4
API_RATE_PERIOD=60

LOG_LEVEL=DEBUG
LOG_PATH=/app/logs/alerts.log

TZ=America/New_York
```

### config/config.php

This file loads environment variables and provides them to the application as a structured array.

**Features**:
- Loads `.env` file automatically
- Provides fallback defaults
- Returns configuration as array
- Type conversion (strings to integers where needed)

**Usage in Code**:
```php
$config = require __DIR__ . '/config/config.php';

// Access values
echo $config['app']['name'];        // alerts
echo $config['contact']['email'];   // your-email@example.com
echo $config['api']['rate_limit'];  // 4
```

---

## Environment-Specific Configuration

### Development Environment

```env
APP_ENV=development
LOG_LEVEL=DEBUG
```

**Characteristics**:
- Verbose logging
- Detailed error messages
- All log messages captured

### Staging Environment

```env
APP_ENV=staging
LOG_LEVEL=INFO
```

**Characteristics**:
- Moderate logging
- Important events logged
- Test with production-like settings

### Production Environment

```env
APP_ENV=production
LOG_LEVEL=WARNING
```

**Characteristics**:
- Minimal logging
- Only warnings and errors
- Best performance

---

## Docker-Specific Configuration

### docker-compose.yml

Environment variables can be set in `docker-compose.yml`:

```yaml
services:
  alerts:
    environment:
      - APP_ENV=${APP_ENV:-development}
      - LOG_LEVEL=${LOG_LEVEL:-DEBUG}
```

This allows:
- Using `.env` file values
- Providing defaults with `:-` syntax
- Overriding at runtime

### Runtime Override

Override at runtime:
```bash
APP_ENV=production LOG_LEVEL=WARNING docker compose up
```

---

## Security Considerations

### Sensitive Data

**Never commit**:
- `.env` file
- Database files (`.db`, `.sqlite`)
- Log files containing sensitive information

**Protected by .gitignore**:
```
.env
*.db
*.sqlite
logs/*.log
```

### Email Address

- Use a real, monitored email address
- Weather.gov may contact you about API issues
- Required by API terms of service

### Database Permissions

Set appropriate permissions:
```bash
chmod 755 data/
chmod 644 data/alerts.db
chmod 755 logs/
chmod 644 logs/*.log
```

---

## Validation

### Check Configuration

Verify configuration is loaded correctly:

```bash
php -r "
\$config = require 'config/config.php';
print_r(\$config);
"
```

### Test Environment Variables

```bash
# Check if .env is loaded
php -r "
require 'config/config.php';
echo getenv('CONTACT_EMAIL') . PHP_EOL;
"
```

### Verify Paths

```bash
# Check database path
php -r "
\$config = require 'config/config.php';
echo \$config['database']['path'] . PHP_EOL;
"

# Check log path
php -r "
\$config = require 'config/config.php';
echo \$config['logging']['path'] . PHP_EOL;
"
```

---

## Troubleshooting

### Configuration Not Loading

**Symptom**: Default values used instead of .env values

**Solutions**:
1. Ensure `.env` file exists in root directory
2. Check file permissions: `chmod 644 .env`
3. Verify format: `KEY=value` (no spaces around `=`)
4. No quotes needed for values

### Invalid Email Warning

**Symptom**: API requests fail or receive warnings

**Solution**: Set a valid email address in `CONTACT_EMAIL`

### Path Errors

**Symptom**: "No such file or directory" errors

**Solutions**:
1. Use absolute paths in `.env` for Docker
2. Use relative paths for local development
3. Ensure directories exist and are writable

### Log Level Too Verbose

**Symptom**: Too many log messages

**Solution**: Increase log level:
```env
LOG_LEVEL=WARNING  # or ERROR
```

### Rate Limit Exceeded

**Symptom**: "Rate limit" messages in logs

**Solutions**:
1. Don't modify `API_RATE_LIMIT` to exceed weather.gov's limits
2. Wait for rate limit window to reset
3. Reduce API call frequency

---

## Best Practices

1. **Always use .env**: Don't hardcode configuration
2. **Document changes**: Update `.env.example` when adding variables
3. **Use appropriate log levels**: DEBUG for dev, WARNING for production
4. **Respect rate limits**: Don't exceed API limits
5. **Monitor logs**: Check for configuration issues
6. **Backup .env**: Keep a secure backup of production config
7. **Rotate secrets**: Change sensitive values periodically
8. **Test configuration**: Verify before deploying

---

## Related Documentation

- [INSTALL.md](../INSTALL.md) - Installation instructions
- [README.md](../README.md) - General documentation
- [Database.md](Database.md) - Database configuration
- [ApiClient.md](ApiClient.md) - API configuration
