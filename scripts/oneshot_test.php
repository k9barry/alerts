<?php
declare(strict_types=1);

use App\Config;
use App\Logging\LoggerFactory;
use App\Scheduler\ConsoleApp;
use App\Service\AlertFetcher;
use App\Service\AlertProcessor;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

require __DIR__ . '/../vendor/autoload.php';

// Auto-test overrides: use a separate test DB for this run only
$originalDbPath = getenv('DB_PATH') !== false ? getenv('DB_PATH') : null;
$testDbPath = __DIR__ . '/../data/test_alerts.sqlite';
putenv('DB_PATH=' . $testDbPath);
// Respect TEST_FORCE_SEND only if provided by caller; do not set a default here

require __DIR__ . '/../src/bootstrap.php';

// Ensure schema exists in the selected DB by running migrations automatically
// This will create required tables for a fresh test DB path
try {
  // Prefer direct include to ensure same PHP process and env
  require __DIR__ . '/migrate.php';
} catch (Throwable $e) {
  // Fallback to separate process if include fails
  if (function_exists('passthru')) {
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/migrate.php');
    passthru($cmd, $code);
  }
}

// Configure verbose logging to timestamped file under ./logs
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
  mkdir($logDir, 0777, true);
}
// Use Windows-safe timestamp (no colons) for filename
$ts = (new DateTimeImmutable('now'))->format('Y_m_d H-i-s');
$logFile = $logDir . '/' . $ts . '.log';

// Reconfigure logger to verbose file handler
$logger = LoggerFactory::get();
if (method_exists($logger, 'pushHandler')) {
  // Monolog v3: StreamHandler requires PSR-3 compatible level
  $streamHandlerClass = StreamHandler::class;
  $level = Level::Debug;
  $handler = new $streamHandlerClass($logFile, $level);
  // Remove existing handlers if method exists
  if (method_exists($logger, 'setHandlers')) {
    $logger->setHandlers([$handler]);
  } else {
    $logger->pushHandler($handler);
  }
}
$logger->info('Starting one-shot test run', [
  'log_file' => $logFile,
  'db_path' => getenv('DB_PATH'),
  'ntfy_enabled' => Config::$ntfyEnabled,
  'pushover_enabled' => Config::$pushoverEnabled,
  'ntfy_topic' => Config::$ntfyTopic ?? null,
  'force_send' => getenv('TEST_FORCE_SEND') === '1',
]);

try {
  // 1) Fetch the latest API data once
  $fetcher = new AlertFetcher();
  $nFetched = $fetcher->fetchAndStoreIncoming();
  $logger->info('Fetch completed', ['queued' => $nFetched]);

  // 2) Diff/queue any new alerts if applicable
  $processor = new AlertProcessor();
  $nQueued = $processor->diffAndQueue();
  $logger->info('Diff and queue completed', ['queued' => $nQueued]);

  // 3) Process pending and send notifications
  // Note: TEST_FORCE_SEND is no longer needed as filtering is based on user ZoneAlert only
  $processor->processPending();
  $logger->info('Process pending completed');
} catch (Throwable $e) {
  $logger->error('One-shot test failed', [
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
  ]);
  fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

$logger->info('One-shot test run finished successfully');

// Restore original env to avoid side effects
if ($originalDbPath !== null) {
  putenv('DB_PATH=' . $originalDbPath);
} else {
  putenv('DB_PATH');
}

echo "Done. Logs written to: {$logFile}\n";
