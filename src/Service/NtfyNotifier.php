<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;
use App\Service\MessageBuilderTrait;

/**
 * Ntfy notifier - sends notifications to a ntfy server via HTTP POST
 *
 * This class implements a lightweight sender using Guzzle and the ntfy HTTP headers
 */
class NtfyNotifier
{
  use MessageBuilderTrait;
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
   * @return array{status:string,attempts:int,error:string|null}
   */
  public function send(string $title, string $message, array $options = []): array
  {
    if (!$this->isEnabled()) {
      LoggerFactory::get()->info('Ntfy send skipped (disabled or misconfigured)', [
        'status' => 'skipped',
        'attempts' => 0,
      ]);
      return ['status' => 'skipped', 'attempts' => 0, 'error' => 'disabled or misconfigured'];
    }

    $topic = trim((string)$this->topic);
    $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);

    // Construct and validate base URL and topic before retry loop
    $base = rtrim((string)Config::$ntfyBaseUrl, '/');
    if ($base === '') {
      throw new RuntimeException('Empty ntfy base URL in Config');
    }
    $url = $base . '/' . rawurlencode($topic);
    
    // Create HTTP client once outside retry loop
    $http = $this->httpClient ?? new HttpClient([
      'timeout' => 15,
      'http_errors' => false,
    ]);

    // Retry logic similar to PushoverNotifier
    $attempts = 0;
    $ok = false;
    $error = null;
    
    while ($attempts < 3 && !$ok) {
      $attempts++;
      try {
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
          $ok = true;
          $error = null; // Clear error on success
        } else {
          $responseBody = (string)$resp->getBody();
          $error = 'HTTP ' . $status . ': ' . $responseBody;
        }
      } catch (GuzzleException $ge) {
        $error = 'Guzzle exception: ' . $ge->getMessage();
      } catch (Throwable $e) {
        $error = 'Exception: ' . $e->getMessage();
      }
      
