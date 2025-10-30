<?php

use PHPUnit\Framework\TestCase;
use App\Service\NtfyNotifier;
use Psr\Log\NullLogger;

class MockResponse {
    private int $status;
    private string $body;
    public function __construct(int $status, string $body = '') { $this->status = $status; $this->body = $body; }
    public function getStatusCode() { return $this->status; }
    public function getBody() { return $this; }
    public function getContents() { return $this->body; }
    public function __toString() { return $this->body; }
}

class MockClient {
    public array $calls = [];
    public function post($url, $opts = []) {
        $this->calls[] = [$url, $opts];
        return new MockResponse(200, 'ok');
    }
}

class NtfyNotifierTest extends TestCase
{
    public function testSendUsesClient(): void
    {
        $mock = new MockClient();
        $notifier = new NtfyNotifier(new NullLogger(), true, 'topic', 'prefix', $mock);
        // Should not throw
        $notifier->send('title', 'message');
        $this->assertNotEmpty($mock->calls);
    }
}

