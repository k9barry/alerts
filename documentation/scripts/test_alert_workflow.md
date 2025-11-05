# test_alert_workflow.php

Interactive test script for the complete alert notification workflow.

## Overview

This script tests the entire alert pipeline from API download to notification delivery. It uses a random real alert from the weather.gov API and prompts you to select which user should receive the test notification. All operations are logged to Dozzle with clear, structured reporting.

## Purpose

- **Validation**: Verify the complete alert workflow is functioning correctly
- **Testing**: Test notification channels (Pushover, Ntfy) with real alert data
- **Debugging**: Diagnose issues in the alert processing pipeline
- **Demonstration**: Show how alerts flow through the system

## Location

`scripts/test_alert_workflow.php`

## Prerequisites

- Database must be initialized (`migrate.php` has been run)
- At least one user configured in the users table
- User must have Pushover and/or Ntfy credentials configured

## Usage

```bash
php scripts/test_alert_workflow.php
```

## Interactive Workflow

### Step 1: Fetch Alerts
```
Step 1: Fetching alerts from weather.gov API...
  ✓ Fetched 45 alerts from API
```

Downloads current active alerts from the weather.gov API.

### Step 2: Select Random Alert
```
Step 2: Selecting a random alert for testing...
  ✓ Selected alert: Severe Thunderstorm Warning
    ID: urn:oid:2.49.0.1.840.0.abcd1234
    Severity: Severe
    Area: Marion County
```

Randomly picks one alert for the test message.

### Step 3: Retrieve Users
```
Step 3: Retrieving users list...
  ✓ Found 3 user(s)
```

Loads all users from the database.

### Step 4: User Selection
```
Step 4: Select a user to receive the test alert:

----------------------------------------------------------------------
ID    Name                 Email                          Services     
----------------------------------------------------------------------
1     John Doe             john@example.com               Pushover, Ntfy
2     Jane Smith           jane@example.com               Pushover
3     Bob Johnson          bob@example.com                Ntfy
----------------------------------------------------------------------

Enter user ID to send test alert to: _
```

Displays an interactive menu for user selection.

### Step 5: Send Notifications
```
Step 5: Building and sending test alert message...
  Message prepared:
  ------------------------------------------------------------------
  [TEST ALERT]
  
  Event: Severe Thunderstorm Warning
  Severity: Severe | Certainty: Observed | Urgency: Immediate
  Headline: Severe Thunderstorm Warning issued April...
  Area: Marion County
  Effective: 2024-04-15T14:30:00-04:00
  Expires: 2024-04-15T15:30:00-04:00
  
  At 230 PM EDT, severe thunderstorms were located...
  ------------------------------------------------------------------

  Sending via Pushover...
    ✓ Pushover sent successfully
      Request ID: abcd-1234-efgh-5678
  Sending via Ntfy...
    ✓ Ntfy sent successfully
```

Sends the test alert to the selected user via all configured channels.

### Step 6: Final Report
```
======================================================================
TEST WORKFLOW REPORT
======================================================================

Test Alert Details:
  Event: Severe Thunderstorm Warning
  Alert ID: urn:oid:2.49.0.1.840.0.abcd1234
  Severity: Severe
  Area: Marion County

Target User:
  Name: John Doe
  Email: john@example.com
  User ID: 1

Notification Results:
  ✓ Pushover: SUCCESS
  ✓ Ntfy: SUCCESS

======================================================================
Test completed successfully!
Check Dozzle logs at http://localhost:9999 for detailed information.
======================================================================
```

## Test Message Format

The test message includes:
- **[TEST ALERT]** prefix for easy identification
- Event type
- Severity, certainty, and urgency levels
- Headline (if present)
- Area description
- Effective and expiration timestamps
- Full alert description
- Link to NWS alert details

## Notification Channels

### Pushover
- Requires user's `PushoverUser` and `PushoverToken` configured
- Reports success with request ID
- Reports failures with error message

### Ntfy
- Requires user's `NtfyTopic` configured
- Optional: `NtfyUser`, `NtfyPassword`, or `NtfyToken` for authentication
- Reports success or failure

### Skipped Channels
Channels are skipped if:
- Feature is disabled globally (`PUSHOVER_ENABLED=false` or `NTFY_ENABLED=false`)
- User doesn't have credentials configured

## Logging

All operations are logged to the application logger, which outputs to Dozzle:

```json
{
  "message": "=== STARTING ALERT WORKFLOW TEST ===",
  "level": "INFO",
  "channel": "alerts",
  "datetime": "2024-04-15T14:30:00+00:00"
}
```

Log entries include:
- Test start/completion
- API fetch results
- Alert selection details
- User selection
- Notification attempts and results
- Any errors encountered

## Exit Codes

- **0**: Test completed successfully
- **1**: Test failed (no alerts available, no users found, notification error, etc.)

## Example Error Scenarios

### No Alerts Available
```
Step 1: Fetching alerts from weather.gov API...
  ✓ Fetched 0 alerts from API

No alerts available for testing. Exiting.
```

### No Users Found
```
Step 3: Retrieving users list...
  ✗ No users found in database
    Please add at least one user via the web interface at http://localhost:8080
```

### Invalid User ID
```
Enter user ID to send test alert to: 99

  ✗ Invalid user ID
```

### Notification Failure
```
Sending via Pushover...
  ✗ Pushover failed
    Error: Invalid user key
```

## Viewing Logs in Dozzle

1. Open Dozzle: http://localhost:9999
2. Select the `alerts` container
3. Filter for recent logs
4. Look for structured JSON logs with test workflow messages

## Related

- [`oneshot_test.php`](../../scripts/oneshot_test.php) - Automated one-shot test (source file)
- [`test_functionality.php`](../../scripts/test_functionality.php) - Component functionality tests (source file)
- [AlertProcessor](../src/SERVICE_ALERTPROCESSOR.md) - Alert processing logic
- [Logging](../src/LOGGING_FACTORY.md) - Logging configuration

## Troubleshooting

### API Connection Issues
```
✗ Test failed with error:
  cURL error 6: Could not resolve host: api.weather.gov
```
**Solution**: Check internet connectivity and DNS resolution

### Database Not Initialized
```
✗ Test failed with error:
  SQLSTATE[HY000]: General error: 1 no such table: users
```
**Solution**: Run `php scripts/migrate.php` first

### All Channels Skipped
```
⊘ Pushover skipped (not configured for user)
⊘ Ntfy skipped (not configured for user)
```
**Solution**: Configure notification credentials for the user via the web UI

## Best Practices

1. **Run regularly**: Test the workflow periodically to catch issues early
2. **Test both channels**: Verify both Pushover and Ntfy if you use them
3. **Check logs**: Always review Dozzle logs for detailed diagnostics
4. **Use test users**: Consider creating a dedicated test user to avoid spamming real users
5. **Verify delivery**: Check that notifications actually arrive on your devices

## Security Considerations

- Test messages contain real alert data from weather.gov
- Messages will be sent to the selected user's real notification endpoints
- All API calls and results are logged
- Test data is stored in the database like production data
