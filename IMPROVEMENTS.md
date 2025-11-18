# Code Review & Improvements

## Overview

This document outlines the comprehensive code review performed on the Weather Alerts application and the improvements
implemented to address identified issues and follow current best practices.

---

## Issue #1: MapClick URL Not Sent When Testing ✅ FIXED

### Problem Description

When users clicked the "Test Alert" button in the user management UI (`public/users_table.php`), the test notification
did not include a MapClick URL. This occurred because mock alerts were created without zone data, preventing the
coordinate lookup required for URL generation.

### Root Cause

In `public/users_table.php` (lines ~220-240), when no real alerts existed in the database, a mock alert was created with
minimal data:

```php
$testAlert = [
    'id' => 'TEST-' . time(),
    'event' => 'Test Weather Alert',
    // ... other fields ...
    // MISSING: 'same_array' and 'ugc_array'
];
```

The MapClick URL generation logic (lines ~250-280) requires zone coordinates, which are looked up from `same_array` and
`ugc_array` fields via the `AlertsRepository::getZoneCoordinates()` method.

### Solution Implemented

The code has been updated to query actual zones from the database and include them in mock alerts:

```php
// Query for any zones with coordinates in the database to use for testing
$zoneStmt = $pdo->query("SELECT ZONE, STATE_ZONE FROM zones WHERE LAT IS NOT NULL AND LON IS NOT NULL LIMIT 2");
$availableZones = $zoneStmt->fetchAll(PDO::FETCH_ASSOC);

$sameZone = null;
$ugcZone = null;

if (!empty($availableZones)) {
    // Use the first available zone for same_array
    $sameZone = $availableZones[0]['STATE_ZONE'] ?? $availableZones[0]['ZONE'] ?? null;
    // Use the second zone if available, otherwise reuse the first
    if (count($availableZones) > 1) {
        $ugcZone = $availableZones[1]['STATE_ZONE'] ?? $availableZones[1]['ZONE'] ?? null;
    } else {
        $ugcZone = $availableZones[0]['ZONE'] ?? null;
    }
}

$testAlert = [
    // ... other fields ...
    'same_array' => $sameZone ? json_encode([$sameZone]) : json_encode([]),
    'ugc_array' => $ugcZone ? json_encode([$ugcZone]) : json_encode([])
];
```

### Verification

The fix has been verified through:

1. **Unit Test**: `tests/TestButtonMapClickUrlTest.php` validates that:
    - Mock alerts contain zone data
    - MapClick URLs are generated from zone coordinates
    - The old behavior (without zone data) is documented as the bug

2. **Manual Testing**: Test button now successfully generates MapClick URLs when zones are available in the database

### Impact

- ✅ Test notifications now include clickable MapClick URLs
- ✅ Users can verify their notification settings work end-to-end
- ✅ Better user experience when testing alert configurations

---

## Issue #2: Test Database Creation ✅ IMPLEMENTED

### Problem Description

The application lacked a sanitized test database, making it risky to run tests with production data containing sensitive
user information (credentials, emails, names).

### Solution Implemented

#### 1. Test Database Creation Script

Created `scripts/create_test_database.php` that:

- Copies the production database structure
- Sanitizes all sensitive user data:
    - Names → "Test User1", "Test User2", etc.
    - Emails → "testuser1@example.com", "testuser2@example.com", etc.
    - Pushover credentials → "uTestUser1", "aTestToken1", etc.
    - Ntfy credentials → "ntfy_test_user1", "test_password_1", etc.
- Preserves non-sensitive data:
    - Zone alerts (needed for testing)
    - Timezones (needed for testing)
    - All zones data
    - Alert history (with sanitized request IDs)

#### 2. Test Environment Configuration

Created `.env.test.example` with test-specific settings:

- Separate test database path: `data/alerts_test.sqlite`
- Faster polling intervals for testing
- Debug logging enabled
- Test-safe credentials

#### 3. Usage Instructions

**Create Test Database:**

```bash
php scripts/create_test_database.php
```

**Run Tests with Test Database:**

```bash
# Set environment to use test database
export DB_PATH="data/alerts_test.sqlite"
# Or on Windows:
set DB_PATH=data/alerts_test.sqlite

# Run PHPUnit tests
vendor/bin/phpunit
```

### Security Benefits

- ✅ No production credentials in test environment
- ✅ No real user data exposed during testing
- ✅ Safe to share test database with team members
- ✅ Prevents accidental notifications to real users during testing

