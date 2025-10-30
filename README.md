# Alerts

<!-- Badges -->
[![CI](https://github.com/k9barry/alerts/actions/workflows/ci.yml/badge.svg)](https://github.com/k9barry/alerts/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-8.3-blue.svg)](https://www.php.net/releases/8.3/)
[![License](https://img.shields.io/github/license/k9barry/alerts)](https://github.com/k9barry/alerts/blob/main/LICENSE)
[![Last Commit](https://img.shields.io/github/last-commit/k9barry/alerts/main)](https://github.com/k9barry/alerts/commits/main)
[![Open Issues](https://img.shields.io/github/issues/k9barry/alerts)](https://github.com/k9barry/alerts/issues)
[![Pull Requests](https://img.shields.io/github/issues-pr/k9barry/alerts)](https://github.com/k9barry/alerts/pulls)
[![Latest Release](https://img.shields.io/github/v/release/k9barry/alerts?include_prereleases)](https://github.com/k9barry/alerts/releases)

A Dockerized PHP 8.3 application that polls weather.gov alerts, stores them in SQLite, compares and promotes alerts, notifies users via Pushover and or NTFY with rate limiting

## Features
- Scheduler polls weather.gov/alerts/active every X minutes (default 3)
- API client rate limited to X calls/minute (default 4)
- SQLite persistence: incoming_alerts, active_alerts, pending_alerts, sent_alerts, user_data (with SAME/UGC arrays)
- JSON response parsed and stored, SAME/UGC codes persisted as arrays
- Compare and promote to pending_alerts when new
- Pushover and or Ntfy notifications with 1 per 2s pacing and retry 3 times
- Replace active_alerts with incoming on each cycle
- Periodic VACUUM (default every 24 hours)
- Monolog logging with IntrospectionProcessor; view via Dozzle
- Docker Compose services: alerts app, SQLiteBrowser, Dozzle

## Local scheduler

- Run locally: php scripts/scheduler.php
- In Docker: docker compose up --build -d (scheduler runs as the main process)

## Quick start
1. Copy env and adjust values
```sh
cp .env.example .env
# If you need a local CA bundle to fix cURL error 60, download cacert.pem and set in .env:
# SSL_CERT_FILE=certs/cacert.pem
# CURL_CA_BUNDLE=certs/cacert.pem
```
2. Install required libraries with Composer
```sh
composer install
```
3. Build and start
```sh
docker compose up --build -d
```
4. Open the GUI
- App: http://localhost:8080
- Dozzle: http://localhost:9999
- SQLiteBrowser: container exposes files under /data (mounted from ./data)

See INSTALL.md for full instructions.
