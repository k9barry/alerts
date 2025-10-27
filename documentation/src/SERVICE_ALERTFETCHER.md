# src/Service/AlertFetcher.php

Purpose: Fetch active alerts and store a normalized snapshot into incoming_alerts.

Behavior:
- Calls WeatherClient::fetchActive() to get the feed.
- Builds a list of alerts extracting id, SAME/UGC arrays, and retains the full feature JSON.
- Normalizes each alert: keeps original geojson fields and stores arrays separately.
- Writes the set to DB via AlertsRepository::replaceIncoming().
- Logs stored count; if the feed returns 0 features, logs and returns 0 without replacing DB to preserve previous set.

Usage:
- $fetcher = new AlertFetcher(); $count = $fetcher->fetchAndStoreIncoming();
