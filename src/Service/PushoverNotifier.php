<?php
namespace App\Service;

use App\Config;
use App\Logging\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class PushoverNotifier
{
    private Client $client;
    private float $lastSentAt = 0.0;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 15,
            'http_errors' => false,
        ]);
    }

    private function pace(): void
    {
        $now = microtime(true);
        $delta = $now - $this->lastSentAt;
        $minGap = max(1, Config::$pushoverRateSeconds);
        if ($delta < $minGap) {
            usleep((int)round(($minGap - $delta) * 1_000_000));
        }
        $this->lastSentAt = microtime(true);
    }

  public function notifyDetailed(array $alertRow): array
    {
      $props = json_decode($alertRow['json'] ?? '{}', true)['properties'] ?? [];
      $title = $this->buildTitle($props, $alertRow);
      $message = $this->buildMessage($props, $alertRow);

        $body = [
            'token' => Config::$pushoverToken,
            'user' => Config::$pushoverUser,
          'title' => substr($title, 0, 250),
            'message' => substr($message, 0, 1024),
            'priority' => 0,
        ];
      // Add link to NWS alert page if id is a URL
      $idUrl = $alertRow['id'] ?? null;
      if (is_string($idUrl) && preg_match('#^https?://#i', $idUrl)) {
        $body['url'] = $idUrl;
        $body['url_title'] = 'Details';
      }

      $attempts = 0;
      $ok = false;
      $error = null;
      $requestId = null;
        while ($attempts < 3 && !$ok) {
            $attempts++;
            $this->pace();
            try {
                $resp = $this->client->post(Config::$pushoverApiUrl, ['form_params' => $body]);
                $ok = $resp->getStatusCode() === 200;
                if (!$ok) {
                  $statusCode = $resp->getStatusCode();
                  $respBody = (string)$resp->getBody();
                  $respJson = json_decode($respBody, true) ?: [];
                  $apiErrors = $respJson['errors'] ?? null;
                  $error = 'HTTP ' . $statusCode . ($apiErrors ? (' - ' . implode('; ', (array)$apiErrors)) : '');
                } else {
                  $respJson = json_decode((string)$resp->getBody(), true) ?: [];
                  $requestId = $respJson['request'] ?? null;
                }
            } catch (GuzzleException $e) {
                $error = $e->getMessage();
            }
        }

        $status = $ok ? 'success' : 'failure';
        LoggerFactory::get()->info('Pushover send result', [
          'alert_id' => $alertRow['id'] ?? null,
            'status' => $status,
            'attempts' => $attempts,
            'error' => $error,
        ]);

      return [
        'status' => $status,
        'attempts' => $attempts,
        'error' => $error,
        'request_id' => $requestId,
      ];
    }

  private function buildTitle(array $props, array $row): string
  {
    $event = $props['event'] ?? ($row['event'] ?? 'Weather Alert');
    $headline = $props['headline'] ?? ($row['headline'] ?? $event);
    return sprintf('[%s] %s', strtoupper((string)$event), (string)$headline);
    }

  private function buildMessage(array $props, array $row): string
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
      $lines[] = sprintf('Time: %s → %s', $fields['Effective'] ?? '-', $fields['Expires'] ?? '-');

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
}
