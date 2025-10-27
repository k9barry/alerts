# Alerts

A Dockerized PHP 8.3 application that polls weather.gov alerts, stores them in SQLite, compares and promotes alerts, notifies users via Pushover with rate limiting, and exposes a simple GUI to manage user data. Includes structured logging to Dozzle.

## Features
- Scheduler polls weather.gov/alerts/active every X minutes (default 3)
- API client rate limited to X calls/minute (default 4)
- SQLite persistence: incoming_alerts, active_alerts, pending_alerts, sent_alerts, user_data (with SAME/UGC arrays)
- JSON response parsed and stored, SAME/UGC codes persisted as arrays
- Compare and promote to pending_alerts when new
- Pushover notifications with 1 per 2s pacing and retry 3 times
- Replace active_alerts with incoming on each cycle
- Periodic VACUUM (default every 24 hours)
- CRUD GUI for user_data
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
2. Build and start
```sh
docker compose up --build -d
```
3. Open the GUI
- App: http://localhost:8080
- Dozzle: http://localhost:9999
- SQLiteBrowser: container exposes files under /data (mounted from ./data)

## Development
- Configure git for LF line endings (prevents CRLF issues)
```sh
git config core.autocrlf input
```
- Install dependencies
```sh
docker run --rm -v "$PWD":/app -w /app composer:2 install
```
- Run PHP built-in server
```sh
php -S 127.0.0.1:8080 -t public
```

## Branch and PR
After changes:
```sh
git checkout -b feature/alerts-system
git add .
git commit -m "Implement alerts system"
git push -u origin feature/alerts-system
```
Create a PR titled: "Implement weather alerts system with scheduler, SQLite, logging, and GUI".

See INSTALL.md for full instructions.
