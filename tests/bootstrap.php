<?php
// Test bootstrap - initialize Config with test values

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestMigrationTrait.php';

// Initialize Config with test defaults
App\Config::$appName = 'test-alerts';
App\Config::$appVersion = '0.0.0-test';
App\Config::$contactEmail = 'test@example.com';
App\Config::$pollMinutes = 3;
App\Config::$apiRatePerMinute = 4;
App\Config::$pushoverRateSeconds = 0; // No rate limiting in tests
App\Config::$vacuumHours = 24;
App\Config::$dbPath = ':memory:';
App\Config::$logChannel = 'stdout';
App\Config::$logLevel = 'error';
App\Config::$pushoverApiUrl = 'https://api.pushover.net/1/messages.json';
App\Config::$weatherApiUrl = 'https://api.weather.gov/alerts/active';
App\Config::$pushoverUser = 'test-user';
App\Config::$pushoverToken = 'test-token';
App\Config::$pushoverEnabled = true;
App\Config::$ntfyEnabled = true;
App\Config::$ntfyBaseUrl = 'https://ntfy.sh';
App\Config::$ntfyTopic = 'test-topic';
App\Config::$ntfyUser = null;
App\Config::$ntfyPassword = null;
App\Config::$ntfyToken = null;
App\Config::$ntfyTitlePrefix = 'TEST';
App\Config::$timezone = 'UTC';
