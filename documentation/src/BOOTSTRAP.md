# bootstrap.php

Application initialization script loaded by all entry points.

## Location
`src/bootstrap.php`

## Purpose
Sets up the application environment before any business logic runs.

## Execution
Included by all scripts:
```php
require __DIR__ . '/../src/bootstrap.php';
```

## Steps

### 1. Autoloader
```php
require __DIR__ . '/../vendor/autoload.php';
```
Loads Composer autoloader for PSR-4 class loading.

### 2. Environment Loading
```php
use Dotenv\Dotenv;
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createUnsafeImmutable($root)->safeLoad();
}
```
- Finds repository root
- Loads `.env` file if present
- Makes variables available via `getenv()` and `$_ENV`
- `safeLoad()` doesn't override existing environment variables

### 3. Directory Creation
```php
@mkdir($root . '/data', 0777, true);
@mkdir($root . '/logs', 0777, true);
```
- Ensures required directories exist
- Permissions 0777 (may be limited by umask)
- Recursive creation
- `@` suppresses warnings if already exist

### 4. Configuration Initialization
```php
Config::initFromEnv();
```
Loads all environment variables into `Config` static properties.

### 5. Logger Initialization
```php
LoggerFactory::init();
```
Configures Monolog with JSON formatter and appropriate output.

## Notes
- Should be included exactly once per process
- Order matters (autoloader must be first)
- Idempotent (safe to call multiple times for directory creation)
- No business logic (pure initialization)

## Error Handling
- Missing vendor directory: Fatal error (expected)
- Missing .env: Silent (uses defaults)
- Directory creation failure: Silent (will error later when accessed)
