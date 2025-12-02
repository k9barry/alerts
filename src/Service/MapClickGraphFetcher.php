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
 * https://forecast.weather.gov/meteograms/Plotter.php?lat={lat}&lon={lon}&wfo={wfo}&...
 *
 * Note: To get the correct WFO (Weather Forecast Office) code, we first query
 * the NWS API at api.weather.gov/points/{lat},{lon} to get the gridId.
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

    /**
     * Base URL for the NWS API to get WFO information
     */
    private const NWS_API_BASE_URL = 'https://api.weather.gov';

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
                'Accept' => 'application/geo+json, application/json, image/png, image/jpeg, image/gif, */*;q=0.8'
            ]
        ]);
    }

    /**
     * Get the Weather Forecast Office (WFO) code for given coordinates.
     *
     * Queries the NWS API to get the gridId (WFO code) for a location.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return string|null WFO code or null on failure
     */
    public function getWfoCode(float $lat, float $lon): ?string
    {
        $url = sprintf(
            '%s/points/%.4f,%.4f',
            self::NWS_API_BASE_URL,
            $lat,
            $lon
        );

        try {
            $resp = $this->client->get($url);
            $status = $resp->getStatusCode();

            if ($status !== 200) {
                LoggerFactory::get()->warning('NWS API points request failed', [
                    'status' => $status,
                    'lat' => $lat,
                    'lon' => $lon,
                ]);
                return null;
            }

            $body = (string)$resp->getBody();
            $data = json_decode($body, true);

            if (isset($data['properties']['gridId'])) {
                $wfo = $data['properties']['gridId'];
                LoggerFactory::get()->debug('Got WFO code from NWS API', [
                    'wfo' => $wfo,
                    'lat' => $lat,
                    'lon' => $lon,
                ]);
                return $wfo;
            }

            LoggerFactory::get()->warning('No gridId in NWS API response', [
                'lat' => $lat,
                'lon' => $lon,
            ]);
            return null;
        } catch (GuzzleException $e) {
            LoggerFactory::get()->error('NWS API request error', [
                'error' => $e->getMessage(),
                'lat' => $lat,
                'lon' => $lon,
            ]);
            return null;
        } catch (Throwable $e) {
            LoggerFactory::get()->error('Unexpected error getting WFO code', [
                'error' => $e->getMessage(),
                'lat' => $lat,
                'lon' => $lon,
            ]);
            return null;
        }
    }

    /**
     * Build the meteogram URL for given coordinates.
     *
     * The meteogram is a PNG image showing hourly forecast data in graphical form.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param string|null $wfo Optional WFO code (Weather Forecast Office)
     * @return string Meteogram URL
     */
    public function buildMeteogramUrl(float $lat, float $lon, ?string $wfo = null): string
    {
        // The Plotter.php endpoint generates PNG meteogram images
        // Full parameter list based on NWS hourly weather graph format:
        //   lat, lon: coordinates
        //   wfo: Weather Forecast Office code (e.g., IND, OKX, SEW)
        //   zcode: zone code (ALL for auto-detect)
        //   gset: graph set (18 is comprehensive)
        //   gdiff: graph difference
        //   hour: forecast hours to display (48 hours)
        //   pop, temp, sky, rain, snow, fzra, sleet, wspd, wdir, rh: weather elements to show
        $params = [
            'lat' => number_format($lat, 4, '.', ''),
            'lon' => number_format($lon, 4, '.', ''),
            'wfo' => $wfo ?? 'ALL',
            'zcode' => 'ALL',
            'gset' => '18',
            'gdiff' => '3',
            'unit' => '0',
            'session' => time(),
            'hour' => '48',
            'pop' => '1',
            'temp' => '1',
            'sky' => '1',
            'rain' => '1',
            'snow' => '1',
            'fzra' => '1',
            'sleet' => '1',
            'wspd' => '1',
            'wdir' => '1',
            'rh' => '1',
        ];

        return self::METEOGRAM_BASE_URL . '?' . http_build_query($params);
    }

    /**
     * Fetch the meteogram image data for given coordinates.
     *
     * First attempts to get the WFO code from the NWS API for better results.
     * Returns the raw image data (PNG) or null on failure.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return array{data:string,content_type:string}|null Image data and content type, or null on failure
     */
    public function fetchMeteogramImage(float $lat, float $lon): ?array
    {
        // First, try to get the WFO code for this location
        $wfo = $this->getWfoCode($lat, $lon);
        
        $url = $this->buildMeteogramUrl($lat, $lon, $wfo);

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
