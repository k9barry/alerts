# Alerts

<!-- Badges -->
[![CI](https://github.com/k9barry/alerts/actions/workflows/ci.yml/badge.svg)](https://github.com/k9barry/alerts/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-8.3-blue.svg)](https://www.php.net/releases/8.3/)
[![License](https://img.shields.io/github/license/k9barry/alerts)](https://github.com/k9barry/alerts/blob/main/LICENSE)
[![Last Commit](https://img.shields.io/github/last-commit/k9barry/alerts/main)](https://github.com/k9barry/alerts/commits/main)
[![Open Issues](https://img.shields.io/github/issues/k9barry/alerts)](https://github.com/k9barry/alerts/issues)
[![Pull Requests](https://img.shields.io/github/issues-pr/k9barry/alerts)](https://github.com/k9barry/alerts/pulls)
[![Latest Release](https://img.shields.io/github/v/release/k9barry/alerts?include_prereleases)](https://github.com/k9barry/alerts/releases)

A Dockerized PHP 8.3 application that monitors weather.gov alerts, stores them in SQLite, intelligently detects new alerts, and sends notifications through multiple channels (Pushover and ntfy) with intelligent rate limiting and filtering.

## Features

### Core Functionality
- **Automated Polling**: Fetches active weather alerts from weather.gov/alerts/active every configurable interval (default: 3 minutes)
- **Smart API Rate Limiting**: Rolling 60-second window rate limiter respects weather.gov API limits (default: 4 requests/minute)
- **HTTP Caching**: ETag and Last-Modified headers reduce unnecessary data transfers
- **Multi-Table Database Design**: SQLite database with four specialized tables:
  - `incoming_alerts`: Snapshot of latest API fetch
  - `active_alerts`: Currently tracked alerts
  - `pending_alerts`: New alerts queued for notification
  - `sent_alerts`: Historical record of dispatched notifications
- **Intelligent Diff Detection**: Compares incoming alerts against active alerts to identify only new alerts
- **Geographic Filtering**: Configurable SAME/UGC code filtering to receive only relevant alerts for your area
- **Dual Notification Channels**:
  - **Pushover**: Rich notifications with retry logic (up to 3 attempts), 2-second pacing, and clickable alert URLs
  - **ntfy**: Open-source push notifications with custom priority, tags, and actions
- **Structured Logging**: JSON-formatted logs via Monolog with introspection processor for debugging
- **Automatic Database Maintenance**: Periodic VACUUM operation (default: every 24 hours)

### Notification Features
- Customizable message format with severity, certainty, urgency, and time information
- Local timezone conversion for alert timestamps
- Clickable URLs linking directly to NWS alert details
- Configurable notification pacing to avoid overwhelming users
- Comprehensive error handling with retry logic
- Simultaneous multi-channel delivery

### Docker Stack
- **alerts**: Main application container running the scheduler
- **sqlitebrowser**: Web-based SQLite database browser (port 3000)
- **dozzle**: Real-time Docker log viewer (port 9999)

## Quick Start

### 1. Prerequisites
- Docker and Docker Compose installed
- Internet access for weather.gov API

### 2. Configuration
Copy the example environment file and configure it:
```sh
cp .env.example .env
```

Edit `.env` and set at minimum:
- `PUSHOVER_USER` and `PUSHOVER_TOKEN` (if using Pushover)
- `NTFY_TOPIC` (if using ntfy)
- `WEATHER_ALERT_CODES` (optional: comma-separated SAME/UGC codes to filter alerts)
- `TIMEZONE` (your IANA timezone, e.g., "America/New_York")

### 3. Start the Application
```sh
docker compose up --build -d
```

### 4. Access Services
- **Logs (Dozzle)**: http://localhost:9999
- **SQLite Browser**: http://localhost:3000
- **Application**: http://localhost:8080 (currently returns 404 - GUI not implemented)

### 5. Verify Operation
Check the logs in Dozzle to see the scheduler fetching and processing alerts.

## Local Development

### Run Without Docker
Install dependencies and run the scheduler locally:
```sh
composer install
php scripts/scheduler.php
```

### One-Time Poll
Test the system with a single poll cycle:
```sh
php scripts/oneshot_poll.php
```

### Database Migrations
Run database migrations manually:
```sh
php scripts/migrate.php
```

## Configuration Options

See `.env.example` for all available configuration options including:
- Polling intervals
- Rate limits
- Notification settings
- Database paths
- Logging configuration
- Geographic filters

## Architecture

The application follows a clean layered architecture:
- **Config**: Centralized configuration from environment variables
- **DB**: PDO-based SQLite connection with WAL mode
- **Http**: Weather API client with rate limiting
- **Logging**: Structured JSON logging with Monolog
- **Repository**: Data access layer for alert tables
- **Service**: Business logic for fetching, processing, and notifying
- **Scheduler**: Console commands and continuous scheduler loop

## Development

See [README.DEV.md](README.DEV.md) for:
- IDE configuration
- Running tests
- Development workflow
- Troubleshooting

See [INSTALL.md](INSTALL.md) for:
- Detailed installation instructions
- Docker configuration
- SSL certificate setup
- Troubleshooting

See [documentation/INDEX.md](documentation/INDEX.md) for:
- Complete architecture documentation
- Detailed component documentation
- Database schema
- API references

## Testing

Run the test suite:
```sh
./vendor/bin/phpunit --no-coverage
```

Or use the lightweight test runner:
```sh
php scripts/run_unit_smoke.php
```

## License

MIT License - see [LICENSE](LICENSE) file for details.
