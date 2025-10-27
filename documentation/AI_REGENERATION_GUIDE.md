# AI Regeneration Guide

This guide enables recreating the alerts codebase using an AI code generator. It defines the project goals, structure, components, and step-by-step prompts to generate files and ensure functionality.

## Project Summary
- Purpose: Poll active weather alerts from api.weather.gov, store in SQLite, and notify via Pushover.
- Language/Stack: PHP 8+, Composer, Symfony Console, Guzzle, Monolog, vlucas/phpdotenv, SQLite (PDO).
- Runtime: CLI-focused with optional minimal web entry returning 404.

## Functional Requirements
1) Fetch active alerts from api.weather.gov/alerts/active with conditional requests (ETag/Last-Modified), rate-limited.
2) Store the latest feed as a normalized snapshot in SQLite (incoming_alerts).
3) Determine new alerts by diffing incoming_alerts vs active_alerts; queue new ones into pending_alerts.
4) Filter pending alerts by configured SAME/UGC codes (WEATHER_ALERT_CODES). If not configured, treat all as matches.
5) Send notifications via Pushover with pacing and retry logic; persist results in sent_alerts.
6) Replace active_alerts with incoming_alerts after each processing cycle.
7) Provide a scheduler (infinite loop) that performs polling cycles every POLL_MINUTES and VACUUMs every VACUUM_HOURS.
8) Provide scripts: migrate.php, scheduler.php, oneshot_poll.php.
9) Provide JSON logging via Monolog to stdout or file.

## Non-Functional Requirements
- Idempotent migrations (create tables/columns if missing).
- Configurable via .env and environment variables.
- Rate limits: API_RATE_PER_MINUTE for weather API; PUSHOVER_RATE_SECONDS between messages.
- Resilient network behavior: handle non-200 responses; retry Pushover sends up to 3 times.

## Suggested Directory Structure
- src/
  - bootstrap.php
  - Config.php
  - DB/Connection.php
  - Http/{RateLimiter.php, WeatherClient.php}
  - Logging/LoggerFactory.php
  - Repository/AlertsRepository.php
  - Service/{AlertFetcher.php, AlertProcessor.php, PushoverNotifier.php}
  - Scheduler/ConsoleApp.php
- scripts/{migrate.php, scheduler.php, oneshot_poll.php}
- public/index.php
- data/ (runtime)
- logs/ (runtime)
- certs/ (optional)
- composer.json, composer.lock
- Dockerfile, docker-compose.yml (optional)
- README.md, INSTALL.md, LICENSE

## Environment & Config
- .env keys:
  - APP_NAME, APP_VERSION, APP_CONTACT_EMAIL
  - POLL_MINUTES, API_RATE_PER_MINUTE, PUSHOVER_RATE_SECONDS, VACUUM_HOURS
  - DB_PATH
  - LOG_CHANNEL, LOG_LEVEL
  - PUSHOVER_API_URL, WEATHER_API_URL
  - PUSHOVER_USER, PUSHOVER_TOKEN
  - WEATHER_ALERT_CODES

## Database Schema
Common columns across incoming_alerts, active_alerts, pending_alerts, sent_alerts:
- id TEXT PRIMARY KEY
- type, status, msg_type, category, severity, certainty, urgency TEXT
- event, headline, description, instruction, area_desc TEXT
- sent, effective, onset, expires, ends TEXT
- same_array TEXT NOT NULL (JSON array)
- ugc_array TEXT NOT NULL (JSON array)
- json TEXT NOT NULL (normalized feature JSON)
Extras:
- incoming_alerts: received_at TEXT DEFAULT CURRENT_TIMESTAMP
- active_alerts: updated_at TEXT DEFAULT CURRENT_TIMESTAMP
- pending_alerts: created_at TEXT DEFAULT CURRENT_TIMESTAMP
- sent_alerts: notified_at TEXT, result_status TEXT, result_attempts INTEGER DEFAULT 0, result_error TEXT, pushover_request_id TEXT, user_id INTEGER

## Composer Dependencies
- guzzlehttp/guzzle
- monolog/monolog
- symfony/console
- vlucas/phpdotenv

## High-Level Generation Plan
1) Generate composer.json with PHP >=8.1 and dependencies above.
2) Implement src/bootstrap.php to load autoload, .env, create data/logs, init Config and LoggerFactory.
3) Implement src/Config.php static config holder with initFromEnv().
4) Implement src/DB/Connection.php as singleton PDO (SQLite), enabling WAL and foreign_keys.
5) Implement src/Logging/LoggerFactory.php with JSON logs to stdout or logs/app.log based on LOG_CHANNEL.
6) Implement src/Http/RateLimiter.php and src/Http/WeatherClient.php with conditional headers and ETag/Last-Modified persistence per instance.
7) Implement src/Repository/AlertsRepository.php with methods: replaceIncoming, getIncomingIds, getActiveIds, queuePendingForNew, replaceActiveWithIncoming, getPending, deletePendingById, insertSentResult.
8) Implement src/Service/AlertFetcher.php to fetch and store normalized incoming alerts.
9) Implement src/Service/AlertProcessor.php to diff, filter by WEATHER_ALERT_CODES, send via PushoverNotifier, and persist results.
10) Implement src/Service/PushoverNotifier.php with pacing, retries, message formatting, URL linking, and structured result.
11) Implement src/Scheduler/ConsoleApp.php with commands: poll, vacuum, run-scheduler. Replace active with incoming each tick after processing.
12) Implement CLI scripts: scripts/migrate.php (idempotent schema ensure), scripts/scheduler.php (run-scheduler), scripts/oneshot_poll.php (single fetch/store).
13) Implement public/index.php returning 404.

