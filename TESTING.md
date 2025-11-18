# Testing Guide

## Overview

This document provides comprehensive guidance on testing the Weather Alerts application, including unit tests,
integration tests, and manual testing procedures.

---

## Table of Contents

1. [Test Database Setup](#test-database-setup)
2. [Running Tests](#running-tests)
3. [Test Scripts](#test-scripts)
4. [Writing Tests](#writing-tests)
5. [Test Coverage](#test-coverage)
6. [Troubleshooting](#troubleshooting)

---

## Test Database Setup

### Why Use a Test Database?

The test database contains **sanitized data** to ensure:

- ✅ No production credentials are exposed
- ✅ No real users receive test notifications
- ✅ Safe to share with team members
- ✅ Consistent test environment

### Creating the Test Database

```bash
# Create test database from production database
php scripts/create_test_database.php
```

This script will:

1. Copy the production database structure
2. Sanitize all user data:
    - Names → "Test User1", "Test User2", etc.
    - Emails → "testuser1@example.com", etc.
    - Credentials → Test placeholders
3. Preserve zone alerts and other test data
4. Create `data/alerts_test.sqlite`

### Test Database Contents

After creation, the test database contains:

- **Users**: Sanitized test users with placeholder credentials
- **Zones**: Complete zone data (copied from production)
- **Alerts**: Historical alert data (if any)
- **Sent Alerts**: Historical records with sanitized request IDs

---

## Running Tests

### PHPUnit Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/TestButtonMapClickUrlTest.php

# Run with verbose output
vendor/bin/phpunit --verbose

# Run with code coverage (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage/
```

### Test Scripts with Test Database

Use the `TEST_MODE` environment variable to run scripts with the test database:

```bash
# Windows (PowerShell)
$env:TEST_MODE="true"
php scripts/oneshot_test.php

# Windows (CMD)
set TEST_MODE=true
php scripts/oneshot_test.php

# Linux/Mac
TEST_MODE=true php scripts/oneshot_test.php
```

### Available Test Scripts

| Script                    | Purpose                 | Test Mode Support |
|---------------------------|-------------------------|-------------------|
| `oneshot_test.php`        | One-time test execution | ✅ Yes             |
| `dev_test.php`            | Development testing     | ✅ Yes             |
| `test_functionality.php`  | Functionality tests     | ✅ Yes             |
| `test_alert_workflow.php` | Alert workflow tests    | ✅ Yes             |
| `run_unit_smoke.php`      | Unit and smoke tests    | ✅ Yes             |

---

## Test Scripts

### 1. One-Shot Test (`scripts/oneshot_test.php`)

Tests a single alert notification cycle.

```bash
# With test database
TEST_MODE=true php scripts/oneshot_test.php

# With production database (careful!)
php scripts/oneshot_test.php
```

**What it tests:**

- Fetching alerts from weather.gov API
- Processing new alerts
- Sending notifications to users
- Database operations

### 2. Development Test (`scripts/dev_test.php`)

Quick development testing script.

```bash
TEST_MODE=true php scripts/dev_test.php
```

**What it tests:**

- Basic functionality
- Configuration loading
- Database connectivity

### 3. Functionality Test (`scripts/test_functionality.php`)

Comprehensive functionality testing.

```bash
TEST_MODE=true php scripts/test_functionality.php
```

**What it tests:**

- All major features
- Error handling
- Edge cases

### 4. Alert Workflow Test (`scripts/test_alert_workflow.php`)

End-to-end alert processing workflow.

```bash
TEST_MODE=true php scripts/test_alert_workflow.php
```

**What it tests:**

- Complete alert lifecycle
- User matching
- Notification delivery
- Database state transitions

### 5. Unit and Smoke Tests (`scripts/run_unit_smoke.php`)

Runs PHPUnit tests and basic smoke tests.

```bash
TEST_MODE=true php scripts/run_unit_smoke.php
```

**What it tests:**

- All PHPUnit test suites
- Basic application health checks

---

## Writing Tests

### Test Structure

```php
<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

class MyFeatureTest extends TestCase
{
    use TestMigrationTrait; // For database setup
    
    protected function setUp(): void
    {
        parent::setUp();
        // Setup code here
    }
    
    protected function tearDown(): void
    {
        // Cleanup code here
        parent::tearDown();
    }
    
    public function testMyFeature(): void
    {
        // Arrange
        $input = 'test data';
        
        // Act
        $result = myFunction($input);
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Using TestMigrationTrait

The `TestMigrationTrait` provides database setup for tests:

```php
use TestMigrationTrait;

protected function setUp(): void
{
    parent::setUp();
    $this->runMigrations(); // Creates tables
    // Insert test data...
}
```

### Mocking External Services

```php
use Tests\Mocks\MockResponse;

// Mock HTTP client
$mockClient = $this->createMock(HttpClient::class);
$mockClient->method('post')
    ->willReturn(new MockResponse(200, '{"status":"ok"}'));

// Inject mock into service
$notifier = new NtfyNotifier(
    logger: null,
    enabled: true,
    topic: 'test',
    titlePrefix: null,
    httpClient: $mockClient
);
```

### Testing Database Operations

```php
public function testDatabaseOperation(): void
{
    $pdo = Connection::get();
    
    // Insert test data
    $stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email) VALUES (?, ?, ?)");
    $stmt->execute(['Test', 'User', 'test@example.com']);
    
    // Verify insertion
    $user = $pdo->query("SELECT * FROM users WHERE Email='test@example.com'")->fetch();
    $this->assertNotFalse($user);
    $this->assertEquals('Test', $user['FirstName']);
}
```

---

## Test Coverage

### Current Test Files

| Test File                         | Purpose                     | Coverage |
|-----------------------------------|-----------------------------|----------|
| `TestButtonMapClickUrlTest.php`   | MapClick URL generation     | ✅ High   |
| `NtfyNotifierTest.php`            | Ntfy notifications          | ✅ High   |
| `NtfyPerUserTopicTest.php`        | Per-user ntfy topics        | ✅ High   |
| `NtfyDetailedMessageTest.php`     | Detailed message formatting | ✅ High   |
| `NtfyTopicValidationTest.php`     | Topic name validation       | ✅ High   |
| `NtfyFailureTest.php`             | Failure handling            | ✅ High   |
| `NtfyAuditLoggingTest.php`        | Audit logging               | ✅ High   |
| `PushoverRetryAndFailureTest.php` | Pushover retry logic        | ✅ High   |
| `AlertWorkflowTest.php`           | Alert processing workflow   | ✅ High   |
| `ZoneAlertRoundtripTest.php`      | Zone alert storage          | ✅ High   |
| `UsersDownloadUploadTest.php`     | User backup/restore         | ✅ High   |
| `NotificationLoggingTest.php`     | Notification logging        | ✅ High   |
| `SendAlertMethodsTest.php`        | Alert sending methods       | ✅ High   |
| `NoMatchAlertTest.php`            | No matching users           | ✅ High   |
| `TestAlertWorkflowFieldsTest.php` | Alert field handling        | ✅ High   |

### Coverage Goals

- **Unit Tests**: 80%+ coverage of business logic
- **Integration Tests**: All major workflows covered
- **Edge Cases**: Error conditions and boundary cases
- **Regression Tests**: All fixed bugs have tests

---

## Troubleshooting

### Common Issues

#### 1. Test Database Not Found

**Error:**

```
Error: Test database not found at: data/alerts_test.sqlite
```

**Solution:**

```bash
php scripts/create_test_database.php
```

#### 2. Permission Denied

**Error:**

```
Permission denied: data/alerts_test.sqlite
```

**Solution:**

```bash
# Linux/Mac
chmod 600 data/alerts_test.sqlite

# Windows
# Right-click file → Properties → Security → Edit permissions
```

#### 3. Tests Failing After Schema Changes

**Error:**

```
SQLite error: no such column
```

**Solution:**

```bash
# Recreate test database with new schema
php scripts/create_test_database.php
```

#### 4. PHPUnit Not Found

**Error:**

```
'vendor/bin/phpunit' is not recognized
```

**Solution:**

```bash
# Install dependencies
composer install

# Verify installation
vendor/bin/phpunit --version
```

#### 5. Xdebug Not Installed (for coverage)

**Error:**

```
No code coverage driver available
```

**Solution:**

```bash
# Install Xdebug
# See: https://xdebug.org/docs/install

# Verify installation
php -m | grep xdebug
```

### Debug Mode

Enable debug logging for tests:

```bash
# Set log level to debug
export LOG_LEVEL=debug
TEST_MODE=true php scripts/oneshot_test.php
```

### Verbose Output

```bash
# PHPUnit verbose mode
vendor/bin/phpunit --verbose --debug

# Script verbose mode
TEST_MODE=true php scripts/oneshot_test.php --verbose
```

---

## Best Practices

### 1. Always Use Test Database for Development

```bash
# Good: Uses test database
TEST_MODE=true php scripts/oneshot_test.php

# Bad: Uses production database (risky!)
php scripts/oneshot_test.php
```

### 2. Clean Up After Tests

```php
protected function tearDown(): void
{
    // Clean up test data
    $pdo = Connection::get();
    $pdo->exec("DELETE FROM users WHERE Email LIKE 'test%'");
    
    parent::tearDown();
}
```

### 3. Use Descriptive Test Names

```php
// Good
public function testMapClickUrlGeneratedFromMockAlert(): void

// Bad
public function testUrl(): void
```

### 4. Test One Thing Per Test

```php
// Good: Tests one specific behavior
public function testUserCreationWithValidData(): void
{
    // Test user creation only
}

public function testUserCreationWithInvalidEmail(): void
{
    // Test email validation only
}

// Bad: Tests multiple things
public function testUserOperations(): void
{
    // Tests creation, update, delete all in one
}
```

### 5. Use Assertions Effectively

```php
// Good: Specific assertions
$this->assertEquals('expected', $actual);
$this->assertNotNull($result);
$this->assertCount(3, $array);

// Bad: Generic assertions
$this->assertTrue($result == 'expected'); // Use assertEquals instead
```

---

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.1'
        extensions: sqlite3, pdo_sqlite
        
    - name: Install dependencies
      run: composer install
      
    - name: Create test database
      run: php scripts/create_test_database.php
      
    - name: Run tests
      run: TEST_MODE=true vendor/bin/phpunit
```

---

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [PHP Testing Best Practices](https://www.phptherightway.com/#testing)
- [SQLite Testing](https://www.sqlite.org/testing.html)
- [Mocking in PHPUnit](https://phpunit.de/manual/current/en/test-doubles.html)

---

## Support

For questions or issues with testing:

1. Check this documentation
2. Review existing test files for examples
3. Check the `IMPROVEMENTS.md` file
4. Contact the development team

---

**Document Version**: 1.0  
**Last Updated**: 2024  
**Maintained By**: Alerts Development Team
