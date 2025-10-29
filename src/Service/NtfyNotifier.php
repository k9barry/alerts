<?php

namespace App\Service;

use App\Config;
use Ntfy\Client;
use Ntfy\Message;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

class NtfyNotifier
{
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ?Client         $client,
    private readonly bool            $enabled,
    private readonly string          $topic,
    private readonly ?string         $titlePrefix
  )
  {
  }

  public function isEnabled(): bool
  {
    $topic = trim((string)$this->topic);
    return $this->enabled && $this->client !== null && $topic !== '';
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

    // First attempt: use library client if provided
    $libError = null;
    if ($this->client instanceof Client) {
      try {
        $msg = new Message($topic, (string)$message);
        $msg->title($fullTitle);
        if (!empty($options['tags'])) {
          $msg->tags($options['tags']);
        }
        if (isset($options['priority'])) {
          $msg->priority((int)$options['priority']);
        }
        if (!empty($options['attach'])) {
          $msg->attach($options['attach']);
        }
        if (!empty($options['delay'])) {
          $msg->delay($options['delay']);
        }
        $this->client->send($msg);
        $this->logger->info('Ntfy notification sent (library)', ['topic' => $this->topic]);
        return;
      } catch (Throwable $e) {
        $libError = $e->getMessage();
        $this->logger->warning('Ntfy library send failed, falling back to HTTP', ['error' => $libError]);
        // fall through to HTTP fallback
      }
    }

    // Fallback: direct HTTP POST to ntfy topic endpoint via Guzzle
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

      $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
      if (!empty(Config::$ntfyToken)) {
        $headers['Authorization'] = 'Bearer ' . Config::$ntfyToken;
      } elseif (!empty(Config::$ntfyUser) && !empty(Config::$ntfyPassword)) {
        $headers['Authorization'] = 'Basic ' . base64_encode(Config::$ntfyUser . ':' . Config::$ntfyPassword);
      }

      $form = ['message' => (string)$message, 'title' => $fullTitle];
      if (!empty($options['tags'])) {
        $form['tags'] = implode(',', (array)$options['tags']);
      }
      if (isset($options['priority'])) {
        $form['priority'] = (int)$options['priority'];
      }
      if (!empty($options['delay'])) {
        $form['delay'] = (string)$options['delay'];
      }

      $resp = $http->post($url, ['headers' => $headers, 'form_params' => $form]);
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
