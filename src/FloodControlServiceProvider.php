<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Pulse\Events\ExceptionReported;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use ReflectionObject;
use Throwable;
use WeakMap;

/** @internal */
class FloodControlServiceProvider extends ServiceProvider
{
    private bool $wrapsPulse = false;

    private bool $countersRunning = false;

    /** @var WeakMap<object, true> */
    private WeakMap $wired;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flood-control.php', 'flood-control');

        $this->wired = new WeakMap;

        $this->app->singleton(ThrottleConfig::class);

        // The binding must land before Pulse boots and resolves its recorders; registered later,
        // Pulse keeps its stock one. No config check: `flood-control.pulse` is read per exception.
        $this->wrapsPulse = class_exists(PulseExceptions::class) && ! $this->app->isBooted();

        if ($this->wrapsPulse) {
            $this->app->bind(PulseExceptions::class, static function (Container $app): PulseExceptionRecorder {
                // build(), not make(): make() would resolve this binding again.
                return new PulseExceptionRecorder($app->build(PulseExceptions::class));
            });
        }
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/flood-control.php' => config_path('flood-control.php'),
            ], 'flood-control-config');

            $this->registerAboutCommandIntegration();
        }

        $this->validateClassBudgets();
        $this->validateCounters();

        if ($this->wrapsPulse) {
            $events->listen(ExceptionReported::class, PulseReportListener::class);
        }

        // An app's withExceptions() callbacks are added during bootstrap, so they run ahead of these.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $resolved): void {
            $handler = self::unwrap($resolved);
            $target = $handler ?? $resolved;

            // A decorated binding unwraps to the same handler; wiring it twice counts everything twice.
            if (isset($this->wired[$target])) {
                return;
            }

            $this->wired[$target] = true;

            if ($handler === null) {
                $this->countPulseFromAReportable($resolved);

                return;
            }

            // Counted on dontReportWhen(), ahead of the throttle chain: Handler::throttle() stops at
            // the first non-null return, so an app's own throttle callback would starve counters
            // there. These return null, which suppresses nothing.
            if ($this->wrapsPulse) {
                $handler->dontReportWhen(PulseExceptionRecorder::count(...));
                $handler->reportable(PulseExceptionRecorder::countSurvivor(...));
            }

            // Always: the list is read per exception, so counters configured later still run.
            $handler->dontReportWhen($this->runCounters(...));

            $handler->throttleUsing(ExceptionThrottle::for(...));
        });
    }

    private function runCounters(Throwable $e): null
    {
        // A counter that reports re-enters here, and the gate runs too late to stop it.
        if ($this->countersRunning) {
            return null;
        }

        $this->countersRunning = true;

        try {
            foreach ($this->counters() as $counter) {
                try {
                    $this->app->make($counter)($e);
                } catch (Throwable) {
                    // dontReportWhen() callbacks run outside the handler's rescue(): a throw breaks report().
                }
            }
        } finally {
            $this->countersRunning = false;
        }

        return null;
    }

    /** @return list<mixed> */
    private function counters(): array
    {
        return array_values((array)$this->app->make('config')->get('flood-control.counters', []));
    }

    /**
     * throttleUsing() is not on the contract, so a decorator hides it (Collision wraps the binding on
     * every console run). Walk to the handler that has it.
     */
    private static function unwrap(?object $handler): ?object
    {
        for ($depth = 0; $depth < 8; $depth++) {
            if ($handler === null || method_exists($handler, 'throttleUsing')) {
                return $handler;
            }

            $handler = self::inner($handler);
        }

        return null;
    }

    private static function inner(object $handler): ?object
    {
        foreach ((new ReflectionObject($handler))->getProperties() as $property) {
            try {
                if ($property->isStatic() || ! $property->isInitialized($handler)) {
                    continue;
                }

                $value = $property->getValue($handler);
            } catch (Throwable) {
                // Reading a hooked or lazily-initialised property runs app code.
                continue;
            }

            if ($value instanceof ExceptionHandler && $value !== $handler) {
                return $value;
            }
        }

        return null;
    }

    /** Nothing is gated, so count where Pulse would: in a reportable. */
    private function countPulseFromAReportable(object $handler): void
    {
        if ($this->wrapsPulse && method_exists($handler, 'reportable')) {
            $handler->reportable(PulseExceptionRecorder::countAlways(...));
        }
    }

    /**
     * A typo'd class or key would otherwise fall back to the default budget silently. Skipped once
     * cached: config:cache boots the app uncached, so it already ran there.
     */
    private function validateClassBudgets(): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        foreach ((array)$this->app->make('config')->get('flood-control.classes', []) as $class => $budget) {
            if (! is_string($class) || (! class_exists($class) && ! interface_exists($class))) {
                throw new InvalidArgumentException(
                    "flood-control.classes: [{$class}] is not a class or interface that exists.",
                );
            }

            if ($budget instanceof Budget) {
                continue;
            }

            if (! is_array($budget) || $budget === []) {
                throw new InvalidArgumentException(
                    "flood-control.classes.[{$class}]: expected ['limit' => ?int, 'window' => ?int].",
                );
            }

            foreach (['limit', 'window'] as $key) {
                if (array_key_exists($key, $budget) && ! is_int($budget[$key])) {
                    throw new InvalidArgumentException(
                        "flood-control.classes.[{$class}]: '{$key}' must be an int.",
                    );
                }
            }

            if ($unknown = array_diff(array_keys($budget), ['limit', 'window'])) {
                throw new InvalidArgumentException(
                    "flood-control.classes.[{$class}]: unknown key(s) " . implode(', ', $unknown) . '.',
                );
            }
        }
    }

    /** A typo'd counter would otherwise silently never run. */
    private function validateCounters(): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        foreach ($this->counters() as $counter) {
            $name = is_string($counter) ? $counter : get_debug_type($counter);

            // Interfaces too: a counter can be a contract the app binds.
            if (! is_string($counter) || (! class_exists($counter) && ! interface_exists($counter))) {
                throw new InvalidArgumentException("flood-control.counters: [{$name}] is not a class or interface that exists.");
            }

            if (! method_exists($counter, '__invoke')) {
                throw new InvalidArgumentException("flood-control.counters: [{$name}] has no __invoke(Throwable \$e).");
            }
        }
    }

    private function registerAboutCommandIntegration(): void
    {
        AboutCommand::add('Flood Control', fn (): array => [
            'Exception budget' => sprintf(
                '%d per %ds',
                (int)config('flood-control.limit', ThrottleConfig::DEFAULT_LIMIT),
                (int)config('flood-control.window', ThrottleConfig::DEFAULT_WINDOW),
            ),
            'Per class budgets' => (string)count((array)config('flood-control.classes', [])),
            'Enabled'           => AboutCommand::format($this->app->make(ThrottleConfig::class)->enabled(), console: fn ($v) => $v ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF'),
            'Pulse counter'     => AboutCommand::format($this->wrapsPulse && PulseExceptionRecorder::countsInFront(), console: fn ($v) => $v ? '<fg=yellow;options=bold>ON</> (counts in front of the gate)' : 'OFF'),
        ]);
    }
}
