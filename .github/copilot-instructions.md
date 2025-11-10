# Copilot Instructions for Alerts Repository

## Project Overview

This is a Dockerized PHP 8.3 application that monitors weather.gov alerts, stores them in SQLite, intelligently detects new alerts, and sends notifications through multiple channels (Pushover and ntfy). The application uses a clean layered architecture with automated polling, smart rate limiting, and per-user geographic filtering.

## Technology Stack

- **Language**: PHP 8.1+ (8.3 in production)
- **Database**: SQLite with WAL mode
- **Dependencies**: Managed via Composer
- **Testing**: PHPUnit 10
- **Containerization**: Docker & Docker Compose
- **Key Libraries**:
  - Guzzle HTTP client for API calls
  - Monolog for structured JSON logging
  - Symfony Console for CLI commands
  - Respect/Validation for data validation

## Architecture

The application follows a clean layered architecture:
- **Config** (`src/Config/`): Centralized configuration from environment variables
- **DB** (`src/DB/`): PDO-based SQLite connection with WAL mode
- **Http** (`src/Http/`): Weather API client with rate limiting
- **Logging** (`src/Logging/`): Structured JSON logging setup
- **Repository** (`src/Repository/`): Data access layer for alert tables
- **Service** (`src/Service/`): Business logic for fetching, processing, and notifying
- **Scheduler** (`src/Scheduler/`): Console commands and continuous scheduler loop
- **Web** (`public/`): User management CRUD interface

## Build & Test Commands

### Install Dependencies
```bash
composer install
```

### Run Tests
```bash
./vendor/bin/phpunit --no-coverage
# Or use the lightweight runner:
php scripts/run_unit_smoke.php
```

### Syntax Validation
```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

### Documentation Link Checker
```bash
php scripts/check_docs_links.php
```

### Run with Docker
```bash
docker compose up --build
```

### Run Locally
```bash
# Web server
php -S localhost:8080 -t public

# Scheduler (in separate terminal)
php scripts/scheduler.php

# One-time poll for testing
php scripts/oneshot_poll.php
```

## Code Style & Conventions

- **Standard**: Follow PSR-12 coding standards
- **Indentation**: 4 spaces (no tabs)
- **Braces**: Opening braces on new line for classes and methods
- **Type Hints**: Use strict types (`declare(strict_types=1)`) in all PHP files
- **Type Declarations**: All parameters and return values must have type hints
- **Logging**: Use structured logging with context arrays
- **Namespacing**: PSR-4 autoloading with `App\` namespace

### Example Code Style
```php
<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class ExampleService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function processData(string $input): array
    {
        $this->logger->info('Processing data', [
            'input_length' => strlen($input)
        ]);
        
        return ['status' => 'success'];
    }
}
```

## Database

- **Type**: SQLite with WAL (Write-Ahead Logging) mode
- **Location**: `data/alerts.sqlite`
- **Migrations**: Run via `php scripts/migrate.php`
- **Tables**:
  - `incoming_alerts`: Snapshot of latest API fetch
  - `active_alerts`: Currently tracked alerts
  - `pending_alerts`: New alerts queued for notification
  - `sent_alerts`: Historical record of dispatched notifications
  - `zones`: NWS weather zones with geographic information
  - `users`: User profiles with notification preferences and zone subscriptions

## Key Directories

- **`src/`**: Application source code (PSR-4 autoloaded)
- **`tests/`**: PHPUnit test files
- **`scripts/`**: Executable PHP scripts (scheduler, migrations, utilities)
- **`public/`**: Web root for user management interface
- **`documentation/`**: Comprehensive documentation in Markdown
- **`data/`**: SQLite database and runtime data (not in git)
- **`logs/`**: Application logs (not in git)
- **`vendor/`**: Composer dependencies (not in git)
- **`docker/`**: Docker configuration files
- **`certs/`**: SSL certificates for API calls

## Configuration

- **Environment**: Configuration via `.env` file (see `.env.example`)
- **Required Settings**: `TIMEZONE`, notification channel credentials
- **User Settings**: Managed through web UI at `http://localhost:8080`
- **Geographic Filtering**: Per-user zone selection via web interface

## Testing Guidelines

1. **Always run tests before committing**: `./vendor/bin/phpunit --no-coverage`
2. **Test new features**: Add PHPUnit tests for new functionality
3. **Use test scripts**: Leverage `scripts/test_*.php` for interactive testing
4. **Mock external APIs**: Don't make real API calls in tests
5. **Database isolation**: Tests use separate test database

## Notification Channels

The application supports two notification channels:
1. **Pushover**: Requires `PUSHOVER_USER` and `PUSHOVER_TOKEN`
2. **ntfy**: Requires `NTFY_TOPIC` configuration

Configuration is per-user through the web interface, with credentials stored in the `users` table.

## Development Workflow

1. **Create feature branch**: `git checkout -b feature/your-feature`
2. **Make minimal changes**: Focus on small, targeted modifications
3. **Test frequently**: Run tests after each significant change
4. **Check syntax**: `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;`
5. **Verify docs**: `php scripts/check_docs_links.php`
6. **Update documentation**: Keep docs in sync with code changes
7. **Commit logically**: Use clear, descriptive commit messages

## CI/CD

The repository uses GitHub Actions CI (`ci.yml`) which:
1. Sets up PHP 8.2 with required extensions
2. Installs Composer dependencies with caching
3. Runs PHP syntax validation
4. Checks documentation links
5. Executes PHPUnit test suite

All tests must pass before merging to `main`.

## Common Tasks

### Adding a New Service
1. Create class in appropriate `src/` subdirectory
2. Follow PSR-4 namespace conventions
3. Use dependency injection via constructor
4. Add type hints for all parameters and returns
5. Include PHPUnit tests in `tests/`

### Modifying Database Schema
1. Create migration in `scripts/migrate.php`
2. Update repository classes in `src/Repository/`
3. Test migration thoroughly
4. Document changes in schema documentation

### Adding New Scripts
1. Place in `scripts/` directory
2. Use strict types declaration
3. Include proper error handling
4. Add to documentation if user-facing

## Documentation

- **Main README**: `README.md` - Quick start and overview
- **Development Guide**: `README.DEV.md` - Detailed dev setup
- **Installation**: `INSTALL.md` - Production setup
- **Full Docs**: `documentation/INDEX.md` - Complete documentation index

Always update relevant documentation when making changes to features, APIs, or configuration options.

## Important Notes

- **Minimal Changes**: Make the smallest possible changes to achieve goals
- **Don't Break Tests**: Existing tests should continue to pass
- **Rate Limiting**: Respect weather.gov API limits (4 requests/minute)
- **Geographic Filtering**: Alert filtering is per-user via zone selection, not environment variables
- **Automatic Zones**: The application automatically downloads NWS zones data on startup if needed
- **Backup Safety**: User table changes are automatically backed up to JSON files
