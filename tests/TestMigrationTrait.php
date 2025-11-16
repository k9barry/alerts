<?php
/**
 * Test Migration Trait
 * 
 * Shared database migration code for tests to ensure consistent schema.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

/**
 * Trait providing database migration helpers for tests
 */
trait TestMigrationTrait
{
    /**
     * Run database migrations to create tables with current schema
     *
     * @return void
     */
    protected function runMigrations(): void
    {
        $pdo = \App\DB\Connection::get();
        
        // Unified alert schema columns matching weather.gov properties
        $alertColumns = [
            "id TEXT NOT NULL",
            "type TEXT",
            "status TEXT",
            "msg_type TEXT",
            "category TEXT",
            "severity TEXT",
            "certainty TEXT",
            "urgency TEXT",
            "event TEXT",
            "headline TEXT",
            "description TEXT",
            "instruction TEXT",
            "area_desc TEXT",
            "sent TEXT",
            "effective TEXT",
            "onset TEXT",
            "expires TEXT",
            "ends TEXT",
            "same_array TEXT NOT NULL",
            "ugc_array TEXT NOT NULL",
            "json TEXT NOT NULL"
        ];
        
        $alertCols = implode(",\n  ", $alertColumns);
        
        // Create tables with current schema
        $tables = [
            'incoming_alerts' => 'received_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)',
            'active_alerts' => 'updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)',
            'pending_alerts' => 'created_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)',
        ];
        
        foreach ($tables as $table => $extra) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
                {$alertCols},
                {$extra}
            )");
        }
        
        // Create sent_alerts table with composite primary key (id, user_id, channel)
        $pdo->exec("CREATE TABLE IF NOT EXISTS sent_alerts (
            {$alertCols},
            notified_at TEXT,
            result_status TEXT,
            result_attempts INTEGER NOT NULL DEFAULT 0,
            result_error TEXT,
            pushover_request_id TEXT,
            user_id INTEGER NOT NULL,
            channel TEXT,
            PRIMARY KEY (id, user_id, channel)
        )");
        
        // Create users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            idx INTEGER PRIMARY KEY AUTOINCREMENT,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Email TEXT NOT NULL UNIQUE,
            Timezone TEXT DEFAULT 'America/New_York',
            PushoverUser TEXT,
            PushoverToken TEXT,
            NtfyUser TEXT,
            NtfyPassword TEXT,
            NtfyToken TEXT,
            NtfyTopic TEXT,
            ZoneAlert TEXT DEFAULT '[]',
            CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Create zones table
        $pdo->exec("CREATE TABLE IF NOT EXISTS zones (
            idx INTEGER PRIMARY KEY AUTOINCREMENT,
            STATE TEXT NOT NULL,
            ZONE TEXT NOT NULL,
            CWA TEXT,
            NAME TEXT NOT NULL,
            STATE_ZONE TEXT,
            COUNTY TEXT,
            FIPS TEXT,
            TIME_ZONE TEXT,
            FE_AREA TEXT,
            LAT REAL,
            LON REAL,
            UNIQUE(STATE, ZONE)
        )");
    }
}
