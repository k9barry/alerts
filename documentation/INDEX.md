# Documentation Index

Complete documentation for the Alerts weather notification system.

## Quick Start

- [Main README](../README.md) - Overview and quick start guide
- [Installation Guide](../INSTALL.md) - Detailed setup instructions
- [Development Guide](../README.DEV.md) - Local development setup
- [Changelog](../CHANGELOG.md) - Version history and change log

## Overview Documentation

High-level system design and concepts:

- [Architecture](./overview/ARCHITECTURE.md) - System design, layers, data flow, patterns
- [Configuration](./overview/CONFIGURATION.md) - All environment variables and settings
- [Database Schema](./overview/DATABASE.md) - Tables, columns, queries, maintenance
- [Runtime & Scheduler](./overview/RUNTIME.md) - Execution flow, scheduler operation, process management

## Scripts Documentation

Executable PHP scripts:

- [check_zones_data.php](./scripts/check_zones_data.md) - Automated zone data download during startup
- [cleanup_old_sent_alerts.php](./scripts/cleanup_old_sent_alerts.md) - Cleanup old notification history
- [migrate.php](./scripts/migrate.md) - Database migrations and schema management
- [scheduler.php](./scripts/scheduler.md) - Continuous scheduler daemon
- [oneshot_poll.php](./scripts/oneshot_poll.md) - Single poll cycle execution
- [test_alert_workflow.php](./scripts/test_alert_workflow.md) - Interactive workflow testing script

## Source Code Documentation

Detailed component documentation:

### Core
- [Config.php](./src/CONFIG.md) - Configuration container
- [bootstrap.php](./src/BOOTSTRAP.md) - Application initialization

### Database Layer (DB/)
- [Connection.php](./src/DB_CONNECTION.md) - PDO singleton for SQLite

### HTTP Layer (Http/)
- [RateLimiter.php](./src/HTTP_RATELIMITER.md) - API rate limiting
- [WeatherClient.php](./src/HTTP_WEATHERCLIENT.md) - Weather.gov API client

### Logging (Logging/)
- [LoggerFactory.php](./src/LOGGING_FACTORY.md) - Monolog configuration

### Data Access (Repository/)
- [AlertsRepository.php](./src/REPOSITORY_ALERTS.md) - Database operations

### Business Logic (Service/)
- [AlertFetcher.php](./src/SERVICE_ALERTFETCHER.md) - Fetch alerts from API
- [AlertProcessor.php](./src/SERVICE_ALERTPROCESSOR.md) - Process and notify
- [PushoverNotifier.php](./src/SERVICE_PUSHOVER.md) - Pushover notifications
- [NtfyNotifier.php](./src/SERVICE_NTFYNOTIFIER.md) - ntfy notifications
- [MessageBuilderTrait.php](./src/SERVICE_MESSAGEBUILDER.md) - Message formatting

### Scheduler (Scheduler/)
- [ConsoleApp.php](./src/SCHEDULER_CONSOLEAPP.md) - Console commands

### Web Interface (Public/)
- [index.php](./src/PUBLIC_INDEX.md) - Web entry point (placeholder)

### Dependencies
- [Composer Dependencies](./src/COMPOSER_DEPENDENCIES.md) - Third-party packages

## External Resources

- [Weather.gov API](https://www.weather.gov/documentation/services-web-api) - Official API documentation
- [Pushover API](https://pushover.net/api) - Push notification service
- [ntfy Documentation](https://docs.ntfy.sh) - Open-source push notifications
- [SQLite Documentation](https://www.sqlite.org/docs.html) - SQLite database engine
- [Monolog Documentation](https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md) - PHP logging
- [Guzzle Documentation](https://docs.guzzlephp.org/) - PHP HTTP client

## Documentation Maintenance

### Checking Links
Verify all internal documentation links:
```sh
php scripts/check_docs_links.php
```

### Regenerating Documentation
When code changes significantly:
1. Update relevant documentation files
2. Run link checker
3. Update this INDEX if new files added
4. Commit documentation with code changes

### Documentation Standards
- Use Markdown format
- Include code examples
- Link to related documentation
- Keep examples up-to-date with code
- Document configuration options
- Explain error messages and solutions
