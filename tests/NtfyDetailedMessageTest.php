<?php

use PHPUnit\Framework\TestCase;
use App\Service\NtfyNotifier;
use App\Service\PushoverNotifier;
use App\Config;
use Psr\Log\NullLogger;

/**
 * Test that ntfy messages contain the same detailed information as pushover messages.
 */
class NtfyDetailedMessageTest extends TestCase
{
    private array $sampleAlertRow;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize Config for timezone support
        Config::initFromEnv();
        
        // Sample alert row with realistic weather alert data
        $this->sampleAlertRow = [
            'id' => 'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.12345',
            'event' => 'Severe Thunderstorm Warning',
            'headline' => 'Severe Thunderstorm Warning issued for Marion County',
            'json' => json_encode([
                'properties' => [
                    'event' => 'Severe Thunderstorm Warning',
                    'headline' => 'Severe Thunderstorm Warning issued for Marion County',
                    'messageType' => 'Alert',
                    'status' => 'Actual',
                    'category' => 'Met',
                    'severity' => 'Severe',
                    'certainty' => 'Observed',
                    'urgency' => 'Immediate',
                    'areaDesc' => 'Marion County; Hamilton County',
                    'effective' => '2025-11-10T12:00:00-05:00',
                    'expires' => '2025-11-10T14:00:00-05:00',
                    'description' => 'At 1200 PM EST, severe thunderstorms were located along a line extending from Indianapolis to Greenwood, moving northeast at 40 mph.',
                    'instruction' => 'For your protection move to an interior room on the lowest floor of a building.'
                ]
            ])
        ];
    }

    public function testNtfyNotifierHasMessageBuilderTrait(): void
    {
        $reflection = new ReflectionClass(NtfyNotifier::class);
        $traits = $reflection->getTraitNames();
        $this->assertContains('App\Service\MessageBuilderTrait', $traits, 'NtfyNotifier should use MessageBuilderTrait');
    }

    public function testNtfyDetailedMessageContainsSeverityCertaintyUrgency(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $this->assertNotEmpty($mockClient->requests);
        
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message contains S/C/U line (Severity/Certainty/Urgency)
        $this->assertStringContainsString('S/C/U:', $body, 'Message should contain S/C/U line');
        $this->assertStringContainsString('Severe', $body, 'Message should contain severity');
        $this->assertStringContainsString('Observed', $body, 'Message should contain certainty');
        $this->assertStringContainsString('Immediate', $body, 'Message should contain urgency');
    }

    public function testNtfyDetailedMessageContainsStatusMessageCategory(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message contains Status/Msg/Cat line
        $this->assertStringContainsString('Status/Msg/Cat:', $body, 'Message should contain Status/Msg/Cat line');
        $this->assertStringContainsString('Actual', $body, 'Message should contain status');
        $this->assertStringContainsString('Alert', $body, 'Message should contain message type');
        $this->assertStringContainsString('Met', $body, 'Message should contain category');
    }

    public function testNtfyDetailedMessageContainsArea(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message contains area description
        $this->assertStringContainsString('Area:', $body, 'Message should contain Area line');
        $this->assertStringContainsString('Marion County', $body, 'Message should contain area description');
    }

    public function testNtfyDetailedMessageContainsTimeWithTimezone(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message contains time line with formatted timestamps
        // The MessageBuilderTrait formats times according to the configured timezone
        $this->assertStringContainsString('Time:', $body, 'Message should contain Time line');
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $body, 'Message should contain formatted timestamp');
    }

    public function testNtfyDetailedMessageContainsDescription(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message contains the full description
        $this->assertStringContainsString('severe thunderstorms were located', $body, 'Message should contain description');
    }

    public function testNtfyDetailedMessageContainsInstruction(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message contains the instruction
        $this->assertStringContainsString('Instruction:', $body, 'Message should contain Instruction label');
        $this->assertStringContainsString('move to an interior room', $body, 'Message should contain instruction text');
    }

    public function testNtfyDetailedMessageRespectsLengthLimit(): void
    {
        // Create alert with very long description to test truncation
        $longDescription = str_repeat('This is a very long description that exceeds the normal message length. ', 100);
        $alertWithLongDesc = $this->sampleAlertRow;
        $json = json_decode($alertWithLongDesc['json'], true);
        $json['properties']['description'] = $longDescription;
        $alertWithLongDesc['json'] = json_encode($json);
        
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($alertWithLongDesc, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $body = $request['opts']['body'] ?? '';
        
        // Verify message is truncated to ntfy's 4096 character limit
        $this->assertLessThanOrEqual(4096, strlen($body), 'Message should not exceed ntfy limit of 4096 characters');
    }

    public function testNtfyDetailedMessageIncludesClickableLink(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $headers = $request['opts']['headers'] ?? [];
        
        // Verify X-Click header is set with the alert URL
        $this->assertArrayHasKey('X-Click', $headers, 'Headers should include X-Click for clickable link');
        $this->assertEquals('https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.12345', $headers['X-Click']);
    }

    public function testNtfyDetailedTitleMatchesPushoverFormat(): void
    {
        $mockClient = new class {
            public array $requests = [];
            public function post($url, $opts = []) {
                $this->requests[] = ['url' => $url, 'opts' => $opts];
                return $this->mockResponse(200, 'ok');
            }
            private function mockResponse($status, $body) {
                return new class($status, $body) {
                    private $status, $body;
                    public function __construct($s, $b) { $this->status = $s; $this->body = $b; }
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this; }
                    public function __toString() { return $this->body; }
                };
            }
        };

        $notifier = new NtfyNotifier(new NullLogger(), true, 'test_topic', null, $mockClient);
        $userRow = ['idx' => 1, 'NtfyTopic' => 'test_topic'];
        
        $result = $notifier->notifyDetailedForUser($this->sampleAlertRow, $userRow);
        
        $this->assertEquals('success', $result['status']);
        $request = $mockClient->requests[0];
        $headers = $request['opts']['headers'] ?? [];
        
        // Verify title format matches Pushover: [EVENT] Headline
        $this->assertArrayHasKey('X-Title', $headers);
        $title = $headers['X-Title'];
        $this->assertStringContainsString('[SEVERE THUNDERSTORM WARNING]', $title, 'Title should include event in uppercase within brackets');
        $this->assertStringContainsString('Severe Thunderstorm Warning issued for Marion County', $title, 'Title should include headline');
    }
}