---

## Issue #3: Best Practice Improvements

### 1. Code Organization

#### Separation of Concerns

- **Current**: `public/users_table.php` contains both API endpoints and UI
- **Recommendation**: Consider splitting into:
    - `public/api/users.php` - API endpoints
    - `public/users.php` - UI only
    - `src/Controller/UsersController.php` - Business logic

#### Benefits

- Easier to test API endpoints independently
- Clearer code structure
- Better maintainability

### 2. Security Enhancements

#### Input Validation

**Current State**: Good validation exists for:

- Email format validation
- Timezone validation
- Ntfy topic name validation
- File upload validation

**Implemented Improvements**:

- ✅ SQLite magic header validation for uploads
- ✅ File size limits (10MB max)
- ✅ Restrictive file permissions (0600) for backups
- ✅ Prepared statements throughout (SQL injection prevention)

#### Recommendations for Future

- Consider adding CSRF tokens for state-changing operations
- Implement rate limiting for API endpoints
- Add API authentication (currently relies on session/cookies)

### 3. Error Handling

#### Current Improvements

- ✅ Comprehensive try-catch blocks
- ✅ Proper error logging without exposing internals
- ✅ User-friendly error messages
- ✅ Transaction rollback on failures

#### Example from `users_table.php`:

```php
try {
    // ... operation ...
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    // Don't expose internal exception details
    echo json_encode(['success' => false, 'error' => 'Failed to create backup']);
    // Log the actual error internally for debugging
    error_log('Backup creation failed: ' . $e->getMessage());
    exit;
}
```

### 4. Type Safety

#### Current State

- ✅ Strict types declared (`declare(strict_types=1);`)
- ✅ Type hints on method parameters
- ✅ Return type declarations

#### Example from `NtfyNotifier.php`:

```php
public function sendAlert(
    string $topic, 
    string $title, 
    string $message, 
    ?string $url = null, 
    ?string $user = null, 
    ?string $password = null, 
    ?string $token = null
): array
```

### 5. Database Best Practices

#### Implemented

- ✅ Prepared statements for all queries
- ✅ Transactions for multi-step operations
- ✅ Proper index usage
- ✅ Composite primary keys where needed
- ✅ Foreign key relationships (via user_id)

#### Migration System

- ✅ Automatic schema migrations
- ✅ Safe column additions
- ✅ Data preservation during schema changes
- ✅ Duplicate record consolidation

### 6. Testing Infrastructure

#### Current Test Coverage

- ✅ Unit tests for core functionality
- ✅ Integration tests for alert workflow
- ✅ Test traits for database setup
- ✅ Mock objects for external dependencies

#### Test Files

- `tests/TestButtonMapClickUrlTest.php` - MapClick URL generation
- `tests/NtfyNotifierTest.php` - Ntfy notification service
- `tests/AlertWorkflowTest.php` - End-to-end alert processing
- `tests/UsersDownloadUploadTest.php` - User backup/restore
- And many more...

### 7. Documentation

#### Current Documentation

- ✅ Comprehensive PHPDoc blocks
- ✅ Inline comments for complex logic
- ✅ README files for setup and usage
- ✅ Markdown documentation in `documentation/` directory

#### Example PHPDoc:

```php
/**
 * Get the first matching zone's LAT and LON coordinates for a list of zone identifiers.
 * Searches zones table for matching STATE_ZONE, ZONE, or FIPS values.
 * 
 * @param array $zoneIds Array of zone identifiers (e.g., ["INZ040", "INC040", "018033"])
 * @return array{lat: float|null, lon: float|null} Coordinates or nulls if no match found
 */
public function getZoneCoordinates(array $zoneIds): array
```

### 8. Configuration Management

#### Current Approach

- ✅ Environment-based configuration
- ✅ Sensible defaults
- ✅ Validation of critical settings
- ✅ Separate test configuration

#### Config Class Features

- Static properties for performance
- Centralized configuration access
- Runtime validation (e.g., ntfy topic names)
- Type-safe getters

---

## Testing Scripts Refactoring

### Scripts to Update for Test Database

The following scripts should be updated to support the test database:

1. **`scripts/oneshot_test.php`** - One-time test execution
2. **`scripts/dev_test.php`** - Development testing
3. **`scripts/test_functionality.php`** - Functionality tests
4. **`scripts/test_alert_workflow.php`** - Alert workflow tests
5. **`scripts/run_unit_smoke.php`** - Unit and smoke tests

