<?php

namespace App\Service;

use App\Config;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

/**
 * Ntfy notifier - sends notifications to a ntfy server via HTTP POST
 *
 * This class implements a lightweight sender using Guzzle and the ntfy HTTP headers
 */
class NtfyNotifier
{
  public function __construct(
    private readonly ?LoggerInterface $logger = null,
    private readonly ?bool            $enabled = null,
    private readonly ?string          $topic = null,
    private readonly ?string         $titlePrefix = null,
    private readonly mixed           $httpClient = null
  )
  {
  }

  /**
   * Validates ntfy topic name character set.
   * Topic names can use letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-).
   * 
   * @param string $topic Topic name to validate
   * @return bool True if topic is valid, false otherwise
   */
  public static function isValidTopicName(string $topic): bool
  {
    $topic = trim($topic);
    if ($topic === '') {
      return false;
    }
    
    // Check if topic contains only allowed characters: letters, numbers, underscores, hyphens
    return preg_match('/^[A-Za-z0-9_-]+$/', $topic) === 1;
  }

  /**
   * Whether ntfy notifications are enabled and the topic is non-empty and valid.
   */
  public function isEnabled(): bool
  {
    $topic = trim($this->topic ?? '');
    return ($this->enabled ?? false) && $topic !== '' && self::isValidTopicName($topic);
  }

  /**
   * Send a notification to the configured ntfy topic.
   *
   * @param string $title Short title (max 200 chars)
   * @param string $message Message body (max 4096 chars)
   * @param array{tags?:string[],priority?:int,click?:string,attach?:string,delay?:string} $options Additional options mapped to ntfy headers
   */
  public function send(string $title, string $message, array $options = []): void
  {
    if (!$this->isEnabled()) {
      if ($this->logger) {
        $this->logger->info('Ntfy sending skipped (disabled or misconfigured)');
      }
      return;
    }

    $topic = trim((string)$this->topic);
    if ($topic === '') {
      if ($this->logger) {
        $this->logger->error('Ntfy sending aborted: empty topic');
      }
      return;
    }

    if (!self::isValidTopicName($topic)) {
      if ($this->logger) {
        $this->logger->error('Ntfy sending aborted: invalid topic name', [
          'topic' => $topic,
          'reason' => 'Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)'
        ]);
      }
      return;
    }

    if ($this->logger) {
      $this->logger->info('Ntfy sending', [
        'topic' => $topic,
        'title' => $title,
      ]);
    }

    $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);

