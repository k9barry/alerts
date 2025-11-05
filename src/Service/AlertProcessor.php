<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use App\Service\MessageBuilderTrait;
use App\Service\NtfyNotifier;

use App\Service\PushoverNotifier;

/**
 * Class AlertProcessor
 *
 * Processes fetched alerts: diffs, queues, and sends notifications via configured notifiers.
 *
 * Responsibilities:
 * - Diff fetched alerts against stored alerts and queue new ones
 * - Process pending alerts and dispatch notifications via Pushover and ntfy
 */
final class AlertProcessor
{
  use MessageBuilderTrait;

  private AlertsRepository $alerts;
  private PushoverNotifier $pushover;
  private ?NtfyNotifier $ntfy = null;

    /**
     * AlertProcessor constructor.
     * Initializes repositories and notifiers based on configuration.
     */
    public function __construct()
    {
      $this->alerts = new AlertsRepository();
      $this->pushover = new PushoverNotifier();

      if (Config::$ntfyEnabled && trim((string)Config::$ntfyTopic) !== '') {
        $this->ntfy = new NtfyNotifier(
          LoggerFactory::get(),
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

    /**
     * Diff fetched alerts and queue pending new alerts.
     *
     * @return int Number of queued alerts
     */
    public function diffAndQueue(): int
    {
      $queuedCount = $this->alerts->queuePendingForNew();
      LoggerFactory::get()->info('Queued new alerts into pending', ['count' => $queuedCount]);
      return $queuedCount;
    }


  /**
   * Process pending alerts and dispatch notifications.
   * Successful or failed notifications are recorded and pending entries are removed.
   */
  public function processPending(): void
    {
        $pending = $this->alerts->getPending();
      if (!$pending) {
        return;
      }

      // For each pending alert, extract a canonical set of identifiers (STATE_ZONE, UGC, FIPS)
      foreach ($pending as $p) {
        try {
          $same = json_decode($p['same_array'] ?? '[]', true) ?: [];
          $ugc = json_decode($p['ugc_array'] ?? '[]', true) ?: [];
          $alertIds = [];
          foreach (array_merge($same, $ugc) as $v) {
            if (is_null($v)) continue;
            if (is_int($v) || (is_string($v) && preg_match('/^[0-9]+$/', $v))) {
              $alertIds[] = (string)$v;
            } elseif (is_string($v) && trim($v) !== '') {
              $alertIds[] = strtolower(trim($v));
            }
          }
          $alertIds = array_values(array_unique($alertIds));

          // Load all users (limit to 100 per your guidance) and match their ZoneAlert
          $db = \App\DB\Connection::get();
          $stmt = $db->prepare('SELECT idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, ZoneAlert FROM users ORDER BY idx DESC LIMIT 100');
          $stmt->execute();
          $users = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

          // Build notification title and headline for ntfy usage
          $props = json_decode($p['json'] ?? '{}', true)['properties'] ?? [];
          $event = (string)($props['event'] ?? ($p['event'] ?? 'Weather Alert'));
          $title = substr($event, 0, 200);
          $headline = (string)($props['headline'] ?? ($p['headline'] ?? ''));
          $click = null;
          $idUrl = $p['id'] ?? null;
          if (is_string($idUrl) && preg_match('#^https?://#i', $idUrl)) $click = $idUrl;

          $anyMatch = false;
          foreach ($users as $u) {
            $userZoneIds = $this->parseUserZoneAlert($u['ZoneAlert'] ?? '[]');
            // If user has no zones configured, skip them (don't send all alerts)
            if (empty($userZoneIds)) continue;
            // If alert doesn't match any of user's zones, skip this user
            if (empty(array_intersect($alertIds, $userZoneIds))) continue;
            $anyMatch = true;

            // send per-user notifications using their credentials
            $channels = [];
            $pushoverReqId = null;
            if (Config::$pushoverEnabled) {
              $res = $this->pushover->notifyDetailedForUser($p, $u);
              $channels[] = ['channel' => 'pushover', 'result' => $res];
              $pushoverReqId = $res['request_id'] ?? null;
            }
            if ($this->ntfy && $this->ntfy->isEnabled()) {
              // Get zone names for title prefix
              $zoneNames = $this->getZoneNamesForAlert($alertIds);
              $zoneTitlePrefix = !empty($zoneNames) ? implode(', ', array_slice($zoneNames, 0, 3)) : '';
              
              // use per-user send which prefers user's NtfyToken/NtfyUser+Password and NtfyTopic
              $this->ntfy->sendForUserWithTopic($title, $headline, ['priority' => 3, 'tags' => ['warning'], 'click' => $click], $u, $zoneTitlePrefix);
              $channels[] = ['channel' => 'ntfy', 'result' => ['status' => 'sent']];
            }

            // persist result per user
            $this->alerts->insertSentResult($p, ['status' => 'processed', 'channels' => $channels, 'request_id' => $pushoverReqId, 'user_id' => $u['idx'] ?? null]);
          }

          if (!$anyMatch) {
            // no users matched this alert; record as processed with no recipients
            $this->alerts->insertSentResult($p, ['status' => 'no_match', 'attempts' => 0, 'error' => 'no matching user zone']);
          }
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

  /**
   * Get zone names for the given alert IDs to use as title prefix
   * @param array $alertIds Array of UGC zone codes
   * @return array Array of zone names
   */
  private function getZoneNamesForAlert(array $alertIds): array
  {
    if (empty($alertIds)) {
      return [];
    }

    try {
      $pdo = \App\DB\Connection::get();
      $placeholders = str_repeat('?,', count($alertIds) - 1) . '?';
      $stmt = $pdo->prepare("SELECT DISTINCT NAME FROM zones WHERE STATE_ZONE IN ($placeholders) OR ZONE IN ($placeholders)");
      $stmt->execute(array_merge($alertIds, $alertIds));
      return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Exception $e) {
      LoggerFactory::get()->error('Failed to get zone names', [
        'alertIds' => $alertIds,
        'error' => $e->getMessage()
      ]);
      return [];
    }
  }

  /**
   * Parse user's ZoneAlert JSON string into array of zone identifiers.
   * Converts to uppercase for STATE_ZONE format for consistent matching.
   *
   * @param string $zoneAlert JSON string from user record
   * @return array Array of zone identifiers
   */
  private function parseUserZoneAlert(string $zoneAlert): array
  {
    if (trim($zoneAlert) === '' || trim($zoneAlert) === '[]') {
      return [];
    }

    $decoded = @json_decode($zoneAlert, true);
    if (!is_array($decoded)) {
      return [];
    }

    $result = [];
    foreach ($decoded as $zone) {
      $zone = trim((string)$zone);
      if ($zone === '') continue;
      
      // Convert STATE_ZONE format to uppercase for consistent matching
      if (preg_match('/^[a-z]{2,3}c?\d+$/i', $zone)) {
        $result[] = strtoupper($zone);
      } else {
        $result[] = $zone; // Keep FIPS codes as-is
      }
    }

    return array_unique($result);
  }
}
