# Architecture

This project ingests active weather alerts from api.weather.gov, stores them in a local SQLite database, and sends notifications via Pushover.

Components:
- Entry points (CLI): scripts/migrate.php, scripts/scheduler.php, scripts/oneshot_poll.php
- Bootstrap: src/bootstrap.php initializes environment, configuration, logging.
- Core modules:
  - Config (src/Config.php): loads settings from environment.
  - DB Connection (src/DB/Connection.php): provides a singleton PDO for SQLite with WAL and foreign keys enabled.
  - Logging (src/Logging/LoggerFactory.php): Monolog logger configured to stdout or file with JSON formatting.
  - HTTP:
    - RateLimiter (src/Http/RateLimiter.php): client-side rate limiting per minute.
    - WeatherClient (src/Http/WeatherClient.php): calls weather API with conditional headers (ETag/Last-Modified).
  - Repository (src/Repository/AlertsRepository.php): database access for alerts.
  - Services:
    - AlertFetcher: fetches alerts and stores a normalized snapshot into incoming_alerts.
    - AlertProcessor: diffs new alerts, filters by SAME/UGC codes, queues and sends via Pushover.
    - PushoverNotifier: formats and sends notifications to Pushover with retry and pacing.
  - Scheduler (src/Scheduler/ConsoleApp.php): Symfony Console app with commands: poll, vacuum, run-scheduler.
  - Public (public/index.php): returns 404 only.

Data Flow:
1) WeatherClient.fetchActive -> AlertFetcher.fetchAndStoreIncoming -> AlertsRepository.replaceIncoming (incoming_alerts)
2) AlertsRepository.queuePendingForNew compares incoming_alerts vs active_alerts -> pending_alerts
3) AlertProcessor.processPending -> PushoverNotifier.notifyDetailed -> AlertsRepository.insertSentResult (sent_alerts)
4) Scheduler replaces active_alerts with incoming_alerts after processing each tick.

Observability and resilience:
- JSON logs to stdout or logs/app.log via Monolog.
- WeatherClient logs non-200/HTTP failures and uses conditional requests to reduce load.
- PushoverNotifier retries up to 3 times per alert, logs outcome.
