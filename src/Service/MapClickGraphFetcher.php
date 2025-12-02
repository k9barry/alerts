<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Logging\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * MapClickGraphFetcher
 *
 * Fetches the meteogram/graph image from the NWS MapClick page.
 * The meteogram is a graphical forecast showing weather conditions over time.
 *
 * The NWS provides a Plotter.php endpoint that generates meteogram images directly:
 * https://forecast.weather.gov/meteograms/Plotter.php?lat={lat}&lon={lon}&wfo=ALL&zcode={zone}&gession=...
 *
 * @package App\Service
 */
final class MapClickGraphFetcher
{
    private Client $client;

    /**
     * Base URL for the NWS meteogram plotter
     */
    private const METEOGRAM_BASE_URL = 'https://forecast.weather.gov/meteograms/Plotter.php';

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout' => 30,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => sprintf(
                    '%s/%s (%s)',
                    Config::$appName ?? 'alerts',
                    Config::$appVersion ?? '1.0.0',
                    Config::$contactEmail ?? 'weather@example.com'
                ),
                'Accept' => 'image/png, image/jpeg, image/gif, */*;q=0.8'
            ]
        ]);
    }

    /**
     * Build the meteogram URL for given coordinates.
     *
     * The meteogram is a PNG image showing hourly forecast data in graphical form.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return string Meteogram URL
     */
    public function buildMeteogramUrl(float $lat, float $lon): string
    {
        // The Plotter.php endpoint generates PNG meteogram images
        // Parameters:
        //   lat, lon: coordinates
        //   wfo: Weather Forecast Office (ALL means auto-detect)
        //   zcode: zone code (optional, we skip it for simplicity)
        //   gession: cache-busting session ID
        $params = [
            'lat' => number_format($lat, 4, '.', ''),
            'lon' => number_format($lon, 4, '.', ''),
            'wfo' => 'ALL',
            'zcode' => 'ALL',
            'gession' => time(),
        ];

        return self::METEOGRAM_BASE_URL . '?' . http_build_query($params);
    }

    /**
     * Fetch the meteogram image data for given coordinates.
     *
     * Returns the raw image data (PNG) or null on failure.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return array{data:string,content_type:string}|null Image data and content type, or null on failure
     */
    public function fetchMeteogramImage(float $lat, float $lon): ?array
    {
        $url = $this->buildMeteogramUrl($lat, $lon);

        LoggerFactory::get()->debug('Fetching meteogram image', [
            'lat' => $lat,
            'lon' => $lon,
            'url' => $url,
        ]);

        try {
            $resp = $this->client->get($url);
            $status = $resp->getStatusCode();

            if ($status !== 200) {
                LoggerFactory::get()->warning('Meteogram fetch failed', [
                    'status' => $status,
                    'lat' => $lat,
                    'lon' => $lon,
                ]);
                return null;
            }

            $contentType = $resp->getHeaderLine('Content-Type');
            $body = (string)$resp->getBody();

            // Verify we got an image
            if (!$this->isImageContentType($contentType)) {
                LoggerFactory::get()->warning('Meteogram response is not an image', [
                    'content_type' => $contentType,
                    'lat' => $lat,
                    'lon' => $lon,
                ]);
                return null;
            }

            // Verify minimum content length (sanity check)
            if (strlen($body) < 100) {
                LoggerFactory::get()->warning('Meteogram image too small', [
                    'size' => strlen($body),
                    'lat' => $lat,
                    'lon' => $lon,
                ]);
                return null;
            }

            LoggerFactory::get()->info('Meteogram image fetched successfully', [
                'size' => strlen($body),
                'content_type' => $contentType,
                'lat' => $lat,
                'lon' => $lon,
            ]);

            return [
                'data' => $body,
                'content_type' => $this->normalizeContentType($contentType),
            ];
        } catch (GuzzleException $e) {
            LoggerFactory::get()->error('Meteogram fetch error', [
                'error' => $e->getMessage(),
                'lat' => $lat,
                'lon' => $lon,
            ]);
            return null;
        } catch (Throwable $e) {
            LoggerFactory::get()->error('Unexpected error fetching meteogram', [
                'error' => $e->getMessage(),
                'lat' => $lat,
                'lon' => $lon,
            ]);
            return null;
        }
    }

    /**
     * Extract coordinates from a MapClick URL.
     *
     * @param string $url MapClick URL
     * @return array{lat:float,lon:float}|null Extracted coordinates or null
     */
    public function extractCoordsFromUrl(string $url): ?array
    {
        // Match lat and lon parameters in URL
        if (preg_match('/[?&]lat=(-?[\d.]+)/i', $url, $latMatch) &&
            preg_match('/[?&]lon=(-?[\d.]+)/i', $url, $lonMatch)) {
            return [
                'lat' => (float)$latMatch[1],
                'lon' => (float)$lonMatch[1],
            ];
        }

        return null;
    }

    /**
     * Fetch meteogram image from a MapClick URL.
     *
     * Extracts coordinates from the URL and fetches the corresponding meteogram.
     *
     * @param string $mapClickUrl The MapClick URL
     * @return array{data:string,content_type:string}|null Image data and content type, or null on failure
     */
    public function fetchFromMapClickUrl(string $mapClickUrl): ?array
    {
        $coords = $this->extractCoordsFromUrl($mapClickUrl);
        if ($coords === null) {
            LoggerFactory::get()->warning('Could not extract coordinates from MapClick URL', [
                'url' => $mapClickUrl,
            ]);
            return null;
        }

        return $this->fetchMeteogramImage($coords['lat'], $coords['lon']);
    }

    /**
     * Check if content type indicates an image.
     *
     * @param string $contentType HTTP Content-Type header
     * @return bool True if image content type
     */
    private function isImageContentType(string $contentType): bool
    {
        $lower = strtolower($contentType);
        return str_starts_with($lower, 'image/');
    }

    /**
     * Normalize content type to standard format.
     *
     * @param string $contentType HTTP Content-Type header
     * @return string Normalized content type (e.g., 'image/png')
     */
    private function normalizeContentType(string $contentType): string
    {
        // Extract just the MIME type, removing charset or other params
        $parts = explode(';', $contentType);
        $mimeType = trim($parts[0]);

        // Default to PNG if unclear
        if (empty($mimeType) || !str_starts_with(strtolower($mimeType), 'image/')) {
            return 'image/png';
        }

        return strtolower($mimeType);
    }
}
