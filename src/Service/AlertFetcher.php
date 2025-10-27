<?php
namespace App\Service;

use App\Http\WeatherClient;
use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;

final class AlertFetcher
{
    private WeatherClient $client;
    private AlertsRepository $repo;

    public function __construct()
    {
        $this->client = new WeatherClient();
        $this->repo = new AlertsRepository();
    }

    public function fetchAndStoreIncoming(): int
    {
        $data = $this->client->fetchActive();
        $features = $data['features'] ?? [];
      if (empty($features)) {
        \App\Logging\LoggerFactory::get()->info('No changes from API (0 features). Skipping replace to preserve existing incoming_alerts.');
        return 0;
      }
        $alerts = [];
        foreach ($features as $f) {
            $id = $f['id'] ?? ($f['properties']['id'] ?? null);
            if (!$id) { continue; }
            $props = $f['properties'] ?? [];
            $same = $props['areaDesc'] ?? '';
            $ugc = $props['geocode']['UGC'] ?? ($props['UGC'] ?? []);
            $sameArray = $props['geocode']['SAME'] ?? ($props['SAME'] ?? []);
            if (!is_array($ugc)) { $ugc = []; }
            if (!is_array($sameArray)) { $sameArray = []; }
            $alerts[] = [
                'id' => (string)$id,
                'same_array' => array_values($sameArray),
                'ugc_array' => array_values($ugc),
                'feature' => $f,
            ];
        }
        // Normalize structure to store full feature JSON while preserving arrays
        $normalized = array_map(function($a) {
            $f = $a['feature'];
            unset($a['feature']);
            $a = $a + $f; // include geojson keys
            return $a;
        }, $alerts);

        $this->repo->replaceIncoming($normalized);
        LoggerFactory::get()->info('Stored incoming alerts', ['count' => count($normalized)]);
        return count($normalized);
    }
}
