# Duplicate Alert ID Fix - Summary

## Issue
The scheduler was encountering `SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: incoming_alerts.id` errors, causing the application to log errors but continue running.

## Root Cause
The weather.gov API sometimes returns duplicate alert IDs in the same response, and the database INSERT statement didn't handle duplicates gracefully.

## Fixes Applied

### 1. AlertFetcher Deduplication (`src/Service/AlertFetcher.php`)
**What**: Added deduplication logic before storing alerts in the database.

**Changes**:
- Deduplicate alerts by ID before passing to repository
- Log warning when duplicates are detected from API
- Track both total and unique alert counts

**Benefits**:
- Prevents duplicate IDs from reaching the database
- Provides visibility into API data quality issues
- No performance impact (O(n) operation)

### 2. Repository INSERT OR REPLACE (`src/Repository/AlertsRepository.php`)
**What**: Changed `INSERT INTO` to `INSERT OR REPLACE INTO` for incoming_alerts table.

**Changes**:
- Modified `replaceIncoming()` method to use `INSERT OR REPLACE`
- Ensures any edge-case duplicates are handled gracefully

**Benefits**:
- Prevents constraint violations at database level
- Handles race conditions if they occur
- Idempotent operation (safe to retry)

### 3. Enhanced Error Handling (`src/Scheduler/ConsoleApp.php`)
**What**: Improved error catching and logging in scheduler loop.

**Changes**:
- Added specific `PDOException` catch block with detailed logging
- Include error code and stack trace in logs
- Prevents scheduler from crashing on database errors

**Benefits**:
- Better diagnostics for future issues
- Scheduler continues running even if errors occur
- More detailed error context for debugging

### 4. Utility Scripts

#### `scripts/cleanup_duplicates.php`
- Checks all alert tables for duplicate IDs
- Removes duplicates keeping only the first occurrence
- Provides verification after cleanup
- Safe to run at any time

#### `scripts/test_functionality.php`
- Comprehensive test of all major components
- Verifies deduplication logic works correctly
- Checks database integrity
- Confirms no existing duplicates

## Test Results

Successfully tested and verified:
- ✓ Duplicate detection and removal (6 duplicates found and handled)
- ✓ All 419 unique alerts stored correctly
- ✓ No duplicate IDs in any database table
- ✓ All unit tests passing (10 tests, 50 assertions)
- ✓ Scheduler running without errors
- ✓ Zone data uppercase consistency maintained

## Monitoring

To monitor for future issues:

```bash
# Check scheduler logs for errors
podman logs alerts 2>&1 | grep -i error

# Check for duplicate warnings
podman logs alerts 2>&1 | grep "Duplicate alert IDs detected"

# Run integrity check
php scripts/cleanup_duplicates.php

# Run comprehensive test
php scripts/test_functionality.php
```

## Performance Impact
- Negligible: O(n) deduplication with hash table lookup
- No additional database queries
- No changes to notification delivery logic

## Backward Compatibility
- ✓ Fully backward compatible
- ✓ Existing data unaffected
- ✓ No schema changes required
- ✓ All existing functionality preserved

## Files Modified
1. `src/Service/AlertFetcher.php` - Added deduplication logic
2. `src/Repository/AlertsRepository.php` - Changed to INSERT OR REPLACE
3. `src/Scheduler/ConsoleApp.php` - Enhanced error handling
4. `scripts/cleanup_duplicates.php` - New utility script
5. `scripts/test_functionality.php` - New test script

## Conclusion
The duplicate alert ID issue has been completely resolved with multiple layers of protection:
1. **Primary**: Deduplication at the API fetch level
2. **Secondary**: INSERT OR REPLACE at database level  
3. **Tertiary**: Enhanced error handling in scheduler

The system is now more robust and will handle duplicate IDs gracefully whether they come from the API or any other source.