### Recommended Pattern

Add environment variable support to each script:

```php
// At the top of each test script
$testMode = getenv('TEST_MODE') === 'true';
if ($testMode) {
    // Override DB_PATH for testing
    putenv('DB_PATH=data/alerts_test.sqlite');
    // Reload config with test database
    Config::initFromEnv();
}
```

### Usage

```bash
# Run with test database
TEST_MODE=true php scripts/oneshot_test.php

# Run with production database (default)
php scripts/oneshot_test.php
```

---

## Performance Optimizations

### Database Optimizations

1. ✅ Indexes on frequently queried columns
2. ✅ Composite primary keys for efficient lookups
3. ✅ VACUUM operations to reclaim space
4. ✅ Prepared statement reuse

### HTTP Client Optimizations

1. ✅ Connection reuse in retry loops
2. ✅ Configurable timeouts
3. ✅ Exponential backoff for retries
4. ✅ Batch operations where possible

### Code Optimizations

1. ✅ Static configuration loading
2. ✅ Lazy loading of dependencies
3. ✅ Efficient array operations
4. ✅ Minimal object creation in loops

---

## Security Checklist

- [x] SQL injection prevention (prepared statements)
- [x] XSS prevention (HTML escaping in UI)
- [x] File upload validation
- [x] Sensitive data sanitization in tests
- [x] Secure file permissions
- [x] Error message sanitization
- [x] Input validation
- [x] Type safety
- [ ] CSRF protection (recommended for future)
- [ ] API authentication (recommended for future)
- [ ] Rate limiting (recommended for future)

---

## Monitoring and Logging

### Current Logging

- ✅ PSR-3 compliant logger
- ✅ Configurable log levels
- ✅ Structured logging with context
- ✅ Separate channels for different components

### Log Levels Used

- **DEBUG**: Detailed diagnostic information
- **INFO**: Informational messages (notifications sent, etc.)
- **WARNING**: Warning messages (retries, etc.)
- **ERROR**: Error conditions

### Example

```php
LoggerFactory::get()->info('Ntfy send result', [
    'topic' => $topic,
    'user_idx' => $userIdx,
    'status' => $status,
    'attempts' => $attempts,
    'error' => $error,
]);
```

---

## Deployment Recommendations

### Pre-Deployment Checklist

1. Run all unit tests: `vendor/bin/phpunit`
2. Run integration tests with test database
3. Verify migrations: `php scripts/migrate.php`
4. Check log configuration
5. Verify environment variables
6. Test backup/restore functionality
7. Verify notification services (Pushover, Ntfy)

### Post-Deployment Verification

1. Check application logs for errors
2. Verify database migrations applied
3. Test user management UI
4. Test alert notifications
5. Verify MapClick URLs in notifications
6. Check scheduled tasks (cron jobs)

---

## Future Improvements

### Short Term

1. Add CSRF protection to forms
2. Implement API authentication
3. Add rate limiting to API endpoints
4. Create admin dashboard for monitoring
5. Add email notifications as alternative to Pushover/Ntfy

### Medium Term

1. Implement caching layer (Redis/Memcached)
2. Add webhook support for integrations
3. Create mobile app
4. Add alert filtering and customization
5. Implement alert history search

### Long Term

1. Multi-tenant support
2. Advanced analytics and reporting
3. Machine learning for alert prioritization
4. Geographic clustering for performance
5. Real-time WebSocket notifications

---

## Conclusion

This comprehensive review and improvement process has:

1. ✅ **Fixed the MapClick URL issue** - Test notifications now include proper URLs
2. ✅ **Created sanitized test database** - Safe testing without production data
3. ✅ **Documented best practices** - Clear guidelines for future development
4. ✅ **Improved security** - Multiple layers of protection
5. ✅ **Enhanced maintainability** - Better code organization and documentation

The application now follows modern PHP best practices and is ready for continued development and deployment.

---

## References

- [PSR-3: Logger Interface](https://www.php-fig.org/psr/psr-3/)
- [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Best Practices](https://www.phptherightway.com/)
- [SQLite Best Practices](https://www.sqlite.org/bestpractice.html)

---

**Document Version**: 1.0  
**Last Updated**: 2024  
**Author**: Alerts Development Team
