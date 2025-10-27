<?php
namespace App\Http;

final class RateLimiter
{
    private int $maxPerMinute;
    private array $timestamps = [];

    public function __construct(int $maxPerMinute)
    {
        $this->maxPerMinute = max(1, $maxPerMinute);
    }

    public function await(): void
    {
        $now = microtime(true);
        // Drop entries older than 60 seconds
        $windowStart = $now - 60.0;
        $this->timestamps = array_values(array_filter($this->timestamps, fn($t) => $t >= $windowStart));
        if (count($this->timestamps) >= $this->maxPerMinute) {
            $earliest = $this->timestamps[0];
            $sleep = max(0.0, 60.0 - ($now - $earliest));
            usleep((int)round($sleep * 1_000_000));
        }
        $this->timestamps[] = microtime(true);
    }
}
