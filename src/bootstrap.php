<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Logging\LoggerFactory;
use Dotenv\Dotenv;

/**
 * Application bootstrap
 *
 * Loads environment, sets up directories, and initializes configuration and logging.
 */
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
  // Export .env values into process environment so getenv() works
  Dotenv::createUnsafeImmutable($root)->safeLoad();
}
@mkdir($root . '/data', 0777, true);
@mkdir($root . '/logs', 0777, true);

Config::initFromEnv();
LoggerFactory::init();