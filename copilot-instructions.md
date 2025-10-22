# Copilot Instructions

This file provides guidance for GitHub Copilot when working with this codebase.

## Project Overview

This is a PHP-based weather alerts system that:
- Fetches weather alerts from the NWS API (https://api.weather.gov/alerts)
- Stores alerts in a SQLite database
- Runs in Docker containers
- Uses Monolog for logging
- Implements rate limiting (4 calls per minute)
- Provides web interfaces for logs (Dozzle) and database (SQLite Browser)

## Architecture

### Main Components

1. **ApiClient** (`src/ApiClient.php`)
   - Handles API communication with weather.gov
   - Implements rate limiting
   - Manages User-Agent headers
   - Records API call statistics

2. **Database** (`src/Database.php`)
   - Manages SQLite database connections
   - Creates and maintains schema
   - Handles CRUD operations for alerts
   - Tracks API calls for rate limiting

3. **LoggerFactory** (`src/LoggerFactory.php`)
   - Creates configured Monolog instances
   - Sets up multiple handlers (file, stdout, error_log)
   - Formats log messages

4. **app.php** (`src/app.php`)
   - Main application entry point
   - Orchestrates fetching and storing alerts
   - Handles errors and logging

## Coding Standards

### PHPDoc Comments

All functions must have PHPDoc blocks with:
- Description
- `@param` for each parameter with type and description
- `@return` with type and description
- `@throws` for exceptions

Example:
```php
/**
 * Fetch alerts from the API
 *
 * @param array $params Query parameters for the API
 * @return array|null Array of alerts or null on failure
 */
```

### Error Handling

- Use try-catch blocks for potential exceptions
- Log all errors with appropriate severity
- Return null or false on failure (don't throw exceptions to caller)
- Provide meaningful error messages

### Logging

Use appropriate log levels:
- `DEBUG`: Detailed diagnostic information
- `INFO`: Significant events (API calls, record counts)
- `WARNING`: Unexpected but recoverable situations
- `ERROR`: Critical errors that prevent operations

### Security

- Never commit sensitive data (emails, API keys)
- Use environment variables for configuration
- Sanitize all inputs
- Use prepared statements for SQL
- Enable SQLite security features (WAL, foreign keys)

## Database Schema

### alerts table
Main storage for alert data from the API. Fields map to the NWS API response structure.

### alert_zones table
Many-to-many relationship between alerts and affected geographic zones.

### api_calls table
Tracks API usage for rate limiting and monitoring.

## Rate Limiting

The application enforces rate limiting by:
1. Recording each API call in the `api_calls` table
2. Checking recent call count before each request
3. Sleeping if rate limit is reached
4. Cleaning up old records periodically

## Docker Configuration

### Services
- **alerts**: Main PHP application (runs continuously)
- **sqlitebrowser**: Web UI for database inspection
- **dozzle**: Real-time log viewer

### Volumes
- `./data`: SQLite database storage (persistent)
- `./logs`: Application logs (persistent)

## Development Guidelines

### Adding New Features

1. Update relevant class with PHPDoc comments
2. Add error handling and logging
3. Update configuration if needed
4. Document in `documents/` folder
5. Update README.md if user-facing

### Modifying Database Schema

1. Update `Database::initializeSchema()`
2. Consider migration path for existing databases
3. Update documentation
4. Test with existing data

### API Changes

1. Respect rate limiting
2. Use proper User-Agent
3. Handle all HTTP error codes
4. Parse JSON safely
5. Log all API interactions

## Testing Locally

```bash
# Start services
docker-compose up -d

# View logs
docker-compose logs -f alerts

# Access services
# Dozzle: http://localhost:8080
# SQLite Browser: http://localhost:3000

# Stop services
docker-compose down
```

## Common Tasks

### Add a New Configuration Option

1. Add to `.env.example`
2. Add to `config/config.php`
3. Use via `$config` array
4. Document in README.md

### Add a New Database Field

1. Update schema in `Database::initializeSchema()`
2. Update `Database::upsertAlert()` to handle the field
3. Test with existing data

### Change Logging

1. Modify `LoggerFactory::create()`
2. Add new handlers or formatters
3. Test output in Dozzle

## File Structure

```
alerts/
├── src/                    # PHP source code
│   ├── ApiClient.php       # API communication
│   ├── Database.php        # Database operations
│   ├── LoggerFactory.php   # Logging setup
│   └── app.php             # Main application
├── config/                 # Configuration
│   └── config.php          # Config loader
├── data/                   # SQLite database (gitignored)
├── logs/                   # Log files (gitignored)
├── documents/              # Documentation
│   └── Copilot-Create.md   # Original requirements
├── docker-compose.yml      # Service definitions
├── Dockerfile              # Application image
├── composer.json           # PHP dependencies
├── .env.example            # Environment template
├── .gitignore              # Git exclusions
├── README.md               # Main documentation
├── INSTALL.md              # Installation guide
└── copilot-instructions.md # This file
```

## Dependencies

- **PHP 8.1+**: Modern PHP features
- **Monolog**: Structured logging
- **PDO SQLite**: Database access
- **cURL**: HTTP client

## Best Practices

1. **Always check logs** before and after changes
2. **Test rate limiting** to avoid API bans
3. **Validate JSON** before processing
4. **Use prepared statements** for SQL
5. **Log errors** before returning failure
6. **Keep functions focused** on single responsibility
7. **Document all public methods** with PHPDoc
8. **Handle all exceptions** gracefully
9. **Use type hints** for all parameters and returns
10. **Follow PSR-12** coding standards

## Resources

- [Weather.gov API Docs](https://www.weather.gov/documentation/services-web-api)
- [Monolog Documentation](https://github.com/Seldaek/monolog)
- [Docker Compose Docs](https://docs.docker.com/compose/)
- [SQLite Documentation](https://www.sqlite.org/docs.html)
