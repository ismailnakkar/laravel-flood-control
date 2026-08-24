<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;
use Throwable;

/**
 * The unthrottled rate signal behind the gate.
 *
 * Registered as a throttle callback and returns null, so the handler falls through to
 * ExceptionThrottle to decide. That position is the point: throttle callbacks run inside
 * `shouldntReport()`, ahead of every `reportable` callback, so this sees every exception while the
 * gate behind it only narrates some.
 *
 * Pulse's own `Exceptions` recorder registers as a `reportable`, so its counts would agree with the
 * throttle rather than with reality — which is why FloodControlServiceProvider turns it off and this
 * emits the same `exception` type and `[class, location]` key the stock Pulse card reads.
 */
final class PulseExceptionCounter
{
    public static function record(Throwable $e): null
    {
        $key = json_encode([$e::class, self::location($e)], flags: JSON_THROW_ON_ERROR);

        Pulse::record('exception', $key, value: now()->timestamp)->max()->count();

        return null;
    }

    /** The first frame that is ours — a vendor entry point says nothing about which code broke. */
    private static function location(Throwable $e): string
    {
        if (! self::isVendor($e->getFile())) {
            return self::format($e->getFile(), $e->getLine());
        }

        $frame = array_find(
            $e->getTrace(),
            static fn (array $frame): bool => isset($frame['file']) && ! self::isVendor($frame['file']),
        );

        return $frame === null
            ? self::format($e->getFile(), $e->getLine())
            : self::format($frame['file'], $frame['line'] ?? null);
    }

    private static function isVendor(string $file): bool
    {
        return str_starts_with($file, base_path('vendor'))
            || $file === base_path('artisan')
            || $file === public_path('index.php');
    }

    private static function format(string $file, ?int $line): string
    {
        return Str::replaceFirst(base_path(DIRECTORY_SEPARATOR), '', $file) . (is_int($line) ? ":{$line}" : '');
    }
}