      // Add a small delay before retrying, except after the last attempt
      if (!$ok && $attempts < 3) {
        usleep(500000); // 0.5 seconds
      }
    }

    $status = $ok ? 'success' : 'failure';
    LoggerFactory::get()->info('Ntfy send result', [
      'topic' => $topic,
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ]);

    return [
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ];
  }

  /**
   * Send a notification for a specific user using the user's ntfy credentials
   * when present. Falls back to global Config values.
   *
   * @param string $title
   * @param string $message
   * @param array $options
   * @param array|null $userRow
   * @return array{status:string,attempts:int,error:string|null}
   */
  public function sendForUser(string $title, string $message, array $options = [], ?array $userRow = null): array
  {
    $userIdx = $userRow['idx'] ?? null;
    
    if (!$this->isEnabled()) {
      LoggerFactory::get()->info('Ntfy send skipped (disabled or misconfigured)', [
        'user_idx' => $userIdx,
        'status' => 'skipped',
        'attempts' => 0,
      ]);
      return ['status' => 'skipped', 'attempts' => 0, 'error' => 'disabled or misconfigured'];
    }

    $topic = trim((string)$this->topic);
    $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);

    // Validate and construct URL outside the retry loop
    $base = rtrim((string)Config::$ntfyBaseUrl, '/');
    if ($base === '') {
      throw new \RuntimeException('Empty ntfy base URL in Config');
    }
    $url = $base . '/' . rawurlencode($topic);
    
    // Create HTTP client once outside retry loop
    $http = $this->httpClient ?? new HttpClient([
      'timeout' => 15,
      'http_errors' => false,
    ]);

    // Retry logic similar to PushoverNotifier
    $attempts = 0;
    $ok = false;
    $error = null;
    
    while ($attempts < 3 && !$ok) {
      $attempts++;
      try {
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
          $ok = true;
          $error = null; // Clear error on success
        } else {
          $responseBody = (string)$resp->getBody();
          $error = 'HTTP ' . $status . ': ' . $responseBody;
        }
      } catch (GuzzleException $ge) {
        $error = 'Guzzle exception: ' . $ge->getMessage();
      } catch (\Throwable $e) {
        $error = 'Exception: ' . $e->getMessage();
      }
      
      // Add a small delay before retrying, except after the last attempt
      if (!$ok && $attempts < 3) {
        usleep(500000); // 0.5 seconds
      }
    }

    $status = $ok ? 'success' : 'failure';
    LoggerFactory::get()->info('Ntfy send result (user)', [
      'topic' => $topic,
      'user_idx' => $userIdx,
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ]);

    return [
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ];
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
   * @return array{status:string,attempts:int,error:string|null}
   */
  public function sendForUserWithTopic(string $title, string $message, array $options = [], ?array $userRow = null, string $zoneTitlePrefix = ''): array
  {
    $userIdx = $userRow['idx'] ?? null;
    
    // Determine topic: use user's NtfyTopic if provided, otherwise fall back to configured topic
    $topic = '';
    if (!empty($userRow['NtfyTopic'])) {
      $topic = trim((string)$userRow['NtfyTopic']);
    } else {
      $topic = trim((string)$this->topic);
    }

    if ($topic === '') {
      LoggerFactory::get()->error('Ntfy send aborted: no topic available', [
        'user_idx' => $userIdx,
        'status' => 'error',
        'attempts' => 0,
        'error' => 'no topic available (neither user NtfyTopic nor config topic)',
      ]);
      return ['status' => 'error', 'attempts' => 0, 'error' => 'no topic available'];
    }

    if (!self::isValidTopicName($topic)) {
      $error = 'Invalid topic name: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)';
      LoggerFactory::get()->error('Ntfy send aborted: invalid topic name', [
        'topic' => $topic,
        'user_idx' => $userIdx,
        'status' => 'error',
        'attempts' => 0,
        'error' => $error,
      ]);
      return ['status' => 'error', 'attempts' => 0, 'error' => $error];
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

    // Validate and construct URL outside the retry loop
    $base = rtrim((string)Config::$ntfyBaseUrl, '/');
    if ($base === '') {
      throw new \RuntimeException('Empty ntfy base URL in Config');
    }
    $url = $base . '/' . rawurlencode($topic);
    
    // Create HTTP client once outside retry loop
    $http = $this->httpClient ?? new HttpClient([
      'timeout' => 15,
      'http_errors' => false,
    ]);

    // Retry logic similar to PushoverNotifier
    $attempts = 0;
    $ok = false;
    $error = null;
    
    while ($attempts < 3 && !$ok) {
      $attempts++;
      try {
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
          $ok = true;
          $error = null; // Clear error on success
        } else {
          $responseBody = (string)$resp->getBody();
          $error = 'HTTP ' . $status . ': ' . $responseBody;
        }
      } catch (GuzzleException $ge) {
        $error = 'Guzzle exception: ' . $ge->getMessage();
      } catch (\Throwable $e) {
        $error = 'Exception: ' . $e->getMessage();
      }
      
      // Add a small delay before retrying, except after the last attempt
      if (!$ok && $attempts < 3) {
        usleep(500000); // 0.5 seconds
      }
    }

    $status = $ok ? 'success' : 'failure';
    LoggerFactory::get()->info('Ntfy send result (user with topic)', [
      'topic' => $topic,
      'user_idx' => $userIdx,
      'zone_prefix' => $zoneTitlePrefix,
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ]);

    return [
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ];
  }

  /**
   * Send a detailed Ntfy notification for a specific user record.
   * Uses user's NtfyTopic, NtfyToken and NtfyUser/NtfyPassword when present.
   * Builds title and message from alert properties using MessageBuilderTrait.
   *
   * @param array $alertRow Row from alerts table (expected keys: json, id, event, headline, etc.)
   * @param array $userRow User row with optional NtfyTopic, NtfyToken, NtfyUser, NtfyPassword
   * @param string|null $customUrl Optional custom URL to use instead of alert id
   * @param array{data:string,content_type:string}|null $imageData Optional image attachment
   * @return array{status:string,attempts:int,error:string|null}
   */
  public function notifyDetailedForUser(array $alertRow, array $userRow, ?string $customUrl = null, ?array $imageData = null): array
  {
    $userIdx = $userRow['idx'] ?? null;
    
    // Determine topic: use user's NtfyTopic if provided, otherwise fall back to configured topic
    $topic = '';
    if (!empty($userRow['NtfyTopic'])) {
      $topic = trim((string)$userRow['NtfyTopic']);
    } else {
      $topic = trim((string)$this->topic);
    }

    if ($topic === '') {
      LoggerFactory::get()->info('Ntfy send skipped for user (no topic available)', [
        'user_idx' => $userIdx,
        'status' => 'skipped',
        'attempts' => 0,
      ]);
      return ['status' => 'skipped', 'attempts' => 0, 'error' => 'no topic available'];
    }

    if (!self::isValidTopicName($topic)) {
      $error = 'Invalid topic name: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)';
      LoggerFactory::get()->error('Ntfy send aborted: invalid topic name', [
        'topic' => $topic,
        'user_idx' => $userIdx,
        'status' => 'error',
        'attempts' => 0,
        'error' => $error,
      ]);
      return ['status' => 'error', 'attempts' => 0, 'error' => $error];
    }

    // Build title and message using trait methods (same as Pushover)
    $props = json_decode($alertRow['json'] ?? '{}', true)['properties'] ?? [];
    $title = $this->buildTitleFromProps($props, $alertRow);
    $message = $this->buildMessageFromProps($props, $alertRow);

    // Build full title with configured title prefix
    $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);

    // Validate and construct URL outside the retry loop
    $base = rtrim((string)Config::$ntfyBaseUrl, '/');
    if ($base === '') {
      throw new \RuntimeException('Empty ntfy base URL in Config');
    }
    $url = $base . '/' . rawurlencode($topic);
    
    // Create HTTP client once outside retry loop
    $http = $this->httpClient ?? new HttpClient([
      'timeout' => 15,
      'http_errors' => false,
    ]);

    // Use custom URL if provided, otherwise fall back to alert id
    $idUrl = $customUrl ?? ($alertRow['id'] ?? null);
    $click = null;
    if (is_string($idUrl) && preg_match('#^https?://#i', $idUrl)) {
      $click = $idUrl;
    }

    // Retry logic similar to PushoverNotifier
    $attempts = 0;
    $ok = false;
    $error = null;
    
    while ($attempts < 3 && !$ok) {
      $attempts++;
      try {
        $headers = [];
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

        // Set title and priority
        $headers['X-Title'] = substr($fullTitle, 0, 200);
        $headers['X-Priority'] = '3';
        $headers['X-Tags'] = 'warning';
        if ($click) {
          $headers['X-Click'] = $click;
        }

        // If we have an image, attach it using ntfy's attachment feature
        if ($imageData !== null && !empty($imageData['data'])) {
          $headers['X-Filename'] = 'forecast.' . $this->getImageExtension($imageData['content_type']);
          $headers['Content-Type'] = $imageData['content_type'];
          // For ntfy with attachment, the message goes in header
          $headers['X-Message'] = substr($message, 0, 4096);
          $body = $imageData['data'];
        } else {
          $headers['Content-Type'] = 'text/plain; charset=utf-8';
          // Enforce ntfy length limits: message<=4096
          $body = substr($message, 0, 4096);
        }

        $resp = $http->post($url, ['headers' => $headers, 'body' => $body]);
        $status = $resp->getStatusCode();
        if ($status >= 200 && $status < 300) {
          $ok = true;
          $error = null; // Clear error on success
        } else {
          $responseBody = (string)$resp->getBody();
          $error = 'HTTP ' . $status . ': ' . $responseBody;
        }
      } catch (GuzzleException $ge) {
        $error = 'Guzzle exception: ' . $ge->getMessage();
      } catch (\Throwable $e) {
        $error = 'Exception: ' . $e->getMessage();
      }
      
      // Add a small delay before retrying, except after the last attempt
      if (!$ok && $attempts < 3) {
        usleep(500000); // 0.5 seconds
      }
    }

    $status = $ok ? 'success' : 'failure';
    LoggerFactory::get()->info('Ntfy send result (detailed user)', [
      'topic' => $topic,
      'user_idx' => $userIdx,
      'alert_id' => $alertRow['id'] ?? null,
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
      'has_image' => $imageData !== null,
    ]);

    return [
      'status' => $status,
      'attempts' => $attempts,
      'error' => $error,
    ];
  }

  /**
   * Get file extension from content type.
   *
   * @param string $contentType MIME content type
   * @return string File extension
   */
  private function getImageExtension(string $contentType): string
  {
    return match ($contentType) {
      'image/png' => 'png',
      'image/jpeg', 'image/jpg' => 'jpg',
      'image/gif' => 'gif',
      default => 'png',
    };
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
   * @return array{success:bool,error:string|null,request_id:string|null} Note: request_id is always null for ntfy
   */
  public function sendAlert(string $topic, string $title, string $message, ?string $url = null, ?string $user = null, ?string $password = null, ?string $token = null): array
  {
    $topic = trim($topic);
    if ($topic === '') {
      LoggerFactory::get()->error('Ntfy sendAlert failed: empty topic', [
        'status' => 'error',
        'attempts' => 0,
        'error' => 'Empty topic',
      ]);
      return ['success' => false, 'error' => 'Empty topic', 'request_id' => null];
    }

    if (!self::isValidTopicName($topic)) {
      $error = 'Invalid topic name: Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)';
      LoggerFactory::get()->error('Ntfy sendAlert failed: invalid topic', [
        'topic' => $topic,
        'status' => 'error',
        'attempts' => 0,
        'error' => $error,
      ]);
      return ['success' => false, 'error' => $error, 'request_id' => null];
    }

    // Construct and validate base URL and ntfy URL outside the retry loop
    $base = rtrim((string)Config::$ntfyBaseUrl, '/');
    if ($base === '') {
      throw new RuntimeException('Empty ntfy base URL in Config');
    }
    $ntfyUrl = $base . '/' . rawurlencode($topic);
    
    // Create HTTP client once outside retry loop
    $http = $this->httpClient ?? new HttpClient([
      'timeout' => 15,
      'http_errors' => false,
    ]);

    // Retry logic similar to PushoverNotifier
    $attempts = 0;
    $ok = false;
    $error = null;
    
    while ($attempts < 3 && !$ok) {
      $attempts++;
      try {
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
          $ok = true;
          $error = null; // Clear error on success
        } else {
          $responseBody = (string)$resp->getBody();
          $error = 'HTTP ' . $status . ': ' . $responseBody;
        }
      } catch (GuzzleException $ge) {
        $error = 'Guzzle exception: ' . $ge->getMessage();
      } catch (Throwable $e) {
        $error = 'Exception: ' . $e->getMessage();
      }
      
      // Add a small delay before retrying, except after the last attempt
      if (!$ok && $attempts < 3) {
        usleep(500000); // 0.5 seconds
      }
    }

    LoggerFactory::get()->info('Ntfy sendAlert result', [
      'topic' => $topic,
      'success' => $ok,
      'attempts' => $attempts,
      'error' => $error,
    ]);

    return ['success' => $ok, 'error' => $error, 'request_id' => null];
  }
}
