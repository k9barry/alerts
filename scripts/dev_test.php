#!/usr/bin/env php
<?php
declare(strict_types=1);

// Ensure development-friendly environment variables are set before bootstrap
$root = dirname(__DIR__);
$testDb = $root . '/data/test_alerts.sqlite';
if (file_exists($testDb)) {
    // Prefer test DB bundled with repo so host runs don't try to open container paths
    putenv('DB_PATH=' . $testDb);
    $_ENV['DB_PATH'] = $testDb;
}
// Disable remote notification services during local dev test to avoid accidental sends
putenv('PUSHOVER_ENABLED=false'); $_ENV['PUSHOVER_ENABLED'] = 'false';
putenv('NTFY_ENABLED=false'); $_ENV['NTFY_ENABLED'] = 'false';

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

use App\Service\AlertFetcher;
use App\Service\AlertProcessor;
use App\Logging\LoggerFactory;

// Simple smoke test: run fetcher and processor once and output counts
$fetcher = new AlertFetcher();
$processor = new AlertProcessor();

LoggerFactory::get()->info('Starting dev test run');
$stored = $fetcher->fetchAndStoreIncoming();
LoggerFactory::get()->info('Fetched incoming count', ['count' => $stored]);
$queued = $processor->diffAndQueue();
LoggerFactory::get()->info('Queued count', ['count' => $queued]);
$processor->processPending();
LoggerFactory::get()->info('Process pending complete');

echo "Dev test run complete. Stored={$stored}, queued={$queued}\n";
