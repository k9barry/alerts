# src/bootstrap.php

Purpose: Initialize application environment, configuration, and logging prior to use.

Behavior:
- Loads Composer autoload.
- Loads .env if present using Dotenv::createUnsafeImmutable(...)->safeLoad().
- Ensures data/ and logs/ directories exist.
- Initializes configuration via Config::initFromEnv().
- Initializes logging via LoggerFactory::init().

Usage:
- Require this file early in all entry points (scripts and web index).
