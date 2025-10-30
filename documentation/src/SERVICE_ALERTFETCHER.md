# Service/AlertFetcher.php

Fetches active alerts from weather.gov and stores them in the database.

## Location
`src/Service/AlertFetcher.php`

## Purpose
Orchestrates alert fetching: HTTP request → parsing → database storage.

## Key Method

### fetchAndStoreIncoming()
1. Call WeatherClient to fetch active alerts
2. Extract features from GeoJSON response
3. Parse SAME and UGC codes from geocode
4. Normalize structure (preserve full JSON + extracted fields)
5. Store via AlertsRepository.replaceIncoming()
6. Return count of alerts stored

## Data Flow
```
weather.gov API
  → WeatherClient (with rate limiting)
    → Parse GeoJSON features
      → Extract SAME/UGC codes
        → AlertsRepository.replaceIncoming()
          → incoming_alerts table
```

## Error Handling
- HTTP errors: WeatherClient returns empty array
- Empty response: Skips database replacement (preserves existing data)
- Database errors: Transaction rollback

## Logging
- Info: "Stored incoming alerts" with count
- Info: "No changes from API" if empty response

## Configuration
Uses Config values via WeatherClient and AlertsRepository.
