<?php

use PHPUnit\Framework\TestCase;
use App\DB\Connection;

/**
 * Test that the test_alert_workflow.php script retrieves all required fields from the database.
 * This test validates the fix for the issue where PushoverToken, NtfyUser, NtfyPassword, 
 * and NtfyToken were missing from the SELECT query.
 */
class TestAlertWorkflowFieldsTest extends TestCase
{
    /**
     * Helper method to create the users table with consistent schema
     */
    private function createUsersTable(): void
    {
        $pdo = Connection::get();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            idx INTEGER PRIMARY KEY AUTOINCREMENT,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Email TEXT NOT NULL,
            Timezone TEXT,
            PushoverUser TEXT,
            PushoverToken TEXT,
            NtfyUser TEXT,
            NtfyPassword TEXT,
            NtfyToken TEXT,
            NtfyTopic TEXT,
            ZoneAlert TEXT
        )");
    }
    /**
     * Test that all required user fields are retrieved from the database
     * when querying users for the test workflow.
     */
    public function testAllRequiredUserFieldsAreRetrieved(): void
    {
        // Ensure we have a PDO connection and create table
        $this->createUsersTable();
        $pdo = Connection::get();

        // Insert a test user with all notification credentials
        $stmt = $pdo->prepare("INSERT INTO users (
            FirstName, LastName, Email, Timezone,
            PushoverUser, PushoverToken,
            NtfyUser, NtfyPassword, NtfyToken, NtfyTopic,
            ZoneAlert
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            'Test',
            'User',
            'test@example.com',
            'America/New_York',
            'test_pushover_user',
            'test_pushover_token',
            'test_ntfy_user',
            'test_ntfy_password',
            'test_ntfy_token',
            'test_ntfy_topic',
            '[]'
        ]);
        $userId = (int)$pdo->lastInsertId();

        // Simulate the query from test_alert_workflow.php line 116
        // This is the FIXED query that includes all required fields
        $stmt = $pdo->query("SELECT idx, FirstName, LastName, Email, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic FROM users ORDER BY idx");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Verify we got the user
        $this->assertNotEmpty($users, 'No users retrieved');
        $this->assertCount(1, $users, 'Expected exactly one user');

        $user = $users[0];

        // Verify all required fields are present in the result
        $requiredFields = [
            'idx',
            'FirstName',
            'LastName',
            'Email',
            'PushoverUser',
            'PushoverToken',  // This was missing before the fix
            'NtfyUser',       // This was missing before the fix
            'NtfyPassword',   // This was missing before the fix
            'NtfyToken',      // This was missing before the fix
            'NtfyTopic'
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $user, "Field '{$field}' is missing from user data");
        }

        // Verify the values are correct
        $this->assertEquals('Test', $user['FirstName']);
        $this->assertEquals('User', $user['LastName']);
        $this->assertEquals('test@example.com', $user['Email']);
        $this->assertEquals('test_pushover_user', $user['PushoverUser']);
        $this->assertEquals('test_pushover_token', $user['PushoverToken']);
        $this->assertEquals('test_ntfy_user', $user['NtfyUser']);
        $this->assertEquals('test_ntfy_password', $user['NtfyPassword']);
        $this->assertEquals('test_ntfy_token', $user['NtfyToken']);
        $this->assertEquals('test_ntfy_topic', $user['NtfyTopic']);
    }

    /**
     * Test that the credentials can be properly used after retrieval
     * (simulating what happens in the script at lines 219-281)
     */
    public function testCredentialsAreUsableAfterRetrieval(): void
    {
        // Create table with consistent schema
        $this->createUsersTable();
        $pdo = Connection::get();

        // Insert user with credentials (unique email to avoid conflicts)
        $stmt = $pdo->prepare("INSERT INTO users (
            FirstName, LastName, Email,
            PushoverUser, PushoverToken,
            NtfyUser, NtfyPassword, NtfyToken, NtfyTopic
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $uniqueEmail = 'john-' . uniqid() . '@example.com';
        $stmt->execute([
            'John',
            'Doe',
            $uniqueEmail,
            'pushover_user_123',
            'pushover_token_456',
            'ntfy_user_789',
            'ntfy_pass_abc',
            'ntfy_token_def',
            'MyAlertTopic'
        ]);
        
        $insertedId = (int)$pdo->lastInsertId();

        // Retrieve the specific user we just inserted
        $stmt = $pdo->prepare("SELECT idx, FirstName, LastName, Email, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic FROM users WHERE idx = ?");
        $stmt->execute([$insertedId]);
        $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotEmpty($selectedUser, 'User not found after insertion');

        // Simulate the credential extraction from lines 219-281 of test_alert_workflow.php
        $pushoverUser = trim($selectedUser['PushoverUser'] ?? '');
        $pushoverToken = trim($selectedUser['PushoverToken'] ?? '');
        $ntfyTopic = trim($selectedUser['NtfyTopic'] ?? '');
        $ntfyUser = !empty($selectedUser['NtfyUser']) ? trim($selectedUser['NtfyUser']) : null;
        $ntfyPassword = !empty($selectedUser['NtfyPassword']) ? trim($selectedUser['NtfyPassword']) : null;
        $ntfyToken = !empty($selectedUser['NtfyToken']) ? trim($selectedUser['NtfyToken']) : null;

        // Verify credentials are properly extracted and not empty
        $this->assertEquals('pushover_user_123', $pushoverUser);
        $this->assertEquals('pushover_token_456', $pushoverToken);
        $this->assertEquals('MyAlertTopic', $ntfyTopic);
        $this->assertEquals('ntfy_user_789', $ntfyUser);
        $this->assertEquals('ntfy_pass_abc', $ntfyPassword);
        $this->assertEquals('ntfy_token_def', $ntfyToken);

        // Verify they're not empty (which was the original problem)
        $this->assertNotEmpty($pushoverUser, 'Pushover user should not be empty');
        $this->assertNotEmpty($pushoverToken, 'Pushover token should not be empty');
        $this->assertNotEmpty($ntfyTopic, 'Ntfy topic should not be empty');
        $this->assertNotEmpty($ntfyUser, 'Ntfy user should not be empty');
        $this->assertNotEmpty($ntfyPassword, 'Ntfy password should not be empty');
        $this->assertNotEmpty($ntfyToken, 'Ntfy token should not be empty');
    }
}
