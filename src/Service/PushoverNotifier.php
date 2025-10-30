<?php
namespace App\Service;

use App\Config;
use App\Logging\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Service\MessageBuilderTrait;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pushover notifier helper
 *
 * Wraps Guzzle calls to the Pushover API and provides rate limiting/pacing.
 */
final class PushoverNotifier
{
  use MessageBuilderTrait;

    private mixed $client;
    private float $lastSentAt = 0.0;

    public function __construct(mixed $client = null)
    {
        $this->client = $client ?? new Client([
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

  /**
   * Send a detailed Pushover notification and return result metadata.
   *
   * @param array $alertRow Row from alerts table (expected keys: json, id, event, headline, etc.)
   * @return array{status:string,attempts:int,error:string|null,request_id:string|null}
   */
  public function notifyDetailed(array $alertRow): array
    {
      $props = json_decode($alertRow['json'] ?? '{}', true)['properties'] ?? [];
      $title = $this->buildTitleFromProps($props, $alertRow);
      $message = $this->buildMessageFromProps($props, $alertRow);

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


}
