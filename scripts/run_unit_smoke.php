#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\MessageBuilderTrait;

// Minimal smoke runner that exercises MessageBuilderTrait without phpunit installed
$ok = true;
try {
    $obj = new class {
        use MessageBuilderTrait;
    };
    $props = [
        'event' => 'Test Event',
        'headline' => 'Headline here',
        'messageType' => 'Alert',
        'status' => 'Actual',
        'category' => 'Met',
        'severity' => 'Severe',
        'certainty' => 'Observed',
        'urgency' => 'Immediate',
        'areaDesc' => 'Test Area',
        'effective' => '2025-10-30T12:00:00Z',
        'expires' => '2025-10-30T13:00:00Z',
        'description' => 'Detailed description here'
    ];
    $row = ['id' => '1'];
    $title = $obj->buildTitleFromProps($props, $row);
    $message = $obj->buildMessageFromProps($props, $row);
    if (stripos($title, 'TEST EVENT') === false) { $ok = false; echo "Title missing expected content\n"; }
    if (stripos($message, 'Detailed description here') === false) { $ok = false; echo "Message missing description\n"; }
} catch (Throwable $e) {
    echo "Exception during test: " . $e->getMessage() . "\n";
    $ok = false;
}
if ($ok) echo "Unit smoke test passed\n"; else echo "Unit smoke test FAILED\n";

