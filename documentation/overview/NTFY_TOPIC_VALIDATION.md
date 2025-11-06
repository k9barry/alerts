# NTFY Topic Name Validation

## Overview

This document describes the NTFY topic name validation feature that enforces the character set limitations per the NTFY specification.

## Character Set Requirements

NTFY topic names can only contain:
- **Letters**: A-Z, a-z
- **Numbers**: 0-9  
- **Underscores**: _
- **Hyphens**: -

## Implementation

### Server-Side Validation

1. **NtfyNotifier Class** (`src/Service/NtfyNotifier.php`):
   - Added `isValidTopicName()` static method for validation
   - Updated `isEnabled()` to check topic validity
   - Added validation in `send()` and `sendForUserWithTopic()` methods
   - Invalid topics are logged with helpful error messages

2. **Config Class** (`src/Config.php`):
   - Added validation during environment configuration loading
   - Throws `RuntimeException` if NTFY is enabled with invalid global topic
   - Added private `isValidNtfyTopicName()` method

3. **User API** (`public/users_table.php`):
   - Added validation for user-specific NTFY topics in create/update operations
   - Returns HTTP 400 with descriptive error message for invalid topics

### Client-Side Validation

1. **Real-time Input Validation**:
   - Added `isValidNtfyTopicName()` JavaScript function
   - Input field shows browser validation message for invalid topics
   - Uses HTML5 `setCustomValidity()` for native error display

2. **Form Submission Validation**:
   - Prevents form submission with invalid topics
   - Shows alert with clear error message

### Testing

- **Unit Tests** (`tests/NtfyTopicValidationTest.php`):
  - Comprehensive test coverage for valid and invalid topic names
  - Tests NtfyNotifier behavior with invalid topics
  - Validates that invalid topics disable the notifier

## Examples

### Valid Topic Names
- `weather_alerts`
- `weather-alerts`
- `WeatherAlerts123`
- `alerts_2024`
- `test-topic_123`

### Invalid Topic Names
- `weather alerts` (space)
- `weather.alerts` (dot)
- `weather@alerts` (special characters)
- `weather/alerts` (slash)
- `weather:alerts` (colon)

## Error Messages

- **Config Error**: "Invalid NTFY_TOPIC 'topic name': Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)"
- **API Error**: "Invalid Ntfy Topic: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)"
- **Log Error**: "Ntfy sending aborted: invalid topic name"

## Documentation Updates

- Updated `documentation/overview/CONFIGURATION.md` to include character set requirements
- Added character set specification to NTFY_TOPIC section

## Backward Compatibility

- Existing valid topic names continue to work unchanged
- Invalid topic names will be rejected with clear error messages
- No database migration required (validation is applied at runtime)

## Security Benefits

- Prevents potential injection attacks through topic names
- Ensures topics conform to NTFY server expectations
- Reduces possibility of server-side errors due to invalid characters
