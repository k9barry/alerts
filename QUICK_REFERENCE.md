# Quick Reference Guide

## Common Commands

### Testing

```bash
# Create test database
php scripts/create_test_database.php

# Run all tests
vendor/bin/phpunit

# Run specific test
vendor/bin/phpunit tests/TestButtonMapClickUrlTest.php

# Run with test database
TEST_MODE=true php scripts/oneshot_test.php

# Run with verbose output
vendor/bin/phpunit --verbose
```

### Database

```bash
# Run migrations
php scripts/migrate.php

# Access production database
sqlite3 data/alerts.sqlite

# Access test database
sqlite3 data/alerts_test.sqlite

# Backup database
cp data/alerts.sqlite data/alerts_backup_$(date +%Y%m%d).sqlite
```

### Development

```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Check code style
vendor/bin/phpcs src/

# Fix code style
vendor/bin/phpcbf src/
```

---

## Environment Variables

### Production

```bash
DB_PATH="data/alerts.sqlite"
LOG_LEVEL="info"
POLL_MINUTES="3"
PUSHOVER_ENABLED="true"
NTFY_ENABLED="true"
```

### Testing

```bash
TEST_MODE="true"
DB_PATH="data/alerts_test.sqlite"
LOG_LEVEL="debug"
POLL_MINUTES="1"
```

---

## File Locations

### Configuration

- `.env` - Production environment
- `.env.test` - Test environment
- `src/Config.php` - Configuration class

### Scripts

- `scripts/migrate.php` - Database migrations
- `scripts/create_test_database.php` - Create test DB
- `scripts/oneshot_test.php` - One-shot test
- `scripts/test_helper.php` - Test utilities

### Tests

- `tests/` - All test files
- `phpunit.xml` - PHPUnit configuration
- `tests/bootstrap.php` - Test bootstrap

### Documentation

- `README.md` - Main documentation
- `TESTING.md` - Testing guide
- `IMPROVEMENTS.md` - Code improvements
- `SUMMARY.md` - Executive summary

---

## Database Schema

### Users Table

```sql
CREATE TABLE users (
  idx INTEGER PRIMARY KEY,
  FirstName TEXT NOT NULL,
  LastName TEXT NOT NULL,
  Email TEXT NOT NULL UNIQUE,
  Timezone TEXT DEFAULT 'America/New_York',
  PushoverUser TEXT,
  PushoverToken TEXT,
  NtfyUser TEXT,
  NtfyPassword TEXT,
  NtfyToken TEXT,
  NtfyTopic TEXT,
  ZoneAlert TEXT DEFAULT '[]',
  CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt TEXT DEFAULT CURRENT_TIMESTAMP
);
```

### Zones Table

```sql
CREATE TABLE zones (
  idx INTEGER PRIMARY KEY AUTOINCREMENT,
  STATE TEXT NOT NULL,
  ZONE TEXT NOT NULL,
  NAME TEXT NOT NULL,
  STATE_ZONE TEXT,
  COUNTY TEXT,
  FIPS TEXT,
  LAT REAL,
  LON REAL,
  UNIQUE(STATE, ZONE)
);
```

---

## API Endpoints

### Users

- `GET /api/users` - List all users
- `GET /api/users/{id}` - Get user by ID
- `POST /api/users` - Create user
- `PUT /api/users/{id}` - Update user
- `DELETE /api/users/{id}` - Delete user

### Zones

- `GET /api/zones` - List zones
- `GET /api/zones?search={query}` - Search zones
- `GET /api/zones?all=1` - Get all zones

### Testing

- `POST /api/test-alert` - Send test notification

### Backup

- `GET /api/users/download` - Download users backup
- `POST /api/users/upload` - Upload users backup

---

## Common Tasks

### Add a New User

```php
$stmt = $pdo->prepare("INSERT INTO users 
    (FirstName, LastName, Email, Timezone, ZoneAlert) 
    VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['John', 'Doe', 'john@example.com', 'America/New_York', '["INZ040"]']);
```

### Query Zones

