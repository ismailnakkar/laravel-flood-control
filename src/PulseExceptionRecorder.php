<?php

declare(strict_types=1);

namespace FloodControl;

use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use Throwable;

/**
 * @internal
 *
 * Bound in place of Pulse's exceptions recorder so the card counts in front of the gate; Pulse's own
 * counts from a reportable, behind it. Recording is delegated, so Pulse's settings still apply. It
 * has no register(), so Pulse wires none of its hooks: the provider wires the ones below.
 */
final class PulseExceptionRecorder
{
    public function __construct(private PulseExceptions $recorder) {}

    public static function count(Throwable $e): null
    {
        if (self::countsInFront()) {
            self::countAlways($e);
        }

        return null;
    }

    /** A reportable: counts behind the gate, as Pulse does, while `flood-control.pulse` is off. */
    public static function countSurvivor(Throwable $e): void
    {
        if (! self::countsInFront()) {
            self::countAlways($e);
        }
    }

    /** Where the gate decides nothing: a handler with no throttleUsing(), and Pulse::report(). */
    public static function countAlways(Throwable $e): void
    {
        try {
            self::registered()?->record($e);
        } catch (Throwable) {
            // Runs outside the handler's rescue(): a throw would break report().
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

    /** Pulse's registered list, so Pulse's own off switches still apply. */
    private static function registered(): ?self
    {
        return app(Pulse::class)->recorders()->whereInstanceOf(self::class)->first();
    }
}
