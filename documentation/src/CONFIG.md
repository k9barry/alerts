# src/Config.php

Purpose: Centralized configuration holder initialized from environment variables.

Key responsibilities:
- Provide static properties for application settings (name, version, contact email).
- Control polling cadence, API rate limits, Pushover pacing, database vacuum schedule.
- Define paths (SQLite DB path), logging channel and level.
- Hold external endpoints and credentials (weather API, Pushover).
- Parse WEATHER_ALERT_CODES into an array of codes for filtering alerts.

Usage:
- Call Config::initFromEnv() during bootstrap to populate static properties.
- Read values directly via static properties, e.g., Config::$pollMinutes.

Notes:
- Env loading is done via Dotenv in bootstrap first; Config::env() reads from $_ENV then getenv().
