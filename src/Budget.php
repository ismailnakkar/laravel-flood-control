<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;

/**
 * $times per $seconds. Below 1 on either side means no limit, not never: an empty env var reads as 0.
 * The only home of this rule; other code asks isUnlimited().
 */
final readonly class Budget
{
    private function __construct(public int $times, public int $seconds) {}

    public static function of(int $times, int $seconds): self
    {
        return new self($times, $seconds);
    }

    public static function perMinute(int $times = 1): self
    {
        return new self($times, 60);
    }

    public static function perHour(int $times = 1): self
    {
        return new self($times, 3600);
    }

    public static function perDay(int $times = 1): self
    {
        return new self($times, 86400);
    }

    public static function unlimited(): self
    {
        return new self(0, 0);
    }

    /** @param  array<string, mixed>  $entry  A `classes` entry; absent keys fall back to $default. */
    public static function fromConfig(array $entry, self $default): self
    {
        return new self(
            (int)($entry['limit'] ?? $default->times),
            (int)($entry['window'] ?? $default->seconds),
        );
    }

    /**
     * Lets a Budget in config survive `config:cache`, which var_export()s it.
     *
     * @param  array{times: int, seconds: int}  $state
     */
    public static function __set_state(array $state): self
    {
        return new self($state['times'], $state['seconds']);
    }

    public function isUnlimited(): bool
    {
        return $this->times < 1 || $this->seconds < 1;
    }

    /** @param  string|null  $key  The bucket; null leaves it to the handler, one per concrete class. */
    public function toLimit(?string $key = null): Limit
    {
        return $this->isUnlimited()
            ? Limit::none()
            : new Limit(key: $key ?? '', maxAttempts: $this->times, decaySeconds: $this->seconds);
    }
}
