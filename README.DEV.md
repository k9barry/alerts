# Development Guide

This guide covers local development setup, testing, and IDE configuration for the Alerts project.

## Development Environment Setup

### Option 1: Host-Based Development (Recommended)

This approach runs Composer and PHP on your host machine, making it easier for IDEs to index code and provide autocompletion.

#### Prerequisites
- PHP 8.1 or higher
- PHP extensions: pdo, pdo_sqlite
- Composer 2.x

#### Setup Steps

1. **Install dependencies:**
```sh
composer install
```

2. **Configure environment:**
```sh
cp .env.example .env
# Edit .env to set DB_PATH=data/alerts.sqlite
```

3. **Run migrations:**
```sh
php scripts/migrate.php
```

4. **Start development scheduler:**
```sh
php scripts/scheduler.php
```

### Option 2: Container-Based Development

This approach keeps all dependencies inside Docker containers.

#### Setup Steps

1. **Build and start containers:**
```sh
docker compose up --build -d
```

2. **Install dependencies in container:**
```sh
docker run --rm -v "$PWD":/app -w /app composer:2 install
```

3. **Access container shell:**
```sh
docker exec -it alerts bash
```

## IDE Configuration

### PhpStorm

#### With Host-Based Vendor

If you ran `composer install` on your host:

1. **File → Settings → PHP**
2. Set PHP Language Level to 8.1 or higher
3. Set CLI Interpreter to your host PHP installation
4. PhpStorm will automatically index the `vendor/` directory

If autocompletion doesn't work:
- **File → Invalidate Caches / Restart**

#### With Container-Based Vendor

1. **File → Settings → PHP → CLI Interpreter**
2. Click **[+]** → **From Docker, Vagrant, etc.**
3. Select **Docker Compose**
4. Choose the `alerts` service
5. Apply and set as project interpreter
6. PhpStorm will index the container's vendor directory

### VS Code

#### With Host-Based Vendor

1. Install **PHP Intelephense** extension
2. Ensure `vendor/` is present in your workspace
3. Configure settings.json:
```json
{
  "php.suggest.basic": false,
  "intelephense.environment.phpVersion": "8.3.0"
}
```

#### With Container-Based Vendor

1. Install **Dev Containers** extension
2. Click the green icon in bottom-left corner
3. Select **Reopen in Container**
4. VS Code will run inside the container with full access to vendor/

### Other IDEs

Ensure your IDE is configured to:
- Use PHP 8.1+ language level
- Index the `vendor/` directory (either host or container)
- Support PSR-4 autoloading (most modern IDEs do automatically)

## Line Endings Configuration

The project enforces LF line endings via `.gitattributes` to prevent issues with shell scripts running in Linux containers.

### Global Git Configuration

**On Windows:**
```sh
git config --global core.autocrlf input
```

**On macOS/Linux:**
```sh
git config --global core.autocrlf input
```

### Fix Existing Files with CRLF

If you encounter `env: 'bash\r'` errors:

```sh
# Using dos2unix (if available)
dos2unix docker/entrypoint.sh scripts/*.php

# Using sed
sed -i 's/\r$//' docker/entrypoint.sh scripts/*.php
```

## Running Tests

### Full Test Suite

Run all tests with PHPUnit:
```sh
./vendor/bin/phpunit --no-coverage
```

Run with coverage report:
```sh
./vendor/bin/phpunit
```

### Lightweight Test Runner

