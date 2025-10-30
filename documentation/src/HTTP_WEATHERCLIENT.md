# Http/WeatherClient.php

HTTP client for fetching active weather alerts from weather.gov API.

## Location
`src/Http/WeatherClient.php`

## Purpose
Fetches weather alerts with rate limiting and HTTP caching support.

## Usage
```php
use App\Http\WeatherClient;

$client = new WeatherClient();
$data = $client->fetchActive();
$features = $data['features'] ?? [];
```

## Features
- **Rate Limiting**: Integrates with RateLimiter
- **HTTP Caching**: Supports ETag and Last-Modified headers
- **Custom User-Agent**: Includes app name, version, and contact email
- **Error Handling**: Returns empty array on failures (fail-safe)
- **Content Negotiation**: Accepts GeoJSON and JSON

## HTTP Headers
```
User-Agent: alerts/0.1.0 (you@example.com)
Accept: application/geo+json, application/json;q=0.9, */*;q=0.8
If-None-Match: "etag-value" (if cached)
If-Modified-Since: "last-modified-date" (if cached)
```

## Response Handling
- **200 OK**: Parse and return JSON
- **304 Not Modified**: Return empty features array
- **Other**: Log warning and return empty array

## Configuration
- `WEATHER_API_URL`: Endpoint (default: https://api.weather.gov/alerts/active)
- `API_RATE_PER_MINUTE`: Rate limit
- `APP_NAME`, `APP_VERSION`, `APP_CONTACT_EMAIL`: User-Agent

## Implementation
- Guzzle HTTP client with 20-second timeout
- Stores ETag/Last-Modified for subsequent requests
- Non-throwing (http_errors: false)
