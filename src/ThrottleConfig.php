<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Resolves the budget for one throwable: the most specific `classes` entry it matches, or the default.
 */
final readonly class ThrottleConfig
{
    /** Mirrors config/flood-control.php, so a stale config cache without the key still throttles. */
    public const DEFAULT_LIMIT = 10;

    public const DEFAULT_WINDOW = 300;

    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return (bool)$this->config->get('flood-control.enabled', true);
    }

    public function for(Throwable $e): Budget
    {
        $default = Budget::of(
            (int)$this->config->get('flood-control.limit', self::DEFAULT_LIMIT),
            (int)$this->config->get('flood-control.window', self::DEFAULT_WINDOW),
        );

        $classes = (array)$this->config->get('flood-control.classes', []);
        $match = self::mostSpecific($e, array_keys($classes));

        if ($match === null) {
            return $default;
        }

        $entry = $classes[$match];

        return $entry instanceof Budget ? $entry : Budget::fromConfig((array)$entry, $default);
    }

    /**
     * Config order is not specificity order: return the match no other match is a subtype of.
     * Unrelated entries tie-break on config order — catch-all last.
     *
     * @param  list<array-key>  $candidates
     */
    private static function mostSpecific(Throwable $e, array $candidates): ?string
    {
        $matching = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $e instanceof $candidate) {
                $matching[] = $candidate;
            }
        }

        foreach ($matching as $candidate) {
            foreach ($matching as $other) {
                if ($other !== $candidate && is_a($other, $candidate, true)) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return null;
    }
}
