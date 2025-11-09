<?php

use PHPUnit\Framework\TestCase;
use App\Service\NtfyNotifier;
use App\Logging\LoggerFactory;
use Psr\Log\NullLogger;

/**
 * Test to verify comprehensive audit logging for Ntfy notifications
 * This addresses issue #28: Ntfy messages are not logged like pushover messages
 */
class NtfyAuditLoggingTest extends TestCase
{
    /**
     * Test that successful send operations log audit information
     */
    public function testSuccessfulSendLogsAuditInfo(): void
    {
        $mockClient = new class {
            public array $calls = [];
            public function post($url, $opts = []) {
                $this->calls[] = [$url, $opts];
                return new class {
                    public function getStatusCode() { return 200; }
                    public function getBody() { return ''; }
                };
            }
        };
        
        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $result = $notifier->send('Test Title', 'Test Message');
        
        // Verify audit result structure matches Pushover pattern
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('error', $result);
        
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['error']);
    }
    
    /**
     * Test that failed send operations log audit information with error details
     */
    public function testFailedSendLogsAuditInfo(): void
    {
        $mockClient = new class {
            public array $calls = [];
            public function post($url, $opts = []) {
                $this->calls[] = [$url, $opts];
                return new class {
                    public function getStatusCode() { return 500; }
                    public function getBody() { return 'Internal Server Error'; }
                };
            }
        };
        
        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $result = $notifier->send('Test Title', 'Test Message');
        
        // Verify audit result structure includes failure details
        $this->assertEquals('failure', $result['status']);
        $this->assertEquals(3, $result['attempts']); // Should retry 3 times
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('HTTP 500', $result['error']);
    }
    
    /**
     * Test that sendForUser logs audit information with user context
     */
    public function testSendForUserLogsAuditInfo(): void
    {
        $mockClient = new class {
            public function post($url, $opts = []) {
                return new class {
                    public function getStatusCode() { return 200; }
                    public function getBody() { return ''; }
                };
            }
        };
        
        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 123, 'NtfyToken' => 'test_token'];
        $result = $notifier->sendForUser('Test Title', 'Test Message', [], $userRow);
        
        // Verify audit result structure
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('error', $result);
        
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['error']);
    }
    
    /**
     * Test that sendForUserWithTopic logs audit information with topic and user context
     */
    public function testSendForUserWithTopicLogsAuditInfo(): void
    {
        $mockClient = new class {
            public function post($url, $opts = []) {
                return new class {
                    public function getStatusCode() { return 200; }
                    public function getBody() { return ''; }
                };
            }
        };
        
        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 456, 'NtfyTopic' => 'user_topic'];
        $result = $notifier->sendForUserWithTopic('Test Title', 'Test Message', [], $userRow, 'Zone1');
        
        // Verify audit result structure
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('error', $result);
        
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['error']);
    }
    
    /**
     * Test that sendAlert with retry logic logs audit information
     */
    public function testSendAlertLogsAuditInfo(): void
    {
        $mockClient = new class {
            public int $attemptCount = 0;
            
            public function post($url, $opts = []) {
                $this->attemptCount++;
                // Fail first 2 attempts, succeed on 3rd
                if ($this->attemptCount < 3) {
                    return new class {
                        public function getStatusCode() { return 500; }
                        public function getBody() { return 'Server Error'; }
                    };
                }
                return new class {
                    public function getStatusCode() { return 200; }
                    public function getBody() { return ''; }
                };
            }
        };
        
        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $result = $notifier->sendAlert('test_topic', 'Test Title', 'Test Message');
        
        // Verify audit result includes retry attempts
        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertEquals(3, $mockClient->attemptCount); // Should have attempted 3 times
    }
    
    /**
     * Test that invalid topic errors are properly logged in audit trail
     */
    public function testInvalidTopicLogsAuditError(): void
    {
        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null);
        $result = $notifier->sendAlert('invalid topic!', 'Test', 'Test');
        
        // Verify error is captured in audit result
        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Invalid topic name', $result['error']);
    }
}
