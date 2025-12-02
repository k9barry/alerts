<?php

declare(strict_types=1);

namespace Tests;

use App\Service\PushoverNotifier;
use App\Service\NtfyNotifier;
use App\Logging\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Simple mock response for testing.
 */
class ImageMockResponse
{
    private int $status;
    private string $body;
    
    public function __construct(int $status, string $body = '')
    {
        $this->status = $status;
        $this->body = $body;
    }
    
    public function getStatusCode(): int
    {
        return $this->status;
    }
    
    public function getBody(): self
    {
        return $this;
    }
    
    public function getContents(): string
    {
        return $this->body;
    }
    
    public function __toString(): string
    {
        return $this->body;
    }
}

/**
 * Simple mock HTTP client for testing.
 */
class ImageMockClient
{
    public array $calls = [];
    private int $status;
    private string $body;
    
    public function __construct(int $status = 200, string $body = 'ok')
    {
        $this->status = $status;
        $this->body = $body;
    }
    
    public function post(string $url, array $opts = []): ImageMockResponse
    {
        $this->calls[] = ['url' => $url, 'opts' => $opts];
        return new ImageMockResponse($this->status, $this->body);
    }
}

/**
 * Unit tests for image attachment functionality in notifiers.
 */
class ImageAttachmentTest extends TestCase
{
    /**
     * Test Pushover notification with image attachment.
     */
    public function testPushoverNotificationWithImageAttachment(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 1, 'request' => 'test-request-id'])),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $notifier = new PushoverNotifier($client);
        
        $alertRow = [
            'id' => 'https://api.weather.gov/alerts/test-alert-1',
            'event' => 'Test Storm Warning',
            'headline' => 'Test headline',
            'json' => json_encode([
                'properties' => [
                    'event' => 'Test Storm Warning',
                    'headline' => 'Test headline',
                    'severity' => 'Moderate',
                    'certainty' => 'Likely',
                    'urgency' => 'Expected',
                ]
            ]),
        ];
        
        $userRow = [
            'idx' => 1,
            'PushoverUser' => 'test-user-key',
            'PushoverToken' => 'test-app-token',
        ];
        
        $imageData = [
            'data' => "\x89PNG\r\n\x1a\n" . str_repeat('x', 200),
            'content_type' => 'image/png',
        ];
        
        $result = $notifier->notifyDetailedForUser($alertRow, $userRow, 'https://example.com/map', $imageData);
        
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['error']);
    }

    /**
     * Test Pushover notification without image attachment.
     */
    public function testPushoverNotificationWithoutImageAttachment(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 1, 'request' => 'test-request-id'])),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $notifier = new PushoverNotifier($client);
        
        $alertRow = [
            'id' => 'https://api.weather.gov/alerts/test-alert-1',
            'event' => 'Test Storm Warning',
            'headline' => 'Test headline',
            'json' => json_encode([
                'properties' => [
                    'event' => 'Test Storm Warning',
                    'headline' => 'Test headline',
                ]
            ]),
        ];
        
        $userRow = [
            'idx' => 1,
            'PushoverUser' => 'test-user-key',
            'PushoverToken' => 'test-app-token',
        ];
        
        $result = $notifier->notifyDetailedForUser($alertRow, $userRow, 'https://example.com/map', null);
        
        $this->assertEquals('success', $result['status']);
    }

    /**
     * Test ntfy notification with image attachment.
     */
    public function testNtfyNotificationWithImageAttachment(): void
    {
        $mockClient = new ImageMockClient(200, json_encode(['id' => 'test-message-id']));
        
        $notifier = new NtfyNotifier(
            new NullLogger(),
            true,
            'test-topic',
            'TEST',
            $mockClient
        );
        
        $alertRow = [
            'id' => 'https://api.weather.gov/alerts/test-alert-1',
            'event' => 'Test Storm Warning',
            'headline' => 'Test headline',
            'json' => json_encode([
                'properties' => [
                    'event' => 'Test Storm Warning',
                    'headline' => 'Test headline',
                    'severity' => 'Moderate',
                    'certainty' => 'Likely',
                    'urgency' => 'Expected',
                ]
            ]),
        ];
        
        $userRow = [
            'idx' => 1,
            'NtfyTopic' => 'user-topic',
        ];
        
        $imageData = [
            'data' => "\x89PNG\r\n\x1a\n" . str_repeat('x', 200),
            'content_type' => 'image/png',
        ];
        
        $result = $notifier->notifyDetailedForUser($alertRow, $userRow, 'https://example.com/map', $imageData);
        
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['error']);
        
        // Verify the request included image headers
        $this->assertNotEmpty($mockClient->calls);
        $call = $mockClient->calls[0];
        $this->assertArrayHasKey('headers', $call['opts']);
        $this->assertEquals('image/png', $call['opts']['headers']['Content-Type']);
        $this->assertArrayHasKey('X-Filename', $call['opts']['headers']);
    }

    /**
     * Test ntfy notification without image attachment.
     */
    public function testNtfyNotificationWithoutImageAttachment(): void
    {
        $mockClient = new ImageMockClient(200, json_encode(['id' => 'test-message-id']));
        
        $notifier = new NtfyNotifier(
            new NullLogger(),
            true,
            'test-topic',
            'TEST',
            $mockClient
        );
        
        $alertRow = [
            'id' => 'https://api.weather.gov/alerts/test-alert-1',
            'event' => 'Test Storm Warning',
            'headline' => 'Test headline',
            'json' => json_encode([
                'properties' => [
                    'event' => 'Test Storm Warning',
                    'headline' => 'Test headline',
                ]
            ]),
        ];
        
        $userRow = [
            'idx' => 1,
            'NtfyTopic' => 'user-topic',
        ];
        
        $result = $notifier->notifyDetailedForUser($alertRow, $userRow, 'https://example.com/map', null);
        
        $this->assertEquals('success', $result['status']);
        
        // Verify the request did NOT include image-specific headers
        $this->assertNotEmpty($mockClient->calls);
        $call = $mockClient->calls[0];
        $this->assertEquals('text/plain; charset=utf-8', $call['opts']['headers']['Content-Type']);
    }

    /**
     * Test ntfy notification with JPEG image attachment.
     */
    public function testNtfyNotificationWithJpegImageAttachment(): void
    {
        $mockClient = new ImageMockClient(200, json_encode(['id' => 'test-message-id']));
        
        $notifier = new NtfyNotifier(
            new NullLogger(),
            true,
            'test-topic',
            'TEST',
            $mockClient
        );
        
        $alertRow = [
            'id' => 'https://api.weather.gov/alerts/test-alert-1',
            'event' => 'Test Storm Warning',
            'headline' => 'Test headline',
            'json' => json_encode([
                'properties' => [
                    'event' => 'Test Storm Warning',
                    'headline' => 'Test headline',
                ]
            ]),
        ];
        
        $userRow = [
            'idx' => 1,
            'NtfyTopic' => 'user-topic',
        ];
        
        $imageData = [
            'data' => "\xFF\xD8\xFF\xE0" . str_repeat('x', 200),
            'content_type' => 'image/jpeg',
        ];
        
        $result = $notifier->notifyDetailedForUser($alertRow, $userRow, 'https://example.com/map', $imageData);
        
        $this->assertEquals('success', $result['status']);
        
        // Verify the request included JPEG headers
        $this->assertNotEmpty($mockClient->calls);
        $call = $mockClient->calls[0];
        $this->assertEquals('image/jpeg', $call['opts']['headers']['Content-Type']);
        $this->assertStringContainsString('jpg', $call['opts']['headers']['X-Filename']);
    }
}

