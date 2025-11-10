#!/usr/bin/env php
<?php
/**
 * Demo script to show the difference between old and new ntfy message format
 * This demonstrates how ntfy messages now have the same detailed information as pushover
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Service\MessageBuilderTrait;

// Sample class using the trait
class MessageDemo {
    use MessageBuilderTrait;
    
    public function demonstrateFormat(array $alertRow): array {
        $props = json_decode($alertRow['json'] ?? '{}', true)['properties'] ?? [];
        return [
            'title' => $this->buildTitleFromProps($props, $alertRow),
            'message' => $this->buildMessageFromProps($props, $alertRow)
        ];
    }
}

// Initialize config for timezone support
Config::initFromEnv();

// Sample alert data
$sampleAlert = [
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
            'description' => 'At 1200 PM EST, severe thunderstorms were located along a line extending from Indianapolis to Greenwood, moving northeast at 40 mph. HAZARD...60 mph wind gusts and quarter size hail. SOURCE...Radar indicated. IMPACT...Hail damage to vehicles is expected. Expect wind damage to roofs, siding, and trees.',
            'instruction' => 'For your protection move to an interior room on the lowest floor of a building.'
        ]
    ])
];

$demo = new MessageDemo();
$result = $demo->demonstrateFormat($sampleAlert);

echo "\n";
echo "===========================================\n";
echo "NTFY ALERT MESSAGE FORMAT DEMONSTRATION\n";
echo "===========================================\n\n";

echo "BEFORE (Old Format - Missing Details):\n";
echo "--------------------------------------\n";
echo "Title: Severe Thunderstorm Warning\n";
echo "Body: Severe Thunderstorm Warning issued for Marion County\n\n";

echo "AFTER (New Format - Same as Pushover):\n";
echo "--------------------------------------\n";
echo "Title: " . $result['title'] . "\n";
echo "Body:\n" . $result['message'] . "\n\n";

echo "Key Features Added:\n";
echo "-------------------\n";
echo "✓ Severity/Certainty/Urgency (S/C/U)\n";
echo "✓ Status/Message Type/Category\n";
echo "✓ Area description\n";
echo "✓ Effective and expiration times (respects timezone: " . Config::$timezone . ")\n";
echo "✓ Full alert description\n";
echo "✓ Safety instructions\n";
echo "✓ Clickable link to NWS alert (via X-Click header)\n";
echo "✓ Respects ntfy's 4096 character limit\n";
echo "\n";
