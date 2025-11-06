<?php

use PHPUnit\Framework\TestCase;

class UsersDownloadUploadTest extends TestCase
{
    private PDO $pdo;
    
    protected function setUp(): void
    {
        $this->pdo = \App\DB\Connection::get();
        
        // Create fresh users table for each test
        $this->pdo->exec("DROP TABLE IF EXISTS users");
        $this->pdo->exec("CREATE TABLE users (
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
    }
    
    /**
     * Helper method to map user array to parameters for INSERT statement
     */
    private function mapUserToParams(array $user): array
    {
        return [
            $user['idx'],
            $user['FirstName'],
            $user['LastName'],
            $user['Email'],
            $user['Timezone'],
            $user['PushoverUser'] ?? '',
            $user['PushoverToken'] ?? '',
            $user['NtfyUser'] ?? '',
            $user['NtfyPassword'] ?? '',
            $user['NtfyToken'] ?? '',
            $user['NtfyTopic'] ?? '',
            $user['ZoneAlert'] ?? '[]',
            $user['CreatedAt'] ?? null,
            $user['UpdatedAt'] ?? null
        ];
    }
    
    /**
     * Helper method to insert a user into the backup database
     */
    private function insertUserToBackup(PDO $backupDb, array $user): void
    {
        $stmt = $backupDb->prepare("INSERT INTO users (idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute($this->mapUserToParams($user));
    }
    
    /**
     * Helper method to restore a user from backup to main database
     */
    private function restoreUserFromBackup(array $user): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute($this->mapUserToParams($user));
    }
    
    public function testBackupAndRestoreUsers(): void
    {
        // Insert test users
        $stmt = $this->pdo->prepare("INSERT INTO users (FirstName, LastName, Email, Timezone, ZoneAlert) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['John', 'Doe', 'john@example.com', 'America/New_York', json_encode(['zone1', 'zone2'])]);
        $stmt->execute(['Jane', 'Smith', 'jane@example.com', 'America/Los_Angeles', json_encode(['zone3'])]);
        
        // Verify users were inserted
        $count = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertEquals(2, $count, 'Should have 2 users initially');
        
        // Simulate backup by reading all users
        $users = $this->pdo->query("SELECT * FROM users ORDER BY idx")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $users);
        
        // Verify first user data
        $this->assertEquals('John', $users[0]['FirstName']);
        $this->assertEquals('Doe', $users[0]['LastName']);
        $this->assertEquals('john@example.com', $users[0]['Email']);
        
        // Create a backup database in memory
        $backupDb = new PDO('sqlite::memory:');
        $backupDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create same schema in backup
        $backupDb->exec("CREATE TABLE users (
            idx INTEGER PRIMARY KEY,
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
            CreatedAt TEXT,
            UpdatedAt TEXT
        )");
        
        // Copy users to backup
        foreach ($users as $user) {
            $this->insertUserToBackup($backupDb, $user);
        }
        
        // Clear original users
        $this->pdo->exec("DELETE FROM users");
        $count = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertEquals(0, $count, 'Users should be deleted');
        
        // Restore from backup
        $backupUsers = $backupDb->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($backupUsers as $user) {
            $this->restoreUserFromBackup($user);
        }
        
        // Verify restoration
        $restoredCount = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertEquals(2, $restoredCount, 'Should have 2 users after restore');
        
        $restoredUsers = $this->pdo->query("SELECT * FROM users ORDER BY idx")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEquals('John', $restoredUsers[0]['FirstName']);
        $this->assertEquals('jane@example.com', $restoredUsers[1]['Email']);
        $this->assertEquals('["zone3"]', $restoredUsers[1]['ZoneAlert']);
    }
    
    public function testRestorePreservesAllUserData(): void
    {
        // Insert user with all fields populated
        $stmt = $this->pdo->prepare("INSERT INTO users (FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic, ZoneAlert) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Test',
            'User',
            'test@example.com',
            'America/Chicago',
            'pushover_user_123',
            'pushover_token_456',
            'ntfy_user',
            'ntfy_pass',
            'ntfy_token_789',
            'my-topic',
            json_encode(['INZ001', 'INZ002'])
        ]);
        
        $original = $this->pdo->query("SELECT * FROM users")->fetch(PDO::FETCH_ASSOC);
        
        // Simulate backup/restore
        $this->pdo->exec("DELETE FROM users");
        
        $this->restoreUserFromBackup($original);
        
        $restored = $this->pdo->query("SELECT * FROM users")->fetch(PDO::FETCH_ASSOC);
        
        // Verify all fields were preserved
        $this->assertEquals($original['FirstName'], $restored['FirstName']);
        $this->assertEquals($original['PushoverUser'], $restored['PushoverUser']);
        $this->assertEquals($original['PushoverToken'], $restored['PushoverToken']);
        $this->assertEquals($original['NtfyUser'], $restored['NtfyUser']);
        $this->assertEquals($original['NtfyPassword'], $restored['NtfyPassword']);
        $this->assertEquals($original['NtfyToken'], $restored['NtfyToken']);
        $this->assertEquals($original['NtfyTopic'], $restored['NtfyTopic']);
        $this->assertEquals($original['ZoneAlert'], $restored['ZoneAlert']);
    }
}
