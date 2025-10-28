<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Config;

final class AlertProcessor
{
    private AlertsRepository $alerts;
  private PushoverNotifier $pushover;
  private ?NtfyNotifier $ntfy = null;

    public function __construct()
    {
        $this->alerts = new AlertsRepository();
      $this->pushover = new PushoverNotifier();

      if (Config::$ntfyEnabled) {
        $base = rtrim(Config::$ntfyBaseUrl, '/');
        $client = new \Joseph\Ntfy\NtfyClient($base);
        if (Config::$ntfyToken) {
          $client->setBearerToken(Config::$ntfyToken);
        } elseif (Config::$ntfyUser && Config::$ntfyPassword) {
          $client->setBasicAuth(Config::$ntfyUser, Config::$ntfyPassword);
        }
        $this->ntfy = new NtfyNotifier(
          \App\Logging\LoggerFactory::get(),
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

  private function buildTitleFromProps(array $props, array $row): string
  {
    $event = $props['event'] ?? ($row['event'] ?? 'Weather Alert');
    $headline = $props['headline'] ?? ($row['headline'] ?? $event);
    return sprintf('[%s] %s', strtoupper((string)$event), (string)$headline);
  }

  private function buildMessageFromProps(array $props, array $row): string
  {
    $fields = [
      'Msg' => $props['messageType'] ?? ($row['msg_type'] ?? null),
      'Status' => $props['status'] ?? ($row['status'] ?? null),
      'Category' => $props['category'] ?? ($row['category'] ?? null),
      'Severity' => $props['severity'] ?? ($row['severity'] ?? null),
      'Certainty' => $props['certainty'] ?? ($row['certainty'] ?? null),
      'Urgency' => $props['urgency'] ?? ($row['urgency'] ?? null),
      'Area' => $props['areaDesc'] ?? ($row['area_desc'] ?? null),
      'Effective' => $props['effective'] ?? ($row['effective'] ?? null),
      'Expires' => $props['expires'] ?? ($row['expires'] ?? null),
    ];
    $lines = [];
    $lines[] = sprintf('S/C/U: %s/%s/%s', $fields['Severity'] ?? '-', $fields['Certainty'] ?? '-', $fields['Urgency'] ?? '-');
    $lines[] = sprintf('Status/Msg/Cat: %s/%s/%s', $fields['Status'] ?? '-', $fields['Msg'] ?? '-', $fields['Category'] ?? '-');
    $lines[] = sprintf('Area: %s', $fields['Area'] ?? '-');
    $lines[] = sprintf('Time: %s → %s',
      $this->formatLocalTime($fields['Effective'] ?? null),
      $this->formatLocalTime($fields['Expires'] ?? null)
    );

    $desc = $props['description'] ?? ($row['description'] ?? null);
    if ($desc) {
      $lines[] = '';
      $lines[] = (string)$desc;
    }

    $instr = $props['instruction'] ?? ($row['instruction'] ?? null);
    if ($instr) {
      $lines[] = '';
      $lines[] = 'Instruction: ' . (string)$instr;
    }

    return implode("\n", array_filter($lines, fn($l) => $l !== null));
  }

  private function formatLocalTime($iso8601OrNull): string
  {
    if (!$iso8601OrNull || !is_string($iso8601OrNull)) {
      return '-';
    }
    try {
      $dt = new \DateTimeImmutable($iso8601OrNull);
      $tz = new \DateTimeZone(\App\Config::$timezone ?: 'UTC');
      $local = $dt->setTimezone($tz);
      return $local->format('Y-m-d H:i');
    } catch (\Throwable $e) {
      return (string)$iso8601OrNull;
    }
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
