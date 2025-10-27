# Runtime & Scheduler

Commands are provided via Symfony Console in src/Scheduler/ConsoleApp.php and invoked by scripts/scheduler.php.

Commands:
- poll: One cycle: fetch -> diff & queue -> process pending.
- vacuum: Executes SQLite VACUUM and logs completion.
- run-scheduler: Infinite loop performing poll cycles and periodic VACUUM.

Scheduler loop (run-scheduler):
- Interval: POLL_MINUTES (min 60 seconds enforced).
- Each tick:
  1) fetchAndStoreIncoming()
  2) diffAndQueue()
  3) processPending()
  4) replaceActiveWithIncoming()
- Periodic VACUUM: every VACUUM_HOURS.
- Errors are caught and logged per tick; loop continues.

Logs:
- JSON logs via Monolog to stdout or logs/app.log depending on LOG_CHANNEL.
