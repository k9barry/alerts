<?php

use PHPUnit\Framework\TestCase;
use App\Service\NtfyNotifier;
use Psr\Log\NullLogger;

class ThrowingClient {
    public function post($url, $opts = []) { throw new \Exception('network error'); }
}

class NtfyFailureTest extends TestCase
{
    public function testSendHandlesExceptionGracefully(): void
    {
        $client = new ThrowingClient();
        $notifier = new NtfyNotifier(new NullLogger(), true, 'topic', 'prefix', $client);
        // should not throw, but should return failure status
        $result = $notifier->send('title', 'message');
        $this->assertEquals('failure', $result['status']);
        $this->assertEquals(3, $result['attempts']); // Should retry 3 times
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Exception', $result['error']);
    }
}

