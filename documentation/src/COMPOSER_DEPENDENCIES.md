# Composer Dependencies

Overview of third-party packages used in the Alerts application.

## Location
`composer.json`

## Production Dependencies (require)

### guzzlehttp/guzzle (^7.9)
**Purpose**: HTTP client for API requests  
**Used by**: WeatherClient, PushoverNotifier, NtfyNotifier  
**Features**: Async requests, middleware, streaming, error handling

### monolog/monolog (^3.6)
**Purpose**: Logging framework  
**Used by**: LoggerFactory  
**Features**: Multiple handlers, formatters, processors, PSR-3 compliant

### vlucas/phpdotenv (^5.6)
**Purpose**: Environment variable loading from .env file  
**Used by**: bootstrap.php  
**Features**: Type validation, parsing, safe loading

### symfony/console (^7.1)
**Purpose**: CLI command framework  
**Used by**: ConsoleApp, scheduler.php  
**Features**: Command routing, argument parsing, output formatting

### symfony/process (^7.1)
**Purpose**: Process management utilities  
**Used by**: Not directly used yet (included for future features)  
**Features**: Process execution, pipes, signals

### respect/validation (^2.3)
**Purpose**: Input validation library  
**Used by**: Future validation (not actively used yet)  
**Features**: Fluent validation, custom rules

### psr/log (^3.0)
**Purpose**: PSR-3 logging interface  
**Used by**: Dependency of Monolog  
**Features**: Standard logging interface

### ramsey/uuid (^4.7)
**Purpose**: UUID generation  
**Used by**: Future features (not actively used yet)  
**Features**: UUID v1, v3, v4, v5, v6, v7

## Development Dependencies (require-dev)

### phpunit/phpunit (^10.0)
**Purpose**: Testing framework  
**Used by**: tests/ directory  
**Features**: Assertions, mocks, code coverage

## PHP Requirements
- **php**: >=8.1
- **ext-pdo**: PDO extension for database
- **ext-sqlite3**: SQLite3 support (implied by pdo_sqlite)

## Autoloading
PSR-4 autoloading:
```json
"autoload": {
    "psr-4": {
        "App\\": "src/"
    }
}
```

## Scripts
Composer hooks:
```json
"scripts": {
    "post-install-cmd": ["php scripts/migrate.php"],
    "post-update-cmd": ["php scripts/migrate.php"]
}
```

Automatically runs migrations after dependency installation.

## Installation
```sh
composer install              # Install dependencies
composer install --no-dev     # Production only
composer update               # Update to latest versions
composer dump-autoload        # Regenerate autoloader
```

## Version Constraints
- `^7.9`: Allow 7.9.0 - 7.999.999 (no breaking changes)
- `^3.6`: Allow 3.6.0 - 3.999.999
- `>=8.1`: Minimum PHP version 8.1

See https://getcomposer.org/doc/ for Composer documentation.
