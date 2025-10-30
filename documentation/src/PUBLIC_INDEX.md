# public/index.php

Web application entry point.

## Location
`public/index.php`

## Current Implementation
Returns HTTP 404:
```php
require __DIR__ . '/../src/bootstrap.php';

http_response_code(404);
echo 'Not Found';
exit;
```

## Purpose
Placeholder for future web interface. Currently the application has no GUI.

## Future Plans
Potential features:
- View active alerts
- Browse notification history
- Manage user preferences
- Configure geographic filters
- View statistics/dashboards
- Acknowledge alerts

## Web Server
Can be served with PHP built-in server:
```sh
php -S 127.0.0.1:8080 -t public
```

Or via Docker (port 8080).

## Note
For now, use:
- **Dozzle** (http://localhost:9999) for logs
- **SQLite Browser** (http://localhost:3000) for database
- **CLI scripts** for all operations
