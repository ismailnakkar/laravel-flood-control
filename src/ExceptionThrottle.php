<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Throwable;
use WeakMap;

/**
 * @internal
 *
 * The handler's throttle callback: it gates in shouldntReport(), ahead of the log write and every
 * reportable.
 */
final class ExceptionThrottle
{
    /** @var WeakMap<Throwable, Limit>|null */
    private static ?WeakMap $overrides = null;

    /** The handler matches throttle callbacks on this parameter's type, so keep it Throwable. */
    public static function for(Throwable $e): Limit
    {
        $config = app(ThrottleConfig::class);

        if (! $config->enabled()) {
            return Limit::none();
        }

        $override = self::takeOverride($e);

        if ($override instanceof Limit) {
            return $override;
        }

        return $config->for($e)->toLimit();
    }

    public static function override(Throwable $e, Limit $limit): void
    {
        if (Budget::of($limit->maxAttempts, $limit->decaySeconds)->isUnlimited()) {
            self::overrides()[$e] = Limit::none();

            return;
        }

        // Key-less, it would share the class bucket, whose window the first hit fixes. Cloned: by()
        // mutates, and the caller may reuse the Limit. A falsy key is none, as the handler reads it.
        self::overrides()[$e] = $limit->key
            ? $limit
            : (clone $limit)->by('flood-control:override:' . $e::class);
    }

    /** Drops an override report() never consumed. */
    public static function forget(Throwable $e): void
    {
        self::takeOverride($e);
    }

    /**
     * Consumed on read, so it caps one report. Walks getPrevious(): $exceptions->map() replaces the
     * throwable before shouldntReport() runs.
     */
    private static function takeOverride(Throwable $e): ?Limit
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (isset(self::overrides()[$current])) {
                $limit = self::overrides()[$current];
                unset(self::overrides()[$current]);

                return $limit;
            }
        }

        return null;
    }

    /** @return WeakMap<Throwable, Limit> */
    private static function overrides(): WeakMap
    {
        return self::$overrides ??= new WeakMap;
    }
}
