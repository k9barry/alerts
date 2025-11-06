# Development Guide

This guide provides detailed instructions for setting up a local development environment, running tests, and contributing to the Alerts weather notification system.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Local Development Setup](#local-development-setup)
- [Running the Application](#running-the-application)
- [Testing](#testing)
- [Development Workflow](#development-workflow)
- [Code Quality](#code-quality)
- [IDE Configuration](#ide-configuration)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)

## Prerequisites

### Required Software

- **PHP**: Version 8.1 or higher (8.2+ recommended)
- **Composer**: Latest version for dependency management
- **Docker & Docker Compose**: For containerized development (optional but recommended)
- **Git**: For version control

### PHP Extensions

Required PHP extensions:
- `pdo` - PDO database abstraction layer
- `pdo_sqlite` - SQLite database driver
- `json` - JSON support
- `mbstring` - Multibyte string handling
- `curl` - HTTP client support

Check your PHP version and extensions:
```bash
php -v
php -m | grep -E "(pdo|sqlite|json|mbstring|curl)"
```

### System Requirements

- **Operating System**: Linux, macOS, or Windows with WSL2
- **Memory**: 512MB minimum (1GB recommended)
- **Disk Space**: 100MB for dependencies and data

## Local Development Setup

### 1. Clone the Repository

```bash
git clone https://github.com/k9barry/alerts.git
cd alerts
```

### 2. Install Dependencies

Install PHP dependencies using Composer:

```bash
composer install
```

This will install:
- Guzzle HTTP client
- Monolog logging framework
- Symfony Console and Process components
- PHPUnit testing framework
- Other required packages

### 3. Configure Environment

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` and configure at minimum:

```env
# Application
APP_NAME="alerts"
APP_CONTACT_EMAIL="dev@example.com"

# Database (for local development)
DB_PATH="data/alerts.sqlite"

# Logging
LOG_CHANNEL="stdout"
LOG_LEVEL="debug"

# Timezone
TIMEZONE="America/New_York"

# Notification channels (use test credentials)
PUSHOVER_ENABLED="true"
NTFY_ENABLED="true"
```

### 4. Create Required Directories

```bash
mkdir -p data logs
chmod 775 data logs
```

### 5. Initialize Database

Run database migrations:

```bash
php scripts/migrate.php
```

### 6. Download Weather Zones Data (Optional)

```bash
php scripts/check_zones_data.php
```

Or manually download:

```bash
php scripts/download_zones.php
```

## Running the Application

### Run with Docker (Recommended)

Start all services:

```bash
docker compose up --build
```

Services available:
- **Web UI**: http://localhost:8080
- **Dozzle (Logs)**: http://localhost:9999
- **SQLite Browser**: http://localhost:3000

Stop services:

```bash
docker compose down
```

### Run Locally (Without Docker)

#### Start the Web Server

```bash
php -S localhost:8080 -t public
```

Access at: http://localhost:8080

#### Run the Scheduler

In a separate terminal:

```bash
php scripts/scheduler.php
```

#### One-Time Poll (Testing)

Execute a single poll cycle:

```bash
php scripts/oneshot_poll.php
```

## Testing

### Run All Tests

```bash
./vendor/bin/phpunit
```

Or without coverage:

```bash
./vendor/bin/phpunit --no-coverage
```

### Run Specific Test Files

```bash
./vendor/bin/phpunit tests/AlertWorkflowTest.php
./vendor/bin/phpunit tests/NtfyNotifierTest.php
```

### Lightweight Test Runner

For quick unit and smoke tests:

```bash
php scripts/run_unit_smoke.php
```

### Interactive Alert Workflow Test

Test the complete alert workflow with a real alert:

```bash
php scripts/test_alert_workflow.php
```

Or in Docker:

```bash
docker exec -it alerts php scripts/test_alert_workflow.php
```

This interactive script will:
1. Fetch current alerts from weather.gov
2. Select a random alert
3. Prompt you to choose a user
4. Send test notifications
5. Show detailed results

### Test Individual Components

Test functionality interactively:

```bash
php scripts/test_functionality.php
```

One-shot test with debugging:

```bash
php scripts/oneshot_test.php
```

## Development Workflow

### 1. Create a Feature Branch

```bash
git checkout -b feature/your-feature-name
```

### 2. Make Changes

Follow the project structure:
- **`src/`** - Application source code
- **`tests/`** - PHPUnit tests
- **`scripts/`** - Executable PHP scripts
- **`public/`** - Web root
- **`documentation/`** - Documentation files

### 3. Test Your Changes

Run tests frequently:

```bash
./vendor/bin/phpunit
php scripts/check_docs_links.php
```

### 4. Check Syntax

Validate PHP syntax:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

### 5. Commit Changes

```bash
git add .
git commit -m "Description of changes"
```

### 6. Push and Create Pull Request

```bash
git push origin feature/your-feature-name
```

Then create a pull request on GitHub.

## Code Quality

### PHP Syntax Check

Check all PHP files for syntax errors:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

### Documentation Link Checker

Verify all internal documentation links:

```bash
php scripts/check_docs_links.php
```

### Code Style

Follow PSR-12 coding standards:
- 4 spaces for indentation (no tabs)
- Opening braces on new line for classes and methods
- Strict types declaration in all PHP files
- Type hints for all parameters and return values

### Logging Best Practices

Use structured logging with context:

```php
$logger->info('Processing alert', [
    'alert_id' => $alertId,
    'event' => $event,
    'severity' => $severity
]);
```

Log levels:
- **debug**: Detailed debugging information
- **info**: Informational messages
- **notice**: Normal but significant conditions
- **warning**: Warning conditions
- **error**: Error conditions
- **critical**: Critical conditions

## IDE Configuration

### Visual Studio Code

Recommended extensions:
- PHP Intelephense
- PHP Debug
- Docker
- SQLite Viewer

Create `.vscode/settings.json`:

```json
{
  "php.validate.executablePath": "/usr/bin/php",
  "php.suggest.basic": false,
  "intelephense.files.exclude": [
    "**/.git/**",
    "**/.svn/**",
    "**/.hg/**",
    "**/CVS/**",
    "**/.DS_Store/**",
    "**/node_modules/**",
    "**/bower_components/**",
    "**/vendor/**/{Tests,tests}/**"
  ]
}
```

### PHPStorm

1. Configure PHP interpreter: Settings → PHP → CLI Interpreter
2. Enable Composer: Settings → PHP → Composer
3. Configure PHPUnit: Settings → PHP → Test Frameworks
4. Set code style to PSR-12: Settings → Editor → Code Style → PHP

## Troubleshooting

### Database Locked Errors

Ensure only one instance of the scheduler is running:

```bash
ps aux | grep scheduler.php
# Kill any running instances
pkill -f scheduler.php
```

### Permission Issues

Fix directory permissions:

```bash
chmod -R 775 data logs
```

### Composer Issues

Clear Composer cache:

```bash
composer clear-cache
composer install
```

### Port Already in Use

Change the port when starting PHP server:

```bash
php -S localhost:8081 -t public
```

Or update `docker-compose.yml` port mappings.

### SSL Certificate Errors

Download CA bundle for local development:

```bash
mkdir -p certs
curl -o certs/cacert.pem https://curl.se/ca/cacert.pem
```

Update `.env`:

```env
SSL_CERT_FILE=certs/cacert.pem
CURL_CA_BUNDLE=certs/cacert.pem
```

### Test Failures

Run tests with verbose output:

```bash
./vendor/bin/phpunit --verbose
```

Check for database state issues:

```bash
# Remove test database if it exists
rm -f data/alerts.sqlite*
php scripts/migrate.php
./vendor/bin/phpunit
```

### API Rate Limiting

If you hit weather.gov rate limits during testing:
- Increase `POLL_MINUTES` in `.env`
- Use cached test data in tests
- Wait 60 seconds between manual API calls

## Contributing

### Before Submitting a Pull Request

1. **Run all tests**: `./vendor/bin/phpunit`
2. **Check documentation links**: `php scripts/check_docs_links.php`
3. **Verify syntax**: `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;`
4. **Update documentation** if you added features
5. **Add tests** for new functionality

### Commit Message Format

Use clear, descriptive commit messages:

```
Add user zone filtering feature

- Implement zone selection in user interface
- Add database migration for user_zones table
- Update AlertProcessor to filter by user zones
- Add tests for zone filtering logic
```

### Pull Request Checklist

- [ ] Tests pass locally
- [ ] Documentation updated
- [ ] Code follows PSR-12 style
- [ ] No broken internal links
- [ ] New features have tests
- [ ] Commits are logical and well-described

## Additional Resources

- [Main README](README.md) - Overview and quick start
- [Installation Guide](INSTALL.md) - Detailed setup instructions
- [Documentation Index](documentation/INDEX.md) - Complete documentation
- [Architecture](documentation/overview/ARCHITECTURE.md) - System design
- [Database Schema](documentation/overview/DATABASE.md) - Database structure

## Getting Help

- **Issues**: https://github.com/k9barry/alerts/issues
- **Pull Requests**: https://github.com/k9barry/alerts/pulls
- **Discussions**: https://github.com/k9barry/alerts/discussions

## License

MIT License - see [LICENSE](LICENSE) file for details.
