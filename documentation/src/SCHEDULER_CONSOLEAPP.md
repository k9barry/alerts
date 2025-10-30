# Scheduler/ConsoleApp.php

Symfony Console application builder with scheduler commands.

## Location
`src/Scheduler/ConsoleApp.php`

## Purpose
Defines console commands for the application: single poll, database vacuum, and continuous scheduler.

## Static Method

### build()
Returns configured Symfony Console Application with three commands:

#### poll
Single poll cycle:
- Fetch and store incoming
- Diff and queue
- Process pending
- Returns SUCCESS

Usage: Invoked by oneshot_poll.php

#### vacuum
Database maintenance:
- Execute VACUUM SQL command
- Log completion
- Returns SUCCESS

Usage: Manual maintenance or automated cleanup

#### run-scheduler
Continuous scheduler loop:
- Infinite loop with poll cycle
- Periodic VACUUM (every VACUUM_HOURS)
- Sleep for POLL_MINUTES between cycles
- Error handling (log and continue)
- Never returns (runs until killed)

Usage: Primary application mode (scheduler.php)

## Commands Implementation
Commands defined as anonymous classes extending Symfony\Component\Console\Command.

## Error Handling
run-scheduler wraps each cycle in try-catch to prevent crashes.

See [RUNTIME.md](../overview/RUNTIME.md) for execution flow details.
