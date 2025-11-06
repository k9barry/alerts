<?php

use PHPUnit\Framework\TestCase;

class ZoneAlertRoundtripTest extends TestCase
{
    public function testZoneAlertRoundtrip(): void
    {
        // Ensure we have a PDO connection
        $pdo = \App\DB\Connection::get();

        // Drop and recreate tables to ensure clean state
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("DROP TABLE IF EXISTS zones");
        
        // Create minimal schema for test (in-memory DB from tests/bootstrap.php)
        $pdo->exec("CREATE TABLE zones (idx INTEGER PRIMARY KEY AUTOINCREMENT, NAME TEXT, STATE TEXT, ZONE TEXT);");
        $pdo->exec("CREATE TABLE users (idx INTEGER PRIMARY KEY AUTOINCREMENT, FirstName TEXT, LastName TEXT, Email TEXT UNIQUE, Timezone TEXT, PushoverUser TEXT, PushoverToken TEXT, NtfyUser TEXT, NtfyPassword TEXT, NtfyToken TEXT, ZoneAlert TEXT);");

        // Insert zones
        $ins = $pdo->prepare("INSERT INTO zones (NAME, STATE, ZONE) VALUES (?, ?, ?)");
        $ins->execute(['Zone One','AA','UGC1']);
        $id1 = (int)$pdo->lastInsertId();
        $ins->execute(['Zone Two','BB','UGC2']);
        $id2 = (int)$pdo->lastInsertId();

        // Create user with ZoneAlert as array of IDs
        $zoneIds = [$id1, $id2];
        $stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, ZoneAlert) VALUES (?, ?, ?, ?)");
        $stmt->execute(['Test','User','test@example.com', json_encode($zoneIds)]);
        $uid = (int)$pdo->lastInsertId();

        // Read back and verify
        $row = $pdo->query("SELECT * FROM users WHERE idx = " . $uid)->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'User row not found');
        $stored = json_decode($row['ZoneAlert'], true);
        $this->assertEquals($zoneIds, $stored, 'ZoneAlert did not round-trip as expected');

        // Verify mapping to zone ZONE codes
        $placeholders = implode(',', array_fill(0, count($stored), '?'));
        $q = $pdo->prepare("SELECT ZONE FROM zones WHERE idx IN ($placeholders) ORDER BY idx");
        $q->execute($stored);
        $codes = $q->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals(['UGC1','UGC2'], $codes, 'Zone codes mismatch');
    }
}
