# src/Scheduler/ConsoleApp.php

Purpose: Provide a Symfony Console-based CLI application to orchestrate polling and maintenance.

Commands:
- poll: Single cycle (fetch -> queue -> process).
- vacuum: Run SQLite VACUUM and log completion.
- run-scheduler: Infinite loop; performs a cycle every POLL_MINUTES (min 60s), periodically VACUUMs every VACUUM_HOURS, and replaces active alerts with the incoming snapshot each tick.

Error handling:
- Each scheduler tick is wrapped with try/catch; errors are logged, and the loop continues.

Usage:
- $app = ConsoleApp::build(); $app->run(...)
- Entrypoint is scripts/scheduler.php.
