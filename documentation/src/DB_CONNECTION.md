# DB/Connection.php

Singleton PDO connection to SQLite database.

## Location
`src/DB/Connection.php`

## Purpose
Provides configured PDO instance for database operations.

## Usage
```php
use App\DB\Connection;

$pdo = Connection::get();
$stmt = $pdo->prepare('SELECT * FROM alerts WHERE id = ?');
$stmt->execute([$id]);
```

## Features
- **Singleton Pattern**: Only one connection instance
- **WAL Mode**: Better concurrency (readers don't block writers)
- **Foreign Keys**: Enabled for referential integrity
- **Exception Mode**: PDO throws exceptions on errors
- **Lazy Initialization**: Connection created on first `get()` call

## Configuration
Uses `Config::$dbPath` for database file location:
- Docker default: `/data/alerts.sqlite`
- Host default: `data/alerts.sqlite`

## SQLite Settings
```sql
PRAGMA journal_mode=WAL;  -- Write-Ahead Logging
PRAGMA foreign_keys=ON;   -- Referential integrity
```

## Implementation
```php
final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'sqlite:' . Config::$dbPath;
            self::$pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL;');
            self::$pdo->exec('PRAGMA foreign_keys=ON;');
        }
        return self::$pdo;
    }
}
```

See [DATABASE.md](../overview/DATABASE.md) for schema details.
