<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Ntfy\Client;
use Ntfy\Server;
use Ntfy\Auth\Token as NtfyToken;
use Ntfy\Auth\User as NtfyUser;
use App\Service\MessageBuilderTrait;
use App\Service\NtfyNotifier;

final class AlertProcessor
{
  use MessageBuilderTrait;

  private AlertsRepository $alerts;
  private PushoverNotifier $pushover;
  private ?NtfyNotifier $ntfy = null;

    public function __construct()
    {
      $this->alerts = new AlertsRepository();
      $this->pushover = new PushoverNotifier();

      if (Config::$ntfyEnabled && trim((string)Config::$ntfyTopic) !== '') {
        $base = rtrim(Config::$ntfyBaseUrl, '/');
        $server = new Server($base);
        $auth = null;
        if (Config::$ntfyToken) {
          $auth = new NtfyToken(Config::$ntfyToken);
        } elseif (Config::$ntfyUser && Config::$ntfyPassword) {
          $auth = new NtfyUser(Config::$ntfyUser, Config::$ntfyPassword);
        }
        $client = new Client($server, $auth);
        $this->ntfy = new NtfyNotifier(
          LoggerFactory::get(),
          $client,
          true,
          Config::$ntfyTopic,
          Config::$ntfyTitlePrefix
        );
        LoggerFactory::get()->info('Ntfy configured', [
          'ntfy_enabled' => Config::$ntfyEnabled,
          'ntfy_topic' => Config::$ntfyTopic,
          'ntfy_base' => Config::$ntfyBaseUrl,
        ]);
      }
    }

    public function diffAndQueue(): int
    {
      $queuedCount = $this->alerts->queuePendingForNew();
      LoggerFactory::get()->info('Queued new alerts into pending', ['count' => $queuedCount]);
      return $queuedCount;
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
          $intersects = !empty(array_intersect($codes, $same)) || !empty(array_intersect($codes, $ugc));
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
          $results = [];

          $tasks = [];
          if (Config::$pushoverEnabled) {
            $tasks[] = function () use ($p) {
              return ['channel' => 'pushover', 'result' => $this->pushover->notifyDetailed($p)];
            };
          }
          if ($this->ntfy && $this->ntfy->isEnabled()) {
            $tasks[] = function () use ($p) {
              $props = json_decode($p['json'] ?? '{}', true)['properties'] ?? [];
              $title = $this->buildTitleFromProps($props, $p);
              $headline = $props['headline'] ?? ($p['headline'] ?? 'Weather Alert');
              $this->ntfy->send($title, (string)$headline, [
                'priority' => 3,
                'tags' => ['warning'],
              ]);
              return ['channel' => 'ntfy', 'result' => ['status' => 'sent']];
            };
          }

          // Execute tasks near-simultaneously
          foreach ($tasks as $task) {
            $results[] = $task();
          }

          $this->alerts->insertSentResult($p, ['status' => 'processed', 'channels' => $results]);
        } catch (Throwable $e) {
          LoggerFactory::get()->error('Failed processing pending alert', [
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
