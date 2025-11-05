<?php
/**
 * Check and download zones data if not present
 * 
 * This script checks if the zones data file exists and downloads it if necessary.
 * It's designed to be run automatically during Docker container startup.
 * 
 * @package Alerts
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

// Determine the zones file path
$dir = dirname(Config::$dbPath);
$zonesFileName = basename(parse_url(Config::$zonesDataUrl, PHP_URL_PATH));
$zonesFile = $dir . '/' . $zonesFileName;

// Check if file already exists
if (file_exists($zonesFile)) {
    $fileSize = filesize($zonesFile);
    echo "Zones data file exists: {$zonesFile} ({$fileSize} bytes)\n";
    exit(0);
}

// Ensure data directory exists
if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create data directory: {$dir}\n");
        exit(1);
    }
}

$url = Config::$zonesDataUrl;

echo "Zones data file not found. Downloading from NWS...\n";
echo "URL: {$url}\n";

// Download the file
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; AlertsApp/1.0)');

$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || $data === false) {
    fwrite(STDERR, "Failed to download zones data.\n");
    fwrite(STDERR, "HTTP Code: {$httpCode}\n");
    if ($error) {
        fwrite(STDERR, "Error: {$error}\n");
    }
    fwrite(STDERR, "Warning: Continuing without zones data. You can download it later with: php scripts/download_zones.php\n");
    // Don't fail - allow container to start without zones data
    exit(0);
}

// Save to file
$bytesWritten = file_put_contents($zonesFile, $data);

if ($bytesWritten === false) {
    fwrite(STDERR, "Failed to write zones file to: {$zonesFile}\n");
    fwrite(STDERR, "Warning: Continuing without zones data. You can download it later with: php scripts/download_zones.php\n");
    // Don't fail - allow container to start without zones data
    exit(0);
}

echo "Successfully downloaded zones data ({$bytesWritten} bytes)\n";
echo "Saved to: {$zonesFile}\n";
