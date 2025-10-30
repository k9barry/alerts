<?php

use PHPUnit\Framework\TestCase;
use App\Service\PushoverNotifier;

class MockResponse {
    private int $status;
    private string $body;
    public function __construct(int $status, string $body = '') { $this->status = $status; $this->body = $body; }
    public function getStatusCode() { return $this->status; }
    public function getBody() { return $this->body; }
    public function __toString() { return $this->body; }
}

class QueueMockClient {
    public array $responses = [];
    public array $calls = [];
    public function __construct(array $responses) { $this->responses = $responses; }
    public function post($url, $opts = []) {
        $this->calls[] = [$url, $opts];
        $resp = array_shift($this->responses);
        if ($resp instanceof \Exception) throw $resp;
        return $resp;
    }
}

class PushoverRetryAndFailureTest extends TestCase
{
    public function testRetryThenSuccess(): void
    {
        // First response 500, second 200
        $mockResponses = [new MockResponse(500, 'err'), new MockResponse(200, json_encode(['request' => 'r1']))];
        $mockClient = new QueueMockClient($mockResponses);
        $notifier = new PushoverNotifier($mockClient);

        $row = ['json' => json_encode(['properties' => ['event' => 'e', 'headline' => 'h']]), 'id' => '1'];
        $res = $notifier->notifyDetailed($row);
        $this->assertEquals('success', $res['status']);
        $this->assertGreaterThanOrEqual(2, $res['attempts']);
        $this->assertNotEmpty($mockClient->calls);
    }

    public function testAllAttemptsFail(): void
    {
        // All three attempts return 500
        $mockResponses = [new MockResponse(500, 'err1'), new MockResponse(500, 'err2'), new MockResponse(500, 'err3')];
        $mockClient = new QueueMockClient($mockResponses);
        $notifier = new PushoverNotifier($mockClient);

        $row = ['json' => json_encode(['properties' => ['event' => 'e', 'headline' => 'h']]), 'id' => '1'];
        $res = $notifier->notifyDetailed($row);
        $this->assertEquals('failure', $res['status']);
        $this->assertEquals(3, $res['attempts']);
    }
}

