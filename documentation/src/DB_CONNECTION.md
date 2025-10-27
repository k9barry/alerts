# src/DB/Connection.php

Purpose: Provide a singleton PDO connection to the SQLite database.

Behavior:
- Lazily constructs PDO with DSN sqlite:Config::$dbPath.
- Sets ATTR_ERRMODE to EXCEPTION and default fetch mode to FETCH_ASSOC.
- Enables WAL journaling and foreign keys.

Usage:
- $pdo = App\DB\Connection::get();

Notes:
- Shared connection reused on subsequent calls.
