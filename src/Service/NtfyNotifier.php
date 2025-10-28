<?php

namespace App\Service;

use Joseph\Ntfy\NtfyClient;
use Joseph\Ntfy\Message;
use Psr\Log\LoggerInterface;

class NtfyNotifier
{
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ?NtfyClient     $client,
    private readonly bool            $enabled,
    private readonly string          $topic,
    private readonly ?string         $titlePrefix
  )
  {
  }

  public function isEnabled(): bool
  {
    return $this->enabled && $this->client !== null && $this->topic !== '';
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

    $fullTitle = ltrim(($this->titlePrefix ?? '') . ' ' . $title);

    $msg = new Message($this->topic, $message);
    $msg->title($fullTitle);

    if (!empty($options['tags'])) {
      $msg->tags($options['tags']);
    }
    if (isset($options['priority'])) {
      $msg->priority((int)$options['priority']);
    }
    if (!empty($options['click'])) {
      $msg->click($options['click']);
    }
    if (!empty($options['attach'])) {
      $msg->attach($options['attach']);
    }
    if (!empty($options['delay'])) {
      $msg->delay($options['delay']);
    }

    try {
      $this->client->publish($msg);
      $this->logger->info('Ntfy notification sent', ['topic' => $this->topic]);
    } catch (\Throwable $e) {
      $this->logger->error('Failed to send Ntfy notification', [
        'error' => $e->getMessage(),
      ]);
    }
  }
}
