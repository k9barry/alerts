<?php
/**
 * Test for sent_alerts cleanup functionality
 * 
 * This test verifies that old sent_alerts records are properly deleted
 * based on the retention period.
 * 
 * @package Alerts\Tests
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use App\DB\Connection;
use PHPUnit\Framework\TestCase;

/**
 * CleanupOldSentAlertsTest
 * 
 * Tests the cleanup functionality for removing old sent_alerts records
 */
final class CleanupOldSentAlertsTest extends TestCase
{
    use TestMigrationTrait;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Connection::get();
        $this->runMigrations();
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-cleanup-%'");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM sent_alerts WHERE id LIKE 'test-cleanup-%'");
    }

    /**
     * Test that old records are deleted and recent records are retained
     */
    public function testCleanupOldRecords(): void
    {
        // Insert test records with different ages
        $oldDate = date('Y-m-d H:i:s', strtotime('-45 days'));
        $recentDate = date('Y-m-d H:i:s', strtotime('-15 days'));
        $veryRecentDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        // Insert old record (should be deleted)
        $stmt = $this->pdo->prepare('INSERT INTO sent_alerts (
            id, type, status, msg_type, category, severity, certainty, urgency,
            event, headline, description, instruction, area_desc, sent, effective,
            onset, expires, ends, same_array, ugc_array, json,
            notified_at, result_status, result_attempts, result_error, user_id, channel
        ) VALUES (
            :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
            :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
            :onset, :expires, :ends, :same_array, :ugc_array, :json,
            :notified_at, :result_status, :result_attempts, :result_error, :user_id, :channel
        )');

        // Old record (45 days ago)
        $stmt->execute([
            ':id' => 'test-cleanup-old-1',
            ':type' => 'Feature',
            ':status' => 'Actual',
            ':msg_type' => 'Alert',
            ':category' => 'Met',
            ':severity' => 'Moderate',
            ':certainty' => 'Likely',
            ':urgency' => 'Expected',
            ':event' => 'Test Event',
            ':headline' => 'Test Old Alert',
            ':description' => 'This is a test old alert',
            ':instruction' => null,
            ':area_desc' => 'Test Area',
            ':sent' => $oldDate,
            ':effective' => $oldDate,
            ':onset' => $oldDate,
            ':expires' => $oldDate,
            ':ends' => $oldDate,
            ':same_array' => '[]',
            ':ugc_array' => '[]',
            ':json' => '{}',
            ':notified_at' => $oldDate,
            ':result_status' => 'success',
            ':result_attempts' => 1,
            ':result_error' => null,
            ':user_id' => 1,
            ':channel' => 'pushover'
        ]);

        // Recent record (15 days ago - should be kept)
        $stmt->execute([
            ':id' => 'test-cleanup-recent-1',
            ':type' => 'Feature',
            ':status' => 'Actual',
            ':msg_type' => 'Alert',
            ':category' => 'Met',
            ':severity' => 'Moderate',
            ':certainty' => 'Likely',
            ':urgency' => 'Expected',
            ':event' => 'Test Event',
            ':headline' => 'Test Recent Alert',
            ':description' => 'This is a test recent alert',
            ':instruction' => null,
            ':area_desc' => 'Test Area',
            ':sent' => $recentDate,
            ':effective' => $recentDate,
            ':onset' => $recentDate,
            ':expires' => $recentDate,
            ':ends' => $recentDate,
            ':same_array' => '[]',
            ':ugc_array' => '[]',
            ':json' => '{}',
            ':notified_at' => $recentDate,
            ':result_status' => 'success',
            ':result_attempts' => 1,
            ':result_error' => null,
            ':user_id' => 1,
            ':channel' => 'pushover'
        ]);

        // Very recent record (1 day ago - should be kept)
        $stmt->execute([
            ':id' => 'test-cleanup-very-recent-1',
            ':type' => 'Feature',
            ':status' => 'Actual',
            ':msg_type' => 'Alert',
            ':category' => 'Met',
            ':severity' => 'Moderate',
            ':certainty' => 'Likely',
            ':urgency' => 'Expected',
            ':event' => 'Test Event',
            ':headline' => 'Test Very Recent Alert',
            ':description' => 'This is a test very recent alert',
            ':instruction' => null,
            ':area_desc' => 'Test Area',
            ':sent' => $veryRecentDate,
            ':effective' => $veryRecentDate,
            ':onset' => $veryRecentDate,
            ':expires' => $veryRecentDate,
            ':ends' => $veryRecentDate,
            ':same_array' => '[]',
            ':ugc_array' => '[]',
            ':json' => '{}',
            ':notified_at' => $veryRecentDate,
            ':result_status' => 'success',
            ':result_attempts' => 1,
            ':result_error' => null,
            ':user_id' => 1,
            ':channel' => 'pushover'
        ]);

        // Verify all records exist
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM sent_alerts WHERE id LIKE 'test-cleanup-%'");
        $count = $stmt->fetchColumn();
        $this->assertEquals(3, $count, 'Should have 3 test records before cleanup');

        // Perform cleanup with 30-day retention
        $retentionDays = 30;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $stmt = $this->pdo->prepare('DELETE FROM sent_alerts WHERE id LIKE \'test-cleanup-%\' AND notified_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoffDate]);

        // Verify old record was deleted
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sent_alerts WHERE id = ?");
        $stmt->execute(['test-cleanup-old-1']);
        $count = $stmt->fetchColumn();
        $this->assertEquals(0, $count, 'Old record (45 days) should be deleted');

        // Verify recent record was kept
        $stmt->execute(['test-cleanup-recent-1']);
        $count = $stmt->fetchColumn();
        $this->assertEquals(1, $count, 'Recent record (15 days) should be kept');

        // Verify very recent record was kept
        $stmt->execute(['test-cleanup-very-recent-1']);
        $count = $stmt->fetchColumn();
        $this->assertEquals(1, $count, 'Very recent record (1 day) should be kept');

        // Verify total count
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM sent_alerts WHERE id LIKE 'test-cleanup-%'");
        $count = $stmt->fetchColumn();
        $this->assertEquals(2, $count, 'Should have 2 test records after cleanup (recent ones kept)');
    }

    /**
     * Test that cleanup handles records with NULL notified_at
     */
    public function testCleanupHandlesNullNotifiedAt(): void
    {
        // Insert a record with NULL notified_at
        $stmt = $this->pdo->prepare('INSERT INTO sent_alerts (
            id, type, status, msg_type, category, severity, certainty, urgency,
            event, headline, description, instruction, area_desc, sent, effective,
            onset, expires, ends, same_array, ugc_array, json,
            notified_at, result_status, result_attempts, result_error, user_id, channel
        ) VALUES (
            :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
            :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
            :onset, :expires, :ends, :same_array, :ugc_array, :json,
            :notified_at, :result_status, :result_attempts, :result_error, :user_id, :channel
        )');

        $stmt->execute([
            ':id' => 'test-cleanup-null-date',
            ':type' => 'Feature',
            ':status' => 'Actual',
            ':msg_type' => 'Alert',
            ':category' => 'Met',
            ':severity' => 'Moderate',
            ':certainty' => 'Likely',
            ':urgency' => 'Expected',
            ':event' => 'Test Event',
            ':headline' => 'Test Null Date Alert',
            ':description' => 'This alert has null notified_at',
            ':instruction' => null,
            ':area_desc' => 'Test Area',
            ':sent' => null,
            ':effective' => null,
            ':onset' => null,
            ':expires' => null,
            ':ends' => null,
            ':same_array' => '[]',
            ':ugc_array' => '[]',
            ':json' => '{}',
            ':notified_at' => null,
            ':result_status' => 'success',
            ':result_attempts' => 1,
            ':result_error' => null,
            ':user_id' => 1,
            ':channel' => 'pushover'
        ]);

        // Perform cleanup - should not delete NULL dates
        $retentionDays = 30;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $stmt = $this->pdo->prepare('DELETE FROM sent_alerts WHERE id LIKE \'test-cleanup-%\' AND notified_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoffDate]);

        // Verify record with NULL notified_at was NOT deleted
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sent_alerts WHERE id = ?");
        $stmt->execute(['test-cleanup-null-date']);
        $count = $stmt->fetchColumn();
        $this->assertEquals(1, $count, 'Record with NULL notified_at should not be deleted');
    }

    /**
     * Test cleanup with different retention periods
     */
    public function testCleanupWithDifferentRetentionPeriods(): void
    {
        // Insert records at various ages
        $ages = [10, 20, 40, 60, 90];
        foreach ($ages as $daysAgo) {
            $date = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
            $stmt = $this->pdo->prepare('INSERT INTO sent_alerts (
                id, type, status, msg_type, category, severity, certainty, urgency,
                event, headline, description, instruction, area_desc, sent, effective,
                onset, expires, ends, same_array, ugc_array, json,
                notified_at, result_status, result_attempts, result_error, user_id, channel
            ) VALUES (
                :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
                :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
                :onset, :expires, :ends, :same_array, :ugc_array, :json,
                :notified_at, :result_status, :result_attempts, :result_error, :user_id, :channel
            )');
            
            $stmt->execute([
                ':id' => "test-cleanup-retention-{$daysAgo}",
                ':type' => 'Feature',
                ':status' => 'Actual',
                ':msg_type' => 'Alert',
                ':category' => 'Met',
                ':severity' => 'Moderate',
                ':certainty' => 'Likely',
                ':urgency' => 'Expected',
                ':event' => 'Test Event',
                ':headline' => "Test Alert {$daysAgo} days old",
                ':description' => "This alert is {$daysAgo} days old",
                ':instruction' => null,
                ':area_desc' => 'Test Area',
                ':sent' => $date,
                ':effective' => $date,
                ':onset' => $date,
                ':expires' => $date,
                ':ends' => $date,
                ':same_array' => '[]',
                ':ugc_array' => '[]',
                ':json' => '{}',
                ':notified_at' => $date,
                ':result_status' => 'success',
                ':result_attempts' => 1,
                ':result_error' => null,
                ':user_id' => 1,
                ':channel' => 'pushover'
            ]);
        }

        // Test 30-day retention
        $retentionDays = 30;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $stmt = $this->pdo->prepare('DELETE FROM sent_alerts WHERE id LIKE \'test-cleanup-retention-%\' AND notified_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoffDate]);

        // Check what remains (should be 10 and 20 day old records)
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM sent_alerts WHERE id LIKE 'test-cleanup-retention-%'");
        $count = $stmt->fetchColumn();
        $this->assertEquals(2, $count, 'With 30-day retention, should keep 2 records (10 and 20 days old)');

        // Verify specific records
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sent_alerts WHERE id = ?");
        $stmt->execute(['test-cleanup-retention-10']);
        $this->assertEquals(1, $stmt->fetchColumn(), '10-day record should exist');
        
        $stmt->execute(['test-cleanup-retention-20']);
        $this->assertEquals(1, $stmt->fetchColumn(), '20-day record should exist');
        
        $stmt->execute(['test-cleanup-retention-40']);
        $this->assertEquals(0, $stmt->fetchColumn(), '40-day record should be deleted');
    }
}
