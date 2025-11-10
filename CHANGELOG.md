# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- CHANGELOG.md file to track project changes and version history
- Added `declare(strict_types=1);` to all source files for better type safety
- Code improvements and cleanup pass

### Fixed
- Removed duplicate comment in Config.php

### Changed
- Improved code quality and documentation
- Enhanced type safety across the codebase

## [0.1.0] - 2024

### Added
- Initial release
- Dockerized PHP 8.3 application for weather.gov alerts monitoring
- SQLite database with WAL mode for alert storage
- Intelligent diff detection for new alerts
- Multi-table database design (incoming, active, pending, sent, zones, users)
- Dual notification channels (Pushover and ntfy)
- Smart API rate limiting (4 requests/minute default)
- HTTP caching with ETag and Last-Modified headers
- Per-user geographic filtering via zone subscriptions
- Web-based CRUD interface for user management
- Automatic database maintenance with VACUUM
- Structured JSON logging via Monolog
- Docker Compose stack with sqlitebrowser and dozzle
- Comprehensive test suite with PHPUnit
- Documentation system with multiple guides
- Automatic NWS zones data download
- User data backup system
- Configurable polling intervals and rate limits
- Local timezone conversion for alert timestamps
- Retry logic for notifications
- Message formatting with severity, certainty, and urgency

### Features
- Automated polling from weather.gov/alerts/active
- Multiple notification channel support
- Clickable URLs in notifications
- Notification pacing to avoid overwhelming users
- Foreign key constraints in SQLite
- PSR-4 autoloading
- Clean layered architecture (Config, DB, Http, Logging, Repository, Service, Scheduler)
- Interactive test scripts for workflow validation
- Development and production environment support

[Unreleased]: https://github.com/k9barry/alerts/compare/HEAD...HEAD
[0.1.0]: https://github.com/k9barry/alerts/releases/tag/v0.1.0
