# scripts/scheduler.php

Purpose: Entrypoint to run Symfony Console commands in the scheduler application.

Behavior:
- Boots Composer autoloader and src/bootstrap.php.
- Builds ConsoleApp and runs the run-scheduler command.

Usage:
- php scripts/scheduler.php

Related commands (defined in ConsoleApp):
- poll, vacuum, run-scheduler
