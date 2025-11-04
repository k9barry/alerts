<?php
namespace App\Service;

use App\Http\WeatherClient;
use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;

/**
 * AlertFetcher
 *
 * Responsible for fetching active alerts from the upstream Weather API and writing
 * normalized incoming rows into the incoming_alerts table.
 */
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

        // Deduplicate by ID to prevent constraint violations
        $deduplicated = [];
        $seenIds = [];
        foreach ($normalized as $alert) {
            $id = $alert['id'] ?? null;
            if ($id && !isset($seenIds[$id])) {
                $deduplicated[] = $alert;
                $seenIds[$id] = true;
            }
        }

        if (count($normalized) !== count($deduplicated)) {
            LoggerFactory::get()->warning('Duplicate alert IDs detected from API', [
                'total' => count($normalized),
                'unique' => count($deduplicated),
                'duplicates' => count($normalized) - count($deduplicated)
            ]);
        }

        $this->repo->replaceIncoming($deduplicated);
        LoggerFactory::get()->info('Stored incoming alerts', ['count' => count($deduplicated)]);
        return count($deduplicated);
    }
}
