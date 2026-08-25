<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;

/**
 * How many times per how many seconds — the package's one unit of allowance.
 *
 * Config stays arrays, because `config:cache` writes it with var_export(). This is what those
 * arrays become on the way in, so the "below 1 means no limit" rule lives here and nowhere else.
 */
final readonly class Budget
{
    private function __construct(public int $times, public int $seconds) {}

    /** Below 1 either way is "no limit", not "never": an empty env var reads as 0. */
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

    /**
     * Reads one `flood-control.classes` entry, falling back to the defaults for absent keys.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromConfig(array $entry, self $default): self
    {
        return new self(
            (int)($entry['limit'] ?? $default->times),
            (int)($entry['window'] ?? $default->seconds),
        );
    }

    /**
     * Arrays are the documented config form, but `Budget::perHour(1)` is the obvious thing to reach
     * for. var_export() writes an object happily and only the require() fails, so without this a
     * `config:cache` deploy goes green and then fatals on every request.
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

    /** @param string|null $key The bucket. Null leaves it to the caller's default — for the exception handler, the concrete class. */
    public function toLimit(?string $key = null): Limit
    {
        return $this->isUnlimited()
            ? Limit::none()
            : new Limit(key: $key ?? '', maxAttempts: $this->times, decaySeconds: $this->seconds);
    }
}
