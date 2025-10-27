<?php
namespace App\Http;

use App\Config;
use App\Logging\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class WeatherClient
{
    private Client $client;
    private RateLimiter $limiter;
    private ?string $etag = null;
    private ?string $lastModified = null;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => sprintf('%s/%s (%s)', Config::$appName, Config::$appVersion, Config::$contactEmail),
                'Accept' => 'application/geo+json, application/json;q=0.9, */*;q=0.8'
            ]
        ]);
        $this->limiter = new RateLimiter(Config::$apiRatePerMinute);
    }

    public function fetchActive(): array
    {
        $this->limiter->await();
        $headers = [];
        if ($this->etag) {
            $headers['If-None-Match'] = $this->etag;
        }
        if ($this->lastModified) {
            $headers['If-Modified-Since'] = $this->lastModified;
        }
        try {
            $resp = $this->client->get(Config::$weatherApiUrl, ['headers' => $headers]);
        } catch (GuzzleException $e) {
            LoggerFactory::get()->error('HTTP request failed', ['error' => $e->getMessage()]);
            return ['features' => []];
        }

        $status = $resp->getStatusCode();
        if ($status === 304) {
            return ['features' => []];
        }
        if ($status !== 200) {
            LoggerFactory::get()->warning('Unexpected status from weather API', ['status' => $status]);
            return ['features' => []];
        }

        $this->etag = $resp->getHeaderLine('ETag') ?: $this->etag;
        $this->lastModified = $resp->getHeaderLine('Last-Modified') ?: $this->lastModified;

        $json = (string)$resp->getBody();
        $data = json_decode($json, true) ?: [];
        return $data;
    }
}
