# src/Logging/LoggerFactory.php

Purpose: Configure and provide a Monolog logger instance.

Behavior:
- Initializes a Logger with IntrospectionProcessor and a StreamHandler.
- StreamHandler writes to stdout or logs/app.log based on Config::$logChannel.
- JSON formatter for structured logs, honoring Config::$logLevel.

Usage:
- LoggerFactory::init();
- $logger = LoggerFactory::get();

Notes:
- Idempotent initialization; get() ensures initialization occurred.
