<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Pulse\Events\ExceptionReported;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use Throwable;

/**
 * Pulse's exceptions recorder, counting in front of the gate instead of behind it.
 *
 * Pulse counts from a `reportable`, which runs only for what got past shouldntReport() — so
 * throttling would flatten the exceptions card by exactly the amount it suppressed.
 * FloodControlServiceProvider binds this in its place: recording is delegated to the stock recorder,
 * so the card, `ignore`, `sample_rate` and `location` are unchanged, but the reportable is dropped
 * and count() runs as a throttle callback instead.
 */
final class PulseExceptionRecorder
{
    public function __construct(private PulseExceptions $recorder) {}

    /** Registered on dontReportWhen(). Returns null, which is not `=== true`, so nothing is suppressed. */
    public static function count(Throwable $e): null
    {
        // dontReportCallbacks are not inside the handler's rescue(), so an escaping throw would take
        // down report() itself.
        try {
            self::registered()?->record($e);
        } catch (Throwable) {
            //
        }

        return null;
    }

    /**
     * Pulse's own recorder registers a `reportable` here too. count() replaces it; the
     * Pulse::report() listener is kept as it is.
     */
    public function register(callable $record, Application $app): void
    {
        $listen = fn (Dispatcher $events) => $events->listen(
            fn (ExceptionReported $event) => $record($event->exception),
        );

        $app->afterResolving(Dispatcher::class, $listen);

        if ($app->resolved(Dispatcher::class)) {
            $listen($app->make(Dispatcher::class));
        }
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
        return app(Pulse::class)->recorders()->first(
            static fn (object $recorder): bool => $recorder instanceof self,
        );
    }
}
