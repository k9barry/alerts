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

    public function notify(string $alertId, string $json): void
    {
        $payload = json_decode($json, true) ?: [];
        $title = $payload['properties']['headline'] ?? 'Weather Alert';
        $message = $payload['properties']['description'] ?? ($payload['properties']['event'] ?? 'Alert');
        $body = [
            'token' => Config::$pushoverToken,
            'user' => Config::$pushoverUser,
            'title' => $title,
            'message' => substr($message, 0, 1024),
            'priority' => 0,
        ];

        $attempts = 0; $ok = false; $error = null;
        while ($attempts < 3 && !$ok) {
            $attempts++;
            $this->pace();
            try {
                $resp = $this->client->post(Config::$pushoverApiUrl, ['form_params' => $body]);
                $ok = $resp->getStatusCode() === 200;
                if (!$ok) {
                    $error = 'HTTP ' . $resp->getStatusCode();
                }
            } catch (GuzzleException $e) {
                $error = $e->getMessage();
            }
        }

        $status = $ok ? 'success' : 'failure';
        LoggerFactory::get()->info('Pushover send result', [
            'alert_id' => $alertId,
            'status' => $status,
            'attempts' => $attempts,
            'error' => $error,
        ]);

        // persist sent record
        $this->recordSent($alertId, null, $json, $status, $attempts, $ok ? null : $error);
    }

    private function recordSent(string $alertId, ?int $userId, string $json, string $status, int $attempts, ?string $error): void
    {
        $db = \App\DB\Connection::get();
        $stmt = $db->prepare('INSERT INTO sent_alerts (alert_id, user_id, json, send_status, send_attempts, sent_at, error_message) VALUES (:alert_id, :user_id, :json, :status, :attempts, CURRENT_TIMESTAMP, :error)');
        $stmt->execute([
            ':alert_id' => $alertId,
            ':user_id' => $userId,
            ':json' => $json,
            ':status' => $status,
            ':attempts' => $attempts,
            ':error' => $error,
        ]);
    }
}
