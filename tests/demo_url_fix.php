<?php
/**
 * Demonstration of URL fix for notification messages
 * 
 * This script demonstrates how the AlertProcessor now:
 * 1. Tries to get coordinates from zones table
 * 2. Falls back to extracting from alert geometry
 * 3. Only uses API URL as last resort
 * 4. Logs the URL source for each notification
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== URL Fix Demonstration ===\n\n";

// Example 1: Alert with geometry (common case from weather.gov)
$alertWithGeometry = [
    'id' => 'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.123456',
    'json' => json_encode([
        'id' => 'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.123456',
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [
                [
                    [-86.5, 40.0],  // [longitude, latitude]
                    [-86.0, 40.0],
                    [-86.0, 40.5],
                    [-86.5, 40.5],
                    [-86.5, 40.0],
                ]
            ]
        ],
        'properties' => [
            'event' => 'Severe Thunderstorm Warning',
            'headline' => 'Severe Thunderstorm Warning issued',
        ]
    ])
];

echo "Example 1: Alert with geometry\n";
echo "Alert ID (API URL): {$alertWithGeometry['id']}\n\n";

// Simulate coordinate extraction
$alert = json_decode($alertWithGeometry['json'], true);
$coords = $alert['geometry']['coordinates'][0][0] ?? null;
if ($coords) {
    $lon = $coords[0];
    $lat = $coords[1];
    $mapClickUrl = sprintf(
        'https://forecast.weather.gov/MapClick.php?lat=%s&lon=%s&lg=english&FcstType=graphical&menu=1',
        $lat,
        $lon
    );
    echo "✓ Coordinates extracted from geometry:\n";
    echo "  Latitude: $lat\n";
    echo "  Longitude: $lon\n";
    echo "✓ MapClick URL generated: $mapClickUrl\n";
    echo "✓ URL Source: alert_geometry\n\n";
}

// Example 2: Alert without geometry (rare case)
$alertWithoutGeometry = [
    'id' => 'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.789012',
    'json' => json_encode([
        'id' => 'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.789012',
        'type' => 'Feature',
        // No geometry field
        'properties' => [
            'event' => 'Special Weather Statement',
            'headline' => 'Special Weather Statement issued',
        ]
    ])
];

echo "Example 2: Alert without geometry (fallback case)\n";
echo "Alert ID (API URL): {$alertWithoutGeometry['id']}\n\n";

$alert2 = json_decode($alertWithoutGeometry['json'], true);
if (!isset($alert2['geometry'])) {
    echo "✗ No geometry in alert data\n";
    echo "✗ Zones table lookup also returned no coordinates\n";
    echo "→ Using API URL as fallback: {$alertWithoutGeometry['id']}\n";
    echo "→ URL Source: api_url_fallback\n";
    echo "→ Reason: neither zones table nor alert geometry provided coordinates\n\n";
}

echo "=== Logging Examples ===\n\n";

echo "When zones table has coordinates:\n";
echo "  Log: MapClick URL built from zones table\n";
echo "  Data: {alert_id, user_idx, lat, lon, url}\n";
echo "  URL Source: zones_table\n\n";

echo "When zones table fails but geometry exists:\n";
echo "  Log: MapClick URL built from alert geometry\n";
echo "  Data: {alert_id, user_idx, lat, lon, url, reason: 'zones table lookup returned no coordinates'}\n";
echo "  URL Source: alert_geometry\n\n";

echo "When both sources fail:\n";
echo "  Log: Using API URL fallback - no coordinates available (WARNING level)\n";
echo "  Data: {alert_id, user_idx, url, reason: 'neither zones table nor alert geometry provided coordinates'}\n";
echo "  URL Source: api_url_fallback\n\n";

echo "For each notification channel (Pushover, ntfy):\n";
echo "  Log: Sending [Channel] notification\n";
echo "  Data: {alert_id, user_idx, details_url, url_source}\n\n";

echo "=== Summary ===\n";
echo "✓ MapClick URLs are now preferred over API URLs\n";
echo "✓ Geometry extraction provides fallback when zones table is incomplete\n";
echo "✓ All URL decisions are logged with source and reason\n";
echo "✓ Dozzle logs will show which URL was sent for each notification\n";
