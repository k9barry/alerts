# Fix Summary: Authentication Issues in test_alert_workflow.php

## Problem

The `test_alert_workflow.php` script was failing with authentication errors:

1. **Pushover Error**: `HTTP 400 - application token is invalid`
2. **Ntfy Error**: `HTTP 403: forbidden`

## Root Cause

The script was falling back to placeholder credentials from `Config.php` when user credentials were not fully configured:

- **Pushover**: Line 227 used `$selectedUser['PushoverToken'] ?? Config::$pushoverToken`, which fell back to the example value `'t-example'` when the user's token was missing
- **Ntfy**: The script didn't validate that authentication credentials were actually set before attempting to send

The `Config.php` file contains example/placeholder values that are not valid API credentials:
- `Config::$pushoverUser = 'u-example'`
- `Config::$pushoverToken = 't-example'`

## Solution

Modified `scripts/test_alert_workflow.php` to:

1. **Validate both PushoverUser and PushoverToken** are set before attempting Pushover notifications
2. **Extract and trim credentials** before using them to avoid empty string issues
3. **Remove fallback to Config credentials** - only use user-specific credentials from the database
4. **Improve error messages** to clearly indicate which credentials are missing (e.g., "missing PushoverUser" vs "missing PushoverToken")
5. **Add logging for Ntfy authentication status** to help debug which authentication method is being used

## Changes Made

### Pushover Validation (lines 218-268)
```php
// Before: Only checked PushoverUser, fell back to Config::$pushoverToken
if (Config::$pushoverEnabled && !empty($selectedUser['PushoverUser'])) {
    // ... used $selectedUser['PushoverToken'] ?? Config::$pushoverToken

// After: Validate BOTH credentials, no fallback
$pushoverUser = trim($selectedUser['PushoverUser'] ?? '');
$pushoverToken = trim($selectedUser['PushoverToken'] ?? '');

if (Config::$pushoverEnabled && !empty($pushoverUser) && !empty($pushoverToken)) {
    // ... use $pushoverUser and $pushoverToken directly
```

### Ntfy Validation (lines 270-325)
```php
// Before: Only checked NtfyTopic, passed potentially empty credentials
if (Config::$ntfyEnabled && !empty($selectedUser['NtfyTopic'])) {
    // ... passed $selectedUser['NtfyUser'] ?? null directly

// After: Extract, trim, and validate credentials properly
$ntfyTopic = trim($selectedUser['NtfyTopic'] ?? '');
$ntfyUser = !empty($selectedUser['NtfyUser']) ? trim($selectedUser['NtfyUser']) : null;
$ntfyPassword = !empty($selectedUser['NtfyPassword']) ? trim($selectedUser['NtfyPassword']) : null;
$ntfyToken = !empty($selectedUser['NtfyToken']) ? trim($selectedUser['NtfyToken']) : null;

if (Config::$ntfyEnabled && !empty($ntfyTopic)) {
    // ... with logging to show which auth method is available
```

## Expected Behavior

After this fix:

1. **If user has valid credentials**: Notifications will be sent successfully
2. **If user is missing PushoverUser**: Script will skip with message "missing PushoverUser"
3. **If user is missing PushoverToken**: Script will skip with message "missing PushoverToken"
4. **If user is missing NtfyTopic**: Script will skip with message "missing NtfyTopic"
5. **No invalid API requests**: The script will never attempt to send with placeholder credentials like `'t-example'`

## Testing

All existing unit tests pass:
- ✅ `SendAlertMethodsTest.php` - 7 tests, 22 assertions
- ✅ `AlertWorkflowTest.php` - 6 tests, 16 assertions
- ✅ `NtfyNotifierTest.php` - 1 test, 1 assertion

## How to Use

Users must configure their notification credentials in the database via the web UI:

**For Pushover:**
- Set `PushoverUser` (user key from Pushover)
- Set `PushoverToken` (application token from Pushover)

**For Ntfy:**
- Set `NtfyTopic` (topic name to publish to)
- Optionally set authentication:
  - `NtfyToken` (for token-based auth), OR
  - `NtfyUser` + `NtfyPassword` (for basic auth)

If credentials are not configured, the test will skip sending notifications for that service with a clear message indicating what is missing.
