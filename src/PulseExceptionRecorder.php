<?php

declare(strict_types=1);

namespace FloodControl;

use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use Throwable;

/**
 * @internal
 *
 * Pulse's exceptions recorder, counting in front of the gate instead of behind it.
 *
 * Pulse counts from a `reportable`, which runs only for what got past shouldntReport() — so
 * throttling would flatten the exceptions card by exactly the amount it suppressed.
 * FloodControlServiceProvider binds this in its place: recording is delegated to the stock recorder,
 * so the card, `ignore`, `sample_rate` and `location` are unchanged. It has no register(), so Pulse
 * wires none of the stock hooks; the provider wires the ones below, and `flood-control.pulse` picks
 * per exception which of count() and countSurvivor() records.
 */
final class PulseExceptionRecorder
{
    public function __construct(private PulseExceptions $recorder) {}

    /** Registered on dontReportWhen(). Returns null, which is not `=== true`, so nothing is suppressed. */
    public static function count(Throwable $e): null
    {
        if (self::countsInFront()) {
            self::countAlways($e);
        }

        return null;
    }

    /** A reportable, behind the gate where Pulse counts, for while the switch is off. */
    public static function countSurvivor(Throwable $e): void
    {
        if (! self::countsInFront()) {
            self::countAlways($e);
        }
    }

    /** Where the gate decides nothing: a handler with no throttleUsing(), and Pulse::report(). */
    public static function countAlways(Throwable $e): void
    {
        // dontReportCallbacks are not inside the handler's rescue(), so an escaping throw would take
        // down report() itself.
        try {
            self::registered()?->record($e);
        } catch (Throwable) {
            //
        }
    }

    public static function countsInFront(): bool
    {
        return (bool)config('flood-control.pulse', true);
    }

    public function record(Throwable $e): void
    {
        $this->recorder->record($e);
    }

    /**
     * Pulse's own list, so its off switches decide with no help from this package: a recorder Pulse
     * did not register — `pulse.enabled` off, or the recorder's own `enabled` off — is not in it.
     */
    private static function registered(): ?self
    {
        return app(Pulse::class)->recorders()->whereInstanceOf(self::class)->first();
    }
}
