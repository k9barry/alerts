<?php

declare(strict_types=1);

namespace App\Logging;

use App\Config;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;

/**
 * LoggerFactory
 *
 * Initializes and exposes a Monolog logger pre-configured for the application.
 */
final class LoggerFactory
{
    private static ?Logger $logger = null;

    public static function init(): void
    {
        if (self::$logger !== null) {
            return;
        }
        $level = Level::fromName(strtoupper(Config::$logLevel));
        $stream = Config::$logChannel === 'stdout' ? 'php://stdout' : dirname(__DIR__, 2) . '/logs/app.log';
        $handler = new StreamHandler($stream, $level);
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true));

        $logger = new Logger('alerts');
        $logger->pushProcessor(new IntrospectionProcessor($level));
        $logger->pushHandler($handler);

        self::$logger = $logger;
    }

    public static function get(): Logger
    {
        if (self::$logger === null) {
            self::init();
        }
        return self::$logger;
    }
}
