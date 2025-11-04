<?php
/**
 * Download zones data from NWS
 * 
 * This script downloads the official weather zones data from weather.gov
 * and saves it to the data directory.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

$dir = dirname(Config::$dbPath);
$zonesFile = $dir . '/bp18mr25.dbx';

// Check if file already exists
if (file_exists($zonesFile)) {
    echo "Zones file already exists at: {$zonesFile}\n";
    echo "Do you want to re-download? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
    fclose($handle);
}

// Ensure data directory exists
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$url = Config::$zonesDataUrl;

echo "Downloading zones data from NWS...\n";
echo "URL: {$url}\n";

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
    exit(1);
}

// Save to file
$bytesWritten = file_put_contents($zonesFile, $data);

if ($bytesWritten === false) {
    fwrite(STDERR, "Failed to write zones file to: {$zonesFile}\n");
    exit(1);
}

echo "Successfully downloaded zones data ({$bytesWritten} bytes)\n";
echo "Saved to: {$zonesFile}\n";
echo "\nTo load this data into the database, run:\n";
echo "  php scripts/migrate.php\n";
