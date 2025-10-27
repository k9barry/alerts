# scripts/oneshot_poll.php

Purpose: Perform a single API fetch and store cycle without running the full scheduler.

Behavior:
- Boots Composer autoloader and src/bootstrap.php.
- Instantiates AlertFetcher and calls fetchAndStoreIncoming().
- Prints how many alerts were stored/updated.

Usage:
- php scripts/oneshot_poll.php
