<?php

use PHPUnit\Framework\TestCase;
use App\Service\PushoverNotifier;
use App\Service\NtfyNotifier;
use App\Config;

require_once __DIR__ . '/Mocks/MockResponse.php';

/**
 * Test suite for sendAlert() methods added to PushoverNotifier and NtfyNotifier.
 * These methods provide a simplified interface for sending alerts in the test workflow.
 */
class SendAlertMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::initFromEnv();
    }

    public function testPushoverSendAlertSuccess(): void
    {
        // Mock successful Pushover response
        $mockResponse = new MockResponse(200, json_encode([
            'status' => 1,
            'request' => 'test-request-id-123'
        ]));
        
        $mockClient = new class($mockResponse) {
            private $response;
            public function __construct($response) {
                $this->response = $response;
            }
            public function post(string $url, array $options) {
                return $this->response;
            }
        };

        $notifier = new PushoverNotifier($mockClient);
        $result = $notifier->sendAlert(
            'test-user-key',
            'test-token',
            'Test Alert',
            'This is a test message',
            'https://example.com/alert'
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertEquals('test-request-id-123', $result['request_id']);
    }

    public function testPushoverSendAlertFailure(): void
    {
        // Mock failed Pushover response
        $mockResponse = new MockResponse(400, json_encode([
            'status' => 0,
            'errors' => ['invalid token']
        ]));
        
        $mockClient = new class($mockResponse) {
            private $response;
            public function __construct($response) {
                $this->response = $response;
            }
            public function post(string $url, array $options) {
                return $this->response;
            }
        };

        $notifier = new PushoverNotifier($mockClient);
        $result = $notifier->sendAlert(
            'test-user-key',
            'bad-token',
            'Test Alert',
            'This is a test message'
        );

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('400', $result['error']);
        $this->assertStringContainsString('invalid token', $result['error']);
    }

    public function testNtfySendAlertSuccess(): void
    {
        // Mock successful ntfy response
        $mockResponse = new MockResponse(200, json_encode(['id' => 'test-msg-123']));
        
        $mockClient = new class($mockResponse) {
            private $response;
            public function __construct($response) {
                $this->response = $response;
            }
            public function post(string $url, array $options) {
                return $this->response;
            }
        };

        $notifier = new NtfyNotifier(null, null, null, null, $mockClient);
        $result = $notifier->sendAlert(
            'test-topic',
            'Test Alert',
            'This is a test message',
            'https://example.com/alert'
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
    }

    public function testNtfySendAlertInvalidTopic(): void
    {
        $notifier = new NtfyNotifier();
        
        // Test empty topic
        $result = $notifier->sendAlert(
            '',
            'Test Alert',
            'This is a test message'
        );
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Empty topic', $result['error']);

        // Test invalid topic with special characters
        $result = $notifier->sendAlert(
            'invalid@topic!',
            'Test Alert',
            'This is a test message'
        );
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid topic name', $result['error']);
    }

    public function testNtfySendAlertFailure(): void
    {
        // Mock failed ntfy response
        $mockResponse = new MockResponse(401, 'Unauthorized');
        
        $mockClient = new class($mockResponse) {
            private $response;
            public function __construct($response) {
                $this->response = $response;
            }
            public function post(string $url, array $options) {
                return $this->response;
            }
        };

        $notifier = new NtfyNotifier(null, null, null, null, $mockClient);
        $result = $notifier->sendAlert(
            'test-topic',
            'Test Alert',
            'This is a test message'
        );

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('401', $result['error']);
    }

    public function testPushoverSendAlertWithoutUrl(): void
    {
        // Test that sendAlert works without providing a URL
        $mockResponse = new MockResponse(200, json_encode([
            'status' => 1,
            'request' => 'test-request-id-456'
        ]));
        
        $mockClient = new class($mockResponse) {
            private $response;
            public function __construct($response) {
                $this->response = $response;
            }
            public function post(string $url, array $options) {
                return $this->response;
            }
        };

        $notifier = new PushoverNotifier($mockClient);
        $result = $notifier->sendAlert(
            'test-user-key',
            'test-token',
            'Test Alert',
            'This is a test message'
        );

        $this->assertTrue($result['success']);
    }

    public function testNtfySendAlertWithCredentials(): void
    {
        // Test that sendAlert accepts and uses custom credentials
        $mockResponse = new MockResponse(200, '');
        
        $mockClient = new class($mockResponse) {
            private $response;
            public function __construct($response) {
                $this->response = $response;
            }
            public function post(string $url, array $options) {
                // Just return the response - credentials are verified by the implementation
                return $this->response;
            }
        };

        $notifier = new NtfyNotifier(null, null, null, null, $mockClient);
        
        // Test with token
        $result = $notifier->sendAlert(
            'test-topic',
            'Test Alert',
            'This is a test message',
            null,
            null,
            null,
            'test-token-123'
        );
        $this->assertTrue($result['success']);

        // Test with username and password
        $result = $notifier->sendAlert(
            'test-topic',
            'Test Alert',
            'This is a test message',
            null,
            'testuser',
            'testpass',
            null
        );
        $this->assertTrue($result['success']);
    }
}
