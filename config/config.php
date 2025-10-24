<?php

/**
 * Configuration Management
 * 
 * This file handles loading and accessing configuration values
 * from environment variables with fallback defaults.
 */

/**
 * Load environment variables from .env file
 *
 * @return void
 */
function loadEnv(): void
{
    $envFile = __DIR__ . '/../.env';
    
    if (!file_exists($envFile)) {
        return;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Don't override existing environment variables
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

/**
 * Get configuration value from environment
 *
 * @param string $key Configuration key
 * @param mixed $default Default value if not found
 * @return mixed Configuration value
 */
function config(string $key, $default = null)
{
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// Load environment variables
loadEnv();

// Application configuration
return [
    'app' => [
        'name' => config('APP_NAME', 'alerts'),
        'version' => config('APP_VERSION', '1.0.0'),
        'env' => config('APP_ENV', 'development'),
    ],
    
    'contact' => [
        'email' => config('CONTACT_EMAIL', 'user@example.com'),
    ],
    
    'database' => [
        'path' => config('DB_PATH', __DIR__ . '/../data/alerts.db'),
    ],
    
    'api' => [
        'base_url' => config('API_BASE_URL', 'https://api.weather.gov/alerts'),
        'rate_limit' => (int)config('API_RATE_LIMIT', 4),
        'rate_period' => (int)config('API_RATE_PERIOD', 60),
    ],
    
    'logging' => [
        'level' => config('LOG_LEVEL', 'DEBUG'),
        'path' => config('LOG_PATH', __DIR__ . '/../logs/alerts.log'),
    ],
    
    'scheduler' => [
        'fetch_interval' => (int)config('FETCH_INTERVAL', 300),
        'vacuum_interval_days' => (int)config('VACUUM_INTERVAL_DAYS', 7),
        'archive_retention_days' => (int)config('ARCHIVE_RETENTION_DAYS', 30),
    ],
];
