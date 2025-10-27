# src/Http/WeatherClient.php

Purpose: Retrieve active weather alerts from api.weather.gov with conditional requests.

Behavior:
- Constructs a Guzzle client with a descriptive User-Agent and Accept headers.
- Uses RateLimiter to control request pace.
- Sends If-None-Match and If-Modified-Since when previous ETag/Last-Modified are available.
- On 304: returns an empty features list. On non-200: logs and returns empty.
- On 200: stores ETag and Last-Modified for the next call, parses JSON and returns as array.

Usage:
- $client = new WeatherClient(); $data = $client->fetchActive();

Notes:
- Logs errors via LoggerFactory on exceptions or unexpected statuses.
