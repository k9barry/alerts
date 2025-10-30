<?php

namespace App\Service;

use App\Config;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

class NtfyNotifier
{
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly bool            $enabled,
    private readonly string          $topic,
    private readonly ?string         $titlePrefix
  )
  {
  }

  public function isEnabled(): bool
  {
    $topic = trim((string)$this->topic);
    return $this->enabled && $topic !== '';
  }

  /**
   * @param string $title
   * @param string $message
   * @param array{tags?:string[],priority?:int,click?:string,attach?:string,delay?:string} $options
   */
  public function send(string $title, string $message, array $options = []): void
  {
    if (!$this->isEnabled()) {
      $this->logger->info('Ntfy sending skipped (disabled or misconfigured)');
      return;
    }

    $topic = trim((string)$this->topic);
    if ($topic === '') {
      $this->logger->error('Ntfy sending aborted: empty topic');
      return;
    }

    $this->logger->info('Ntfy sending', [
      'topic' => $topic,
      'title' => $title,
    ]);

    $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);

    // Direct HTTP POST to ntfy topic endpoint via Guzzle
    try {
      $base = rtrim((string)Config::$ntfyBaseUrl, '/');
      if ($base === '') {
        throw new RuntimeException('Empty ntfy base URL in Config');
      }
      $url = $base . '/' . rawurlencode($topic);
      $http = new HttpClient([
        'timeout' => 15,
        'http_errors' => false,
      ]);

      $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
      if (!empty(Config::$ntfyToken)) {
        $headers['Authorization'] = 'Bearer ' . Config::$ntfyToken;
      } elseif (!empty(Config::$ntfyUser) && !empty(Config::$ntfyPassword)) {
        $headers['Authorization'] = 'Basic ' . base64_encode(Config::$ntfyUser . ':' . Config::$ntfyPassword);
      }

      // Set metadata via headers per ntfy spec
      $headers['X-Title'] = substr($fullTitle, 0, 200);
      if (!empty($options['tags'])) {
        $headers['X-Tags'] = implode(',', (array)$options['tags']);
      }
      if (isset($options['priority'])) {
        $headers['X-Priority'] = (string)(int)$options['priority'];
      }
      if (!empty($options['click'])) {
        $headers['X-Click'] = (string)$options['click'];
      }

      // Enforce ntfy length limits conservatively: message<=4096
      $body = substr((string)$message, 0, 4096);

      $resp = $http->post($url, ['headers' => $headers, 'body' => $body]);
      $status = $resp->getStatusCode();
      if ($status >= 200 && $status < 300) {
        $this->logger->info('Ntfy notification sent (http)', ['topic' => $this->topic, 'status' => $status]);
        return;
      }
      $body = (string)$resp->getBody();
      $this->logger->error('Ntfy HTTP send failed', ['status' => $status, 'body' => $body, 'url' => $url]);
    } catch (GuzzleException $ge) {
      $this->logger->error('Ntfy HTTP send failed (exception)', ['error' => $ge->getMessage()]);
    } catch (Throwable $e) {
      $this->logger->error('Ntfy HTTP send failed (throwable)', ['error' => $e->getMessage()]);
    }
  }
}
