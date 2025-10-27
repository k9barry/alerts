# scripts/migrate.php

Purpose: Initialize and migrate the SQLite database schema for alert tables.

Behavior:
- Ensures the directory for DB_PATH exists and is writable.
- Connects to SQLite via App\DB\Connection.
- Defines a unified alert column set matching weather.gov properties plus arrays/json.
- Ensures four tables exist with the same core schema and table-specific extra columns:
  - incoming_alerts (received_at)
  - active_alerts (updated_at)
  - pending_alerts (created_at)
  - sent_alerts (notified_at, result_* fields, pushover_request_id, user_id)
- Adds missing columns idempotently using PRAGMA table_info.
- Outputs a summary of present tables and any missing/extra tables.

Usage:
- php scripts/migrate.php

Environment/Config:
- DB_PATH from .env or defaults to src/../data/alerts.sqlite
