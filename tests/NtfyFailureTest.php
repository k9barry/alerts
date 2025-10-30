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
        // should not throw
        $notifier->send('title', 'message');
        $this->assertTrue(true);
    }
}

