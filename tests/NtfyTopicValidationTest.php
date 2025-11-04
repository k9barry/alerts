<?php

use PHPUnit\Framework\TestCase;
use App\Service\NtfyNotifier;
use Psr\Log\NullLogger;

class NtfyTopicValidationTest extends TestCase
{
    public function testValidTopicNames(): void
    {
        $validTopics = [
            'weather_alerts',
            'weather-alerts',
            'WeatherAlerts',
            'alerts123',
            'ALERTS',
            'a',
            'A',
            '1',
            '_',
            '-',
            'test_123-ABC',
            'My-Weather_Alerts123'
        ];

        foreach ($validTopics as $topic) {
            $this->assertTrue(
                NtfyNotifier::isValidTopicName($topic),
                "Topic '{$topic}' should be valid"
            );
        }
    }

    public function testInvalidTopicNames(): void
    {
        $invalidTopics = [
            '',
            '   ',
            'weather alerts',    // space
            'weather.alerts',    // dot
            'weather@alerts',    // @
            'weather#alerts',    // #
            'weather$alerts',    // $
            'weather%alerts',    // %
            'weather&alerts',    // &
            'weather+alerts',    // +
            'weather=alerts',    // =
            'weather!alerts',    // !
            'weather?alerts',    // ?
            'weather/alerts',    // slash
            'weather\\alerts',   // backslash
            'weather(alerts)',   // parentheses
            'weather[alerts]',   // brackets
            'weather{alerts}',   // braces
            'weather:alerts',    // colon
            'weather;alerts',    // semicolon
            'weather,alerts',    // comma
            'weather|alerts',    // pipe
        ];

        foreach ($invalidTopics as $topic) {
            $this->assertFalse(
                NtfyNotifier::isValidTopicName($topic),
                "Topic '{$topic}' should be invalid"
            );
        }
    }

    public function testNtfyNotifierIsEnabledWithInvalidTopic(): void
    {
        // Test that NtfyNotifier considers itself disabled when topic is invalid
        $logger = new NullLogger();
        $notifier = new NtfyNotifier($logger, true, 'invalid topic!', null);
        
        $this->assertFalse($notifier->isEnabled(), 'NtfyNotifier should be disabled with invalid topic');
    }

    public function testNtfyNotifierIsEnabledWithValidTopic(): void
    {
        // Test that NtfyNotifier considers itself enabled when topic is valid
        $logger = new NullLogger();
        $notifier = new NtfyNotifier($logger, true, 'valid_topic', null);
        
        $this->assertTrue($notifier->isEnabled(), 'NtfyNotifier should be enabled with valid topic');
    }

    public function testNtfyNotifierSkipsInvalidTopic(): void
    {
        // Test that send() method logs error and returns early for invalid topic
        $logger = new class extends NullLogger {
            public array $logs = [];
            
            public function error($message, array $context = []): void
            {
                $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }
            
            public function info($message, array $context = []): void
            {
                $this->logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }
        };
        
        $notifier = new NtfyNotifier($logger, true, 'invalid topic!', null);
        $notifier->send('Test', 'Test message');
        
        // Should log that sending is skipped due to disabled/misconfigured status
        $this->assertCount(1, $logger->logs);
        $this->assertEquals('info', $logger->logs[0]['level']);
        $this->assertStringContainsString('skipped', $logger->logs[0]['message']);
    }
}