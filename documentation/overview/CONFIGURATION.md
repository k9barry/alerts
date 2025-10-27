# Configuration

Configuration is loaded in src/bootstrap.php via dotenv (if .env exists) and App\Config::initFromEnv().

Environment variables:
- APP_NAME (default: alerts)
- APP_VERSION (default: 0.1.0)
- APP_CONTACT_EMAIL (default: you@example.com) used in User-Agent for weather.gov
- POLL_MINUTES (default: 3) scheduler poll interval
- API_RATE_PER_MINUTE (default: 4) max weather API requests per minute
- PUSHOVER_RATE_SECONDS (default: 2) min seconds between Pushover sends
- VACUUM_HOURS (default: 24) interval between DB VACUUM runs in scheduler
- DB_PATH (default: src/../data/alerts.sqlite)
- LOG_CHANNEL (stdout or file, default: stdout)
- LOG_LEVEL (default: info)
- PUSHOVER_API_URL (default: https://api.pushover.net/1/messages.json)
- WEATHER_API_URL (default: https://api.weather.gov/alerts/active)
- PUSHOVER_USER, PUSHOVER_TOKEN (Pushover credentials)
- WEATHER_ALERT_CODES (space/comma/semicolon separated SAME or UGC codes to match; if empty, all alerts match)

Files/directories auto-created:
- data/ and logs/ directories are created by bootstrap.
