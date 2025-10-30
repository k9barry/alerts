# Service/MessageBuilderTrait.php

Shared trait for building notification messages from alert data.

## Location
`src/Service/MessageBuilderTrait.php`

## Purpose
DRY principle: shared message formatting logic used by multiple notifiers.

## Methods

### buildTitleFromProps(array $props, array $row)
Builds notification title:
```
[EVENT] Headline
```
Example: `[TORNADO WARNING] Tornado Warning issued for Marion County`

### buildMessageFromProps(array $props, array $row)
Builds notification message body:
1. **S/C/U Line**: Severity/Certainty/Urgency
2. **Status Line**: Status/Message Type/Category
3. **Area Line**: Area description
4. **Time Line**: Effective → Expires (localized)
5. **Description**: Full alert description
6. **Instructions**: Recommended actions (if present)

Example:
```
S/C/U: Severe/Likely/Immediate
Status/Msg/Cat: Actual/Alert/Met
Area: Marion County
Time: 2025-10-30 14:30 → 2025-10-30 16:00

A tornado warning has been issued...

Instruction: Take shelter immediately.
```

### formatLocalTime(string|null $iso8601)
Converts ISO 8601 timestamp to local time:
- Uses Config::$timezone
- Format: `YYYY-MM-DD HH:MM`
- Returns "-" if null or invalid

## Usage
```php
use App\Service\MessageBuilderTrait;

class MyNotifier
{
    use MessageBuilderTrait;

    public function notify(array $alert) {
        $props = json_decode($alert['json'], true)['properties'];
        $title = $this->buildTitleFromProps($props, $alert);
        $message = $this->buildMessageFromProps($props, $alert);
        // Send notification...
    }
}
```

## Configuration
Uses `Config::$timezone` for timestamp localization.