    // Direct HTTP POST to ntfy topic endpoint via Guzzle
    try {
      $base = rtrim((string)Config::$ntfyBaseUrl, '/');
      if ($base === '') {
        throw new RuntimeException('Empty ntfy base URL in Config');
      }
      $url = $base . '/' . rawurlencode($topic);
      $http = $this->httpClient ?? new HttpClient([
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
        if ($this->logger) {
          $this->logger->info('Ntfy notification sent (http)', ['topic' => $this->topic, 'status' => $status]);
        }
        return;
      }
      $body = (string)$resp->getBody();
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed', ['status' => $status, 'body' => $body, 'url' => $url]);
      }
    } catch (GuzzleException $ge) {
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (exception)', ['error' => $ge->getMessage()]);
      }
    } catch (Throwable $e) {
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (throwable)', ['error' => $e->getMessage()]);
      }
    }
  }

  /**
   * Send a notification for a specific user using the user's ntfy credentials
   * when present. Falls back to global Config values.
   *
   * @param string $title
   * @param string $message
   * @param array $options
   * @param array|null $userRow
   */
  public function sendForUser(string $title, string $message, array $options = [], ?array $userRow = null): void
  {
    if (!$this->isEnabled()) {
      if ($this->logger) {
        $this->logger->info('Ntfy sending skipped (disabled or misconfigured)');
      }
      return;
    }

    $topic = trim((string)$this->topic);
    if ($topic === '') {
      if ($this->logger) {
        $this->logger->error('Ntfy sending aborted: empty topic');
      }
      return;
    }

    if ($this->logger) {
      $this->logger->info('Ntfy sending (per-user)', ['topic' => $topic, 'title' => $title]);
    }

    try {
      $base = rtrim((string)Config::$ntfyBaseUrl, '/');
      if ($base === '') {
        throw new \RuntimeException('Empty ntfy base URL in Config');
      }
      $url = $base . '/' . rawurlencode($topic);
      $http = $this->httpClient ?? new HttpClient([
        'timeout' => 15,
        'http_errors' => false,
      ]);

      $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
      // prefer per-user token/user:password if provided
      if (!empty($userRow['NtfyToken'])) {
        $headers['Authorization'] = 'Bearer ' . trim((string)$userRow['NtfyToken']);
      } elseif (!empty($userRow['NtfyUser']) && !empty($userRow['NtfyPassword'])) {
        $headers['Authorization'] = 'Basic ' . base64_encode(trim((string)$userRow['NtfyUser']) . ':' . trim((string)$userRow['NtfyPassword']));
      } elseif (!empty(Config::$ntfyToken)) {
        $headers['Authorization'] = 'Bearer ' . Config::$ntfyToken;
      } elseif (!empty(Config::$ntfyUser) && !empty(Config::$ntfyPassword)) {
        $headers['Authorization'] = 'Basic ' . base64_encode(Config::$ntfyUser . ':' . Config::$ntfyPassword);
      }

      $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);
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

      $body = substr((string)$message, 0, 4096);
      $resp = $http->post($url, ['headers' => $headers, 'body' => $body]);
      $status = $resp->getStatusCode();
      if ($status >= 200 && $status < 300) {
        if ($this->logger) {
          $this->logger->info('Ntfy notification sent (user)', ['topic' => $topic, 'status' => $status, 'user_idx' => $userRow['idx'] ?? null]);
        }
        return;
      }
      $body = (string)$resp->getBody();
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (user)', ['status' => $status, 'body' => $body, 'url' => $url]);
      }
    } catch (GuzzleException $ge) {
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (exception)', ['error' => $ge->getMessage(), 'user_idx' => $userRow['idx'] ?? null]);
      }
    } catch (\Throwable $e) {
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (throwable)', ['error' => $e->getMessage(), 'user_idx' => $userRow['idx'] ?? null]);
      }
    }
  }

  /**
   * Send a notification for a specific user using the user's ntfy credentials and topic
   * when present. Falls back to global Config values if user values are not provided.
   *
   * @param string $title
   * @param string $message
   * @param array $options
   * @param array|null $userRow
   * @param string $zoneTitlePrefix Zone name(s) to prefix the title with
   */
  public function sendForUserWithTopic(string $title, string $message, array $options = [], ?array $userRow = null, string $zoneTitlePrefix = ''): void
  {
    // Determine topic: use user's NtfyTopic if provided, otherwise fall back to configured topic
    $topic = '';
    if (!empty($userRow['NtfyTopic'])) {
      $topic = trim((string)$userRow['NtfyTopic']);
    } else {
      $topic = trim((string)$this->topic);
    }

    if ($topic === '') {
      if ($this->logger) {
        $this->logger->error('Ntfy sending aborted: no topic available (neither user NtfyTopic nor config topic)');
      }
      return;
    }

    if (!self::isValidTopicName($topic)) {
      if ($this->logger) {
        $this->logger->error('Ntfy sending aborted: invalid topic name', [
          'topic' => $topic,
          'user_idx' => $userRow['idx'] ?? null,
          'reason' => 'Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)'
        ]);
      }
      return;
    }

    if ($this->logger) {
      $this->logger->info('Ntfy sending (per-user with topic)', ['topic' => $topic, 'title' => $title, 'zone_prefix' => $zoneTitlePrefix]);
    }

    try {
      $base = rtrim((string)Config::$ntfyBaseUrl, '/');
      if ($base === '') {
        throw new \RuntimeException('Empty ntfy base URL in Config');
      }
      $url = $base . '/' . rawurlencode($topic);
      $http = $this->httpClient ?? new HttpClient([
        'timeout' => 15,
        'http_errors' => false,
      ]);

      $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
      // prefer per-user token/user:password if provided
      if (!empty($userRow['NtfyToken'])) {
        $headers['Authorization'] = 'Bearer ' . trim((string)$userRow['NtfyToken']);
      } elseif (!empty($userRow['NtfyUser']) && !empty($userRow['NtfyPassword'])) {
        $headers['Authorization'] = 'Basic ' . base64_encode(trim((string)$userRow['NtfyUser']) . ':' . trim((string)$userRow['NtfyPassword']));
      } elseif (!empty(Config::$ntfyToken)) {
        $headers['Authorization'] = 'Bearer ' . Config::$ntfyToken;
      } elseif (!empty(Config::$ntfyUser) && !empty(Config::$ntfyPassword)) {
        $headers['Authorization'] = 'Basic ' . base64_encode(Config::$ntfyUser . ':' . Config::$ntfyPassword);
      }

      // Build full title with zone prefix and configured title prefix
      $titleParts = [];
      if (!empty($zoneTitlePrefix)) {
        $titleParts[] = $zoneTitlePrefix;
      }
      if (!empty($this->titlePrefix)) {
        $titleParts[] = $this->titlePrefix;
      }
      $titleParts[] = $title;
      $fullTitle = implode(' - ', $titleParts);
      
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

      $body = substr((string)$message, 0, 4096);
      $resp = $http->post($url, ['headers' => $headers, 'body' => $body]);
      $status = $resp->getStatusCode();
      if ($status >= 200 && $status < 300) {
        if ($this->logger) {
          $this->logger->info('Ntfy notification sent (user with topic)', ['topic' => $topic, 'status' => $status, 'user_idx' => $userRow['idx'] ?? null]);
        }
        return;
      }
      $body = (string)$resp->getBody();
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (user with topic)', ['status' => $status, 'body' => $body, 'url' => $url]);
      }
    } catch (GuzzleException $ge) {
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (exception)', ['error' => $ge->getMessage(), 'user_idx' => $userRow['idx'] ?? null]);
      }
    } catch (\Throwable $e) {
      if ($this->logger) {
        $this->logger->error('Ntfy HTTP send failed (throwable)', ['error' => $e->getMessage(), 'user_idx' => $userRow['idx'] ?? null]);
      }
    }
  }

  /**
   * Send an alert notification to a specific topic with custom credentials.
   * This is a simplified interface for testing and ad-hoc notifications.
   *
   * @param string $topic Ntfy topic to send to
   * @param string $title Alert title/event name
   * @param string $message Alert message body
   * @param string|null $url Optional URL to link to
   * @param string|null $user Optional ntfy username for authentication
   * @param string|null $password Optional ntfy password for authentication
   * @param string|null $token Optional ntfy token for authentication
   * @return array{success:bool,error:string|null}
   */
  public function sendAlert(string $topic, string $title, string $message, ?string $url = null, ?string $user = null, ?string $password = null, ?string $token = null): array
  {
    $topic = trim($topic);
    if ($topic === '') {
      return ['success' => false, 'error' => 'Empty topic'];
    }

    if (!self::isValidTopicName($topic)) {
      return ['success' => false, 'error' => 'Invalid topic name: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)'];
    }

    try {
      $base = rtrim((string)Config::$ntfyBaseUrl, '/');
      if ($base === '') {
        throw new RuntimeException('Empty ntfy base URL in Config');
      }
      $ntfyUrl = $base . '/' . rawurlencode($topic);
      $http = $this->httpClient ?? new HttpClient([
        'timeout' => 15,
        'http_errors' => false,
      ]);

      $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
      
      // Use provided credentials, or fall back to config
      if ($token) {
        $headers['Authorization'] = 'Bearer ' . trim($token);
      } elseif ($user && $password) {
        $headers['Authorization'] = 'Basic ' . base64_encode(trim($user) . ':' . trim($password));
      } elseif (!empty(Config::$ntfyToken)) {
        $headers['Authorization'] = 'Bearer ' . Config::$ntfyToken;
      } elseif (!empty(Config::$ntfyUser) && !empty(Config::$ntfyPassword)) {
        $headers['Authorization'] = 'Basic ' . base64_encode(Config::$ntfyUser . ':' . Config::$ntfyPassword);
      }

      $headers['X-Title'] = substr($title, 0, 200);
      if ($url && preg_match('#^https?://#i', $url)) {
        $headers['X-Click'] = $url;
      }

      $body = substr($message, 0, 4096);
      $resp = $http->post($ntfyUrl, ['headers' => $headers, 'body' => $body]);
      $status = $resp->getStatusCode();
      
      if ($status >= 200 && $status < 300) {
        if ($this->logger) {
          $this->logger->info('Ntfy sendAlert success', ['topic' => $topic, 'status' => $status]);
        }
        return ['success' => true, 'error' => null];
      }
      
      $responseBody = (string)$resp->getBody();
      $error = 'HTTP ' . $status . ': ' . $responseBody;
      if ($this->logger) {
        $this->logger->error('Ntfy sendAlert failed', ['status' => $status, 'body' => $responseBody]);
      }
      return ['success' => false, 'error' => $error];
      
    } catch (GuzzleException $ge) {
      $error = 'Guzzle exception: ' . $ge->getMessage();
      if ($this->logger) {
        $this->logger->error('Ntfy sendAlert exception', ['error' => $error]);
      }
      return ['success' => false, 'error' => $error];
    } catch (Throwable $e) {
      $error = 'Exception: ' . $e->getMessage();
      if ($this->logger) {
        $this->logger->error('Ntfy sendAlert throwable', ['error' => $error]);
      }
      return ['success' => false, 'error' => $error];
    }
  }
}
