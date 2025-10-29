<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use VerifiedJoseph\Ntfy\Client;
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

      if (Config::$ntfyEnabled) {
        $base = rtrim(Config::$ntfyBaseUrl, '/');
        $client = new Client($base);
        if (Config::$ntfyToken) {
          $client->setToken(Config::$ntfyToken);
        } elseif (Config::$ntfyUser && Config::$ntfyPassword) {
          $client->setBasicAuth(Config::$ntfyUser, Config::$ntfyPassword);
        }
        $this->ntfy = new NtfyNotifier(
          LoggerFactory::get(),
          $client,
          true,
          Config::$ntfyTopic,
          Config::$ntfyTitlePrefix
        );
      }
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
              $message = $this->buildMessageFromProps($props, $p);
              $this->ntfy->send($title, $message, [
                'priority' => 3,
                'tags' => ['warning'],
                'click' => is_string($p['id'] ?? null) && preg_match('#^https?://#i', (string)$p['id']) ? (string)$p['id'] : null,
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
