<?php

namespace Alerts;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;

/**
 * Logger Factory
 * 
 * Creates and configures logger instances for the application.
 */
class LoggerFactory
{
    /**
     * Create a logger instance
     *
     * @param string $name Logger name
     * @param string $logLevel Log level (DEBUG, INFO, WARNING, ERROR)
     * @param string $logPath Path to log file
     * @return Logger Configured logger instance
     */
    public static function create(string $name, string $logLevel, string $logPath): Logger
    {
        $logger = new Logger($name);
        
        // Convert log level string to Monolog constant
        $level = constant(Logger::class . '::' . strtoupper($logLevel));
        
        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Format: [Y-m-d H:i:s] channel.LEVEL: message context
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            "Y-m-d H:i:s",
            true,
            true
        );
        
        // File handler
        $fileHandler = new StreamHandler($logPath, $level);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);
        
        // STDOUT handler for Docker logs (Dozzle)
        $stdoutHandler = new StreamHandler('php://stdout', $level);
        $stdoutHandler->setFormatter($formatter);
        $logger->pushHandler($stdoutHandler);
        
        // Error log handler as fallback
        $errorLogHandler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level);
        $errorLogHandler->setFormatter($formatter);
        $logger->pushHandler($errorLogHandler);
        
        // Add the IntrospectionProcessor
        $logger->pushProcessor(new IntrospectionProcessor());

        return $logger;
    }
}
