<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Resolves the budget that applies to one throwable: the most specific `classes` entry it matches,
 * or the default.
 */
final readonly class ThrottleConfig
{
    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return (bool)$this->config->get('flood-control.enabled', true);
    }

    /** @return array{limit: int, window: int} */
    public function for(Throwable $e): array
    {
        $window = (int)$this->config->get('flood-control.window', 0);
        $classes = (array)$this->config->get('flood-control.classes', []);
        $match = self::mostSpecific($e, array_keys($classes));

        if ($match === null) {
            return [
                'limit'  => (int)$this->config->get('flood-control.limit', 0),
                'window' => $window,
            ];
        }

        return [
            'limit'  => (int)($classes[$match]['limit'] ?? 0),
            'window' => (int)($classes[$match]['window'] ?? $window),
        ];
    }

    /**
     * Not array order: `class_implements()` returns linkage order, not specificity, so a broad
     * interface can precede a narrow one. A subtype always beats its supertype; between two entries
     * with no subtype relation — two unrelated interfaces, or an interface and a class — the one
     * declared first in config wins, which is why a catch-all belongs last.
     *
     * @param  list<string>  $candidates
     */
    private static function mostSpecific(Throwable $e, array $candidates): ?string
    {
        $matching = array_values(array_filter($candidates, static fn (string $c): bool => $e instanceof $c));

        return array_find($matching, static fn (string $c): bool => ! array_any(
            $matching,
            static fn (string $other): bool => $other !== $c && is_a($other, $c, true),
        ));
    }
}