```php
$stmt = $pdo->prepare("SELECT * FROM zones WHERE STATE = ? AND LAT IS NOT NULL");
$stmt->execute(['IN']);
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Send Test Notification

```php
$notifier = new NtfyNotifier();
$result = $notifier->sendAlert(
    'test_topic',
    'Test Alert',
    'This is a test message',
    'https://example.com'
);
```

### Get Zone Coordinates

```php
$repo = new AlertsRepository();
$coords = $repo->getZoneCoordinates(['INZ040', 'INC040']);
// Returns: ['lat' => 39.7684, 'lon' => -86.1581]
```

---

## Troubleshooting

### Test Database Not Found

```bash
php scripts/create_test_database.php
```

### Permission Denied

```bash
chmod 600 data/alerts_test.sqlite
```

### Tests Failing

```bash
# Recreate test database
php scripts/create_test_database.php

# Run migrations
php scripts/migrate.php

# Check logs
tail -f logs/app.log
```

### MapClick URL Not Generated

1. Check zones table has coordinates:
   ```sql
   SELECT COUNT(*) FROM zones WHERE LAT IS NOT NULL;
   ```
2. Verify zone data in alert:
   ```php
   var_dump($alert['same_array'], $alert['ugc_array']);
   ```
3. Check AlertsRepository::getZoneCoordinates() logic

---

## Code Snippets

### Test Mode Check

```php
require __DIR__ . '/test_helper.php';

if (isTestMode()) {
    echo "Running in test mode\n";
}
```

### Database Transaction

```php
$pdo->beginTransaction();
try {
    // Your operations here
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

### Logging

```php
use App\Logging\LoggerFactory;

LoggerFactory::get()->info('Message', ['context' => 'value']);
LoggerFactory::get()->error('Error', ['exception' => $e->getMessage()]);
```

### Configuration

```php
use App\Config;

Config::initFromEnv();
$dbPath = Config::$dbPath;
$timezone = Config::$timezone;
```

---

## Testing Patterns

### Basic Test

```php
public function testFeature(): void
{
    // Arrange
    $input = 'test';
    
    // Act
    $result = myFunction($input);
    
    // Assert
    $this->assertEquals('expected', $result);
}
```

### Database Test

```php
public function testDatabaseOperation(): void
{
    $pdo = Connection::get();
    
    // Insert
    $stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email) VALUES (?, ?, ?)");
    $stmt->execute(['Test', 'User', 'test@example.com']);
    
    // Verify
    $user = $pdo->query("SELECT * FROM users WHERE Email='test@example.com'")->fetch();
    $this->assertNotFalse($user);
}
```

### Mock Test

```php
public function testWithMock(): void
{
    $mock = $this->createMock(HttpClient::class);
    $mock->method('post')->willReturn(new MockResponse(200, '{"ok":true}'));
    
    $service = new MyService($mock);
    $result = $service->doSomething();
    
    $this->assertTrue($result);
}
```

---

## Git Workflow

```bash
# Create feature branch
git checkout -b feature/my-feature

# Make changes and commit
git add .
git commit -m "Add feature description"

# Run tests before push
TEST_MODE=true vendor/bin/phpunit

# Push to remote
git push origin feature/my-feature

# Create pull request
# (Use GitHub/GitLab UI)
```

---

## Performance Tips

1. **Use prepared statements** - Reuse for multiple executions
2. **Batch operations** - Insert multiple rows in transaction
3. **Index frequently queried columns** - Add indexes in migrations
4. **Use EXPLAIN** - Analyze query performance
5. **VACUUM regularly** - Reclaim space and optimize

---

## Security Checklist

- [x] Use prepared statements
- [x] Validate all inputs
- [x] Escape output
- [x] Use HTTPS
- [x] Secure file permissions
- [x] Sanitize error messages
- [ ] Add CSRF tokens
- [ ] Implement rate limiting
- [ ] Add API authentication

---

## Useful SQL Queries

```sql
-- Count users
SELECT COUNT(*) FROM users;

-- Find zones by state
SELECT * FROM zones WHERE STATE = 'IN' AND LAT IS NOT NULL;

-- Recent alerts
SELECT * FROM incoming_alerts ORDER BY received_at DESC LIMIT 10;

-- User with most zones
SELECT Email, LENGTH(ZoneAlert) as zones_length 
FROM users 
ORDER BY zones_length DESC 
LIMIT 1;

-- Sent alerts by user
SELECT u.Email, COUNT(s.id) as alert_count
FROM users u
LEFT JOIN sent_alerts s ON u.idx = s.user_id
GROUP BY u.Email;
```

---

## Contact & Support

- **Documentation**: See `TESTING.md`, `IMPROVEMENTS.md`
- **Issues**: Check GitHub issues
- **Questions**: Contact development team

---

**Quick Reference Version**: 1.0  
**Last Updated**: 2024
