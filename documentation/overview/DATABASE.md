# Database Schema

SQLite database with four primary tables. All share a common alert schema mirroring weather.gov properties, plus arrays and JSON.

Common columns:
- id TEXT PRIMARY KEY (API id)
- type TEXT
- status TEXT
- msg_type TEXT
- category TEXT
- severity TEXT
- certainty TEXT
- urgency TEXT
- event TEXT
- headline TEXT
- description TEXT
- instruction TEXT
- area_desc TEXT
- sent TEXT
- effective TEXT
- onset TEXT
- expires TEXT
- ends TEXT
- same_array TEXT NOT NULL (JSON array of SAME codes)
- ugc_array TEXT NOT NULL (JSON array of UGC codes)
- json TEXT NOT NULL (full normalized feature JSON)

Tables and their extra columns:
- incoming_alerts: received_at TEXT DEFAULT CURRENT_TIMESTAMP
- active_alerts: updated_at TEXT DEFAULT CURRENT_TIMESTAMP
- pending_alerts: created_at TEXT DEFAULT CURRENT_TIMESTAMP
- sent_alerts: notified_at TEXT, result_status TEXT, result_attempts INTEGER DEFAULT 0, result_error TEXT, pushover_request_id TEXT, user_id INTEGER

Lifecycle:
1) migrate.php ensures tables/columns exist.
2) AlertFetcher replaces incoming_alerts snapshot from the latest API response (skips replace when 0 features to preserve prior data).
3) AlertsRepository.queuePendingForNew selects IDs in incoming_alerts but not in active_alerts, inserts those rows into pending_alerts.
4) AlertProcessor.processPending filters pending by configured SAME/UGC codes, sends notifications, writes results to sent_alerts, and removes from pending.
5) Scheduler periodically replaces active_alerts with incoming_alerts to reflect the latest snapshot.
6) VACUUM is run periodically to compact the database.

Maintenance:
- VACUUM can be invoked via the vacuum command or scheduled in run-scheduler.
- WAL mode and foreign keys are enabled in the PDO connection.
