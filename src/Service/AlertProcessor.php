<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Config;

final class AlertProcessor
{
    private AlertsRepository $alerts;
    private PushoverNotifier $notifier;

    public function __construct()
    {
        $this->alerts = new AlertsRepository();
        $this->notifier = new PushoverNotifier();
    }

    public function diffAndQueue(): int
    {
        $n = $this->alerts->queuePendingForNew();
        LoggerFactory::get()->info('Queued new alerts into pending', ['count' => $n]);
        return $n;
    }

    public function processPending(): void
    {
        $pending = $this->alerts->getPending();
      if (!$pending) {
        return;
      }

      $codes = array_map('strtoupper', Config::$weatherAlerts);

      $match = [];
      $nonMatch = [];

        foreach ($pending as $p) {
          $same = json_decode($p['same_array'] ?? '[]', true) ?: [];
          $ugc = json_decode($p['ugc_array'] ?? '[]', true) ?: [];
          $same = array_map('strtoupper', $same);
          $ugc = array_map('strtoupper', $ugc);
          $intersects = array_intersect($codes, $same) || array_intersect($codes, $ugc);
          if ($codes && !$intersects) {
            $nonMatch[] = $p;
          } else {
            $match[] = $p; // if no codes configured, treat all as matches
          }
        }

      // Remove non-matching from pending
      foreach ($nonMatch as $p) {
        $this->alerts->deletePendingById((string)$p['id']);
      }

      // Send notifications for matches
      foreach ($match as $p) {
        try {
          $result = $this->notifier->notifyDetailed($p);
          // persist sent result
          $this->alerts->insertSentResult($p, $result);
        } catch (\Throwable $e) {
          \App\Logging\LoggerFactory::get()->error('Failed processing pending alert', [
            'id' => $p['id'] ?? null,
            'error' => $e->getMessage(),
          ]);
        } finally {
          // Always remove from pending to avoid clogging the queue
          $this->alerts->deletePendingById((string)$p['id']);
        }
      }
    }
}
