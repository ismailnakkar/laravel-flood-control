<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use ReflectionObject;
use Throwable;
use WeakMap;

class FloodControlServiceProvider extends ServiceProvider
{
    private bool $countsWithPulse = false;

    private bool $countersRunning = false;

    /** @var WeakMap<object, true> Handlers already wired. */
    private WeakMap $wired;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flood-control.php', 'flood-control');

        $this->wired = new WeakMap;

        $this->app->singleton(ThrottleConfig::class);

        // Decided here because the binding has to land before Pulse boots and resolves its
        // recorders. Registered after boot, Pulse already has the stock one, so leave it counting
        // rather than counting survivors twice.
        $this->countsWithPulse = class_exists(PulseExceptions::class)
            && ! $this->app->isBooted()
            && (bool)$this->app->make('config')->get('flood-control.pulse', true);

        if ($this->countsWithPulse) {
            $this->app->bind(PulseExceptions::class, static function (Container $app): PulseExceptionRecorder {
                // build(), not make(): this binding points that class back here.
                return new PulseExceptionRecorder($app->build(PulseExceptions::class));
            });
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/flood-control.php' => config_path('flood-control.php'),
            ], 'flood-control-config');

            $this->registerAboutCommandIntegration();
        }

        $this->validateClassBudgets();
        $this->validateCounters();

        // Registered here, not via a bootstrap/app.php edit. An app's withExceptions() callbacks are
        // added during bootstrap, so they sit ahead of these and win.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $resolved): void {
            $handler = self::unwrap($resolved);
            $target = $handler ?? $resolved;

            // Once per handler, not once per resolve: callAfterResolving() also fires for an
            // already-resolved instance, so a decorated binding unwraps to this same object twice.
            // dontReportWhen() appends, so a second pass counts every exception again.
            if (isset($this->wired[$target])) {
                return;
            }

            $this->wired[$target] = true;

            if ($handler === null) {
                $this->countPulseFromAReportable($resolved);

                return;
            }

            // Counting hangs off dontReportWhen, not throttleUsing: shouldntReport() runs those
            // callbacks ahead of the whole throttle chain, and Handler::throttle() stops at the
            // first non-null return — so an app throttle callback of its own would otherwise
            // starve the counters. Returning null is not `=== true`, so nothing is suppressed here.
            if ($this->countsWithPulse) {
                $handler->dontReportWhen(PulseExceptionRecorder::count(...));
            }

            if ($this->counters() !== []) {
                $handler->dontReportWhen($this->runCounters(...));
            }

            $handler->throttleUsing(ExceptionThrottle::for(...));
        });
    }

    /**
     * Every configured counter, ahead of the gate. Returns null, so ExceptionThrottle still decides.
     */
    private function runCounters(Throwable $e): null
    {
        // Counters run in front of the gate, so the gate cannot stop a counter that reports: each
        // nesting level counts before it is throttled. Skip the nested pass instead of recursing.
        if ($this->countersRunning) {
            return null;
        }

        $this->countersRunning = true;

        try {
            foreach ($this->counters() as $counter) {
                try {
                    $this->app->make($counter)($e);
                } catch (Throwable) {
                    // dontReportCallbacks are not inside the handler's rescue(), so an escaping
                    // throw would take down report() itself.
                }
            }
        } finally {
            $this->countersRunning = false;
        }

        return null;
    }

    /** @return list<string> */
    private function counters(): array
    {
        return array_values((array)$this->app->make('config')->get('flood-control.counters', []));
    }

    /**
     * `throttleUsing()` is not on the ExceptionHandler contract, so a decorator — nunomaduro/collision
     * wraps the binding on every console run — hides it. Walk to the handler that has it.
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

    /**
     * A handler with no throttleUsing() gates nothing, so nothing is suppressed and counting belongs
     * back where Pulse had it — behind the report this package no longer intercepts.
     */
    private function countPulseFromAReportable(object $handler): void
    {
        if ($this->countsWithPulse && method_exists($handler, 'reportable')) {
            $handler->reportable(PulseExceptionRecorder::count(...));
        }
    }

    /**
     * Config entries are scalars: a typo'd FQCN or key silently never matches and the default budget
     * applies instead. Skipped when config is cached, so typos surface at `config:cache`.
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

    /** A typo'd FQCN is a counter that silently never runs. Skipped when config is cached. */
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

    /** Standing in for another package's recorder must not be invisible. */
    private function registerAboutCommandIntegration(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        // The same fallbacks ThrottleConfig applies: a config cache written before the package was
        // installed leaves every key absent, and a panel reading 0 per 0s would call the package off
        // while it is throttling.
        AboutCommand::add('Flood Control', fn (): array => [
            'Exception budget' => sprintf(
                '%d per %ds',
                (int)config('flood-control.limit', ThrottleConfig::DEFAULT_LIMIT),
                (int)config('flood-control.window', ThrottleConfig::DEFAULT_WINDOW),
            ),
            'Per class budgets' => (string)count((array)config('flood-control.classes', [])),
            'Enabled'           => AboutCommand::format($this->app->make(ThrottleConfig::class)->enabled(), console: fn ($v) => $v ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF'),
            'Pulse counter'     => AboutCommand::format($this->countsWithPulse, console: fn ($v) => $v ? '<fg=yellow;options=bold>ON</> (counts in front of the gate)' : 'OFF'),
        ]);
    }
}