Use the built-in smoke test runner (doesn't require PHPUnit):
```sh
php scripts/run_unit_smoke.php
```

### Run Specific Tests

```sh
# Single test class
./vendor/bin/phpunit tests/PushoverRetryAndFailureTest.php

# Single test method
./vendor/bin/phpunit --filter testRetryLogic tests/PushoverRetryAndFailureTest.php
```

## Development Scripts

### One-Time Poll

Execute a single poll cycle without starting the continuous scheduler:
```sh
php scripts/oneshot_poll.php
```

This script:
- Fetches current alerts from weather.gov
- Stores them in `incoming_alerts`
- Identifies new alerts
- Queues them in `pending_alerts`
- Processes and sends notifications
- Replaces `active_alerts` with `incoming_alerts`

### Development Test Script

Run internal development tests:
```sh
php scripts/dev_test.php
```

### Database Migration

Run migrations manually (usually runs automatically):
```sh
php scripts/migrate.php
```

### Documentation Link Checker

Verify all internal markdown links are valid:
```sh
php scripts/check_docs_links.php
```

This checks:
- All links in `documentation/**/*.md`
- Links to other markdown files
- Relative paths
- Anchor links (heading targets)

## Project Structure

```
alerts/
├── src/                    # Source code (PSR-4 autoloaded as App\)
│   ├── Config.php         # Configuration container
│   ├── bootstrap.php      # Application initialization
│   ├── DB/                # Database layer
│   │   └── Connection.php # PDO singleton
│   ├── Http/              # HTTP clients
│   │   ├── RateLimiter.php
│   │   └── WeatherClient.php
│   ├── Logging/           # Logging setup
│   │   └── LoggerFactory.php
│   ├── Repository/        # Data access
│   │   └── AlertsRepository.php
│   ├── Service/           # Business logic
│   │   ├── AlertFetcher.php
│   │   ├── AlertProcessor.php
│   │   ├── MessageBuilderTrait.php
│   │   ├── NtfyNotifier.php
│   │   └── PushoverNotifier.php
│   └── Scheduler/         # Console commands
│       └── ConsoleApp.php
├── scripts/               # Executable scripts
│   ├── scheduler.php      # Main scheduler entry point
│   ├── migrate.php        # Database migrations
│   ├── oneshot_poll.php   # Single poll cycle
│   ├── oneshot_test.php   # Test script
│   ├── dev_test.php       # Development tests
│   ├── run_unit_smoke.php # Lightweight test runner
│   └── check_docs_links.php # Documentation validator
├── tests/                 # PHPUnit tests
│   ├── bootstrap.php      # Test bootstrap
│   ├── Mocks/            # Test mocks
│   └── *Test.php         # Test classes
├── public/               # Web root
│   └── index.php         # Web entry point (currently returns 404)
├── docker/               # Docker configuration
│   └── entrypoint.sh     # Container entrypoint script
├── documentation/        # Generated documentation
├── data/                 # SQLite database (created at runtime)
├── logs/                 # Log files (if LOG_CHANNEL=file)
└── vendor/              # Composer dependencies
```

## Coding Standards

### PSR Standards

This project follows:
- **PSR-1**: Basic Coding Standard
- **PSR-4**: Autoloading Standard
- **PSR-12**: Extended Coding Style Guide

### PHP Version

- **Minimum**: PHP 8.1
- **Recommended**: PHP 8.3
- Use type declarations for all parameters and return types
- Use readonly properties where appropriate
- Use constructor property promotion

### Naming Conventions

- **Classes**: PascalCase (e.g., `AlertProcessor`)
- **Methods**: camelCase (e.g., `fetchAndStoreIncoming`)
- **Properties**: camelCase (e.g., `$apiRatePerMinute`)
- **Constants**: SCREAMING_SNAKE_CASE (e.g., `MAX_RETRIES`)

### Code Organization

- **One class per file**
- **Final classes by default** unless inheritance is needed
- **Dependency injection** over service locators
- **Composition over inheritance**

## Debugging

### View Logs

#### In Docker (Dozzle)
Visit http://localhost:9999 to view real-time logs with:
- JSON syntax highlighting
- Filtering by level
- Full-text search
- Container selection

#### From Command Line
```sh
# Follow logs from all containers
docker compose logs -f

# Follow logs from specific container
docker compose logs -f alerts

# View last 100 lines
docker compose logs --tail=100 alerts
```

### Database Inspection

#### SQLite Browser Web UI
Visit http://localhost:3000 to:
- Browse all tables
- Execute SQL queries
- View table schemas
- Export data to CSV/JSON

#### Command Line
```sh
# Access SQLite CLI
sqlite3 data/alerts.sqlite

# Useful queries
SELECT COUNT(*) FROM incoming_alerts;
SELECT COUNT(*) FROM pending_alerts;
SELECT * FROM sent_alerts ORDER BY notified_at DESC LIMIT 10;

# View schema
.schema incoming_alerts
```

### Add Debug Logging

Use the logger in any class:
```php
use App\Logging\LoggerFactory;

$logger = LoggerFactory::get();
$logger->debug('Debug message', ['context' => $data]);
$logger->info('Info message', ['key' => 'value']);
$logger->error('Error message', ['exception' => $e->getMessage()]);
```

Set `LOG_LEVEL=debug` in `.env` to see debug messages.

## Common Development Tasks

### Add a New Service Class

1. Create file in `src/Service/`
2. Use `namespace App\Service;`
3. Add type hints for all parameters and returns
4. Inject dependencies via constructor
5. Add corresponding test in `tests/`

### Add a New Console Command

Edit `src/Scheduler/ConsoleApp.php` and add a new command:

```php
$app->add(new class('my-command') extends Command {
    protected static $defaultName = 'my-command';
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Your command logic
        return Command::SUCCESS;
    }
});
```

### Modify Database Schema

Edit `scripts/migrate.php`:
1. Add columns to `$alertColumns` array (for all tables)
2. Or add to table-specific extra columns in `$tablesToEnsure`
3. Run `php scripts/migrate.php` to apply changes

The migration script is idempotent and safe to run multiple times.

### Add Environment Variable

1. Add to `.env.example` with description
2. Add property to `src/Config.php`
3. Add initialization in `Config::initFromEnv()`
4. Update relevant documentation

## Troubleshooting

### Autocompletion Not Working

- Ensure `vendor/` directory exists and is indexed by your IDE
- Run `composer dump-autoload` to regenerate autoload files
- Restart your IDE
- Clear IDE caches (PhpStorm: File → Invalidate Caches)

### Database Locked Errors

- Ensure only one scheduler instance is running
- Check for zombie processes: `ps aux | grep php`
- Stop all schedulers and restart: `docker compose restart alerts`

### Tests Failing

- Ensure test database is writable: `chmod 775 data`
- Check test isolation - tests should not depend on each other
- Run tests individually to isolate failures
- Check mocks are configured correctly

### Docker Build Failures

- Clear Docker cache: `docker system prune -a`
- Ensure you have enough disk space
- Check Docker daemon is running
- Try building without cache: `docker compose build --no-cache`

## Contributing

When contributing code:

1. **Create a feature branch**: `git checkout -b feature/your-feature`
2. **Write tests** for new functionality
3. **Run tests** to ensure nothing breaks: `./vendor/bin/phpunit`
4. **Check documentation** links: `php scripts/check_docs_links.php`
5. **Update documentation** if you changed APIs or behavior
6. **Commit with clear messages**: Follow conventional commit format
7. **Push and create PR**: Include description of changes

### Pull Request Guidelines

- Keep changes focused and atomic
- Include tests for new features
- Update documentation as needed
- Ensure tests pass
- Follow existing code style
- Add clear PR description explaining the change

## Additional Resources

- [PHP Documentation](https://www.php.net/docs.php)
- [Composer Documentation](https://getcomposer.org/doc/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Monolog Documentation](https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md)
- [Guzzle Documentation](https://docs.guzzlephp.org/)
- [Weather.gov API](https://www.weather.gov/documentation/services-web-api)