## Prompts for AI Generation
Use the following prompts sequentially with your AI code generator. After each file is generated, save it to the specified path.

1) composer.json
- Prompt: "Create composer.json for a PHP 8.1 project named 'alerts' with dependencies: guzzlehttp/guzzle, monolog/monolog, symfony/console, vlucas/phpdotenv. Add PSR-4 autoload 'App\\': 'src/'."

2) src/bootstrap.php
- Prompt: "Write src/bootstrap.php that loads vendor autoload, loads .env if present using Dotenv::createUnsafeImmutable(...)->safeLoad(), creates data/ and logs/ directories, then calls App\\Config::initFromEnv() and App\\Logging\\LoggerFactory::init()."

3) src/Config.php
- Prompt: "Implement App\\Config with static properties for all config values, an initFromEnv() method to populate them using getenv/$_ENV, and parsing WEATHER_ALERT_CODES into an array."

4) src/DB/Connection.php
- Prompt: "Implement App\\DB\\Connection with a singleton PDO to sqlite:Config::$dbPath, setting ATTR_ERRMODE EXCEPTION, default fetch assoc, and executing PRAGMA journal_mode=WAL and PRAGMA foreign_keys=ON."

5) src/Logging/LoggerFactory.php
- Prompt: "Implement App\\Logging\\LoggerFactory that initializes a Monolog Logger with IntrospectionProcessor and a StreamHandler writing to stdout if LOG_CHANNEL=stdout else logs/app.log. Use JsonFormatter and Config::$logLevel. Provide init() and get()."

6) src/Http/RateLimiter.php
- Prompt: "Implement a simple per-minute RateLimiter with await() using a sliding window of timestamps and usleep when over limit."

7) src/Http/WeatherClient.php
- Prompt: "Implement WeatherClient using Guzzle that sets User-Agent 'APP_NAME/APP_VERSION (APP_CONTACT_EMAIL)', Accept headers for geo+json, applies RateLimiter per API_RATE_PER_MINUTE, sends If-None-Match and If-Modified-Since when available, handles 304/200/other status, stores ETag/Last-Modified, and returns decoded array. Log failures via LoggerFactory."

8) src/Repository/AlertsRepository.php
- Prompt: "Implement AlertsRepository for SQLite with methods replaceIncoming, getIncomingIds, getActiveIds, queuePendingForNew, replaceActiveWithIncoming, getPending, deletePendingById, insertSentResult. Use transactions for replace operations and parameterized queries."

9) src/Service/AlertFetcher.php
- Prompt: "Implement AlertFetcher that calls WeatherClient->fetchActive(), extracts id, SAME, UGC arrays, normalizes to keep full feature JSON and arrays separately, and calls AlertsRepository->replaceIncoming(). Skip replacement when features=0."

10) src/Service/AlertProcessor.php
- Prompt: "Implement AlertProcessor with diffAndQueue() and processPending(): filter pending rows by Config::$weatherAlerts against SAME/UGC codes (case-insensitive), send via PushoverNotifier->notifyDetailed(), insert results into sent_alerts, and delete from pending in finally block."

11) src/Service/PushoverNotifier.php
- Prompt: "Implement PushoverNotifier using Guzzle; enforce delay between sends based on Config::$pushoverRateSeconds; build title/message from alert properties; include URL link if id looks like http(s); retry up to 3 times; return array with status, attempts, error, request_id; log result."

12) src/Scheduler/ConsoleApp.php
- Prompt: "Implement a Symfony Console Application with commands: poll (single cycle), vacuum (VACUUM), run-scheduler (infinite loop every POLL_MINUTES, periodic VACUUM every VACUUM_HOURS, replace active with incoming each tick)."

13) scripts/migrate.php
- Prompt: "Implement migrate.php that ensures four tables with the unified schema exist (incoming_alerts, active_alerts, pending_alerts, sent_alerts), adds missing columns using PRAGMA table_info, and prints a summary."

14) scripts/scheduler.php
- Prompt: "Implement scheduler.php that boots vendor/autoload and src/bootstrap.php, builds ConsoleApp and runs the run-scheduler command."

15) scripts/oneshot_poll.php
- Prompt: "Implement oneshot_poll.php that boots vendor/autoload and src/bootstrap.php, runs AlertFetcher->fetchAndStoreIncoming(), and prints the count."

16) public/index.php
- Prompt: "Implement a minimal web entry that requires bootstrap, returns 404, and exits."

## Validation Steps
- composer install
- php scripts/migrate.php
- php scripts/oneshot_poll.php
- php scripts/scheduler.php (stop after verifying loop starts)
- Optional: Use WEATH ER_ALERT_CODES and Pushover credentials to verify notifications are sent.

## Additional Guidance
- Logging: ensure all exception paths log structured messages.
- Robustness: treat absent properties in API JSON gracefully.
- Testing: simulate Pushover failure by changing PUSHOVER_API_URL to an invalid endpoint and verify retries and result persistence.

By following this guide and prompts, an AI code generator can recreate a functionally equivalent codebase with the same architecture and behavior.