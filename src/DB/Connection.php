<?php

declare(strict_types=1);

namespace App\DB;

use App\Config;
use PDO;

/**
 * DB Connection singleton
 *
 * Provides a configured PDO instance for SQLite used across the app.
 */
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
