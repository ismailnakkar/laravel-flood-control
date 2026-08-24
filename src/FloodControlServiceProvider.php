<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;

class FloodControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flood-control.php', 'flood-control');

        $this->app->singleton(ThrottleConfig::class);

        $this->replacePulseRecorder();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/flood-control.php' => config_path('flood-control.php'),
        ], 'flood-control-config');

        // Registered here rather than asking for a bootstrap/app.php edit. Callbacks the app
        // registers in withExceptions() are added during bootstrap, so they sit ahead of these and
        // win — which is the precedence you want, and what lets an app slot its own counter in front.
        $this->registerAboutCommandIntegration();

        $this->validateClassBudgets();

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'throttleUsing')) {
                return;
            }

            // Counter first: it returns null, so the handler falls through to the gate.
            if ($this->countsWithPulse()) {
                $handler->throttleUsing(PulseExceptionCounter::record(...));
            }

            $handler->throttleUsing(ExceptionThrottle::for(...));
        });
    }

    /**
     * Config entries are scalars, so nothing type-checks them: a typo'd FQCN or a misspelled key
     * silently never matches and the default budget applies instead — a throttle you configured and
     * never got. Skipped once config is cached, so the cost lands on `config:cache` at deploy time
     * and on every dev and CI boot, and never on a production request.
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

            if (! is_array($budget) || ! is_int($budget['limit'] ?? null)) {
                throw new InvalidArgumentException(
                    "flood-control.classes.[{$class}]: expected ['limit' => int, 'window' => ?int].",
                );
            }

            if (array_key_exists('window', $budget) && ! is_int($budget['window'])) {
                throw new InvalidArgumentException(
                    "flood-control.classes.[{$class}]: 'window' must be an int number of seconds.",
                );
            }

            if ($unknown = array_diff(array_keys($budget), ['limit', 'window'])) {
                throw new InvalidArgumentException(
                    "flood-control.classes.[{$class}]: unknown key(s) " . implode(', ', $unknown) . '.',
                );
            }
        }
    }

    /**
     * Turning off another package's recorder is defensible but it must not be invisible: `about` is
     * where Laravel expects a package to say what it changed.
     */
    private function registerAboutCommandIntegration(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Flood Control', fn (): array => [
            'Exception budget'  => sprintf('%d per %ds', config('flood-control.limit'), config('flood-control.window')),
            'Per-class budgets' => count((array)config('flood-control.classes', [])),
            'Enabled'           => config('flood-control.enabled') ? 'ENABLED' : 'OFF',
            'Pulse counter'     => $this->countsWithPulse() ? 'ON (replaces Pulse recorder)' : 'OFF',
        ]);
    }

    /**
     * Pulse reads `pulse.recorders` in its own boot(), and every provider's register() runs before
     * any boot(), so this always lands in time. Leaving both on would count each surviving exception
     * twice — its recorder counts what got past the gate, ours counts everything.
     */
    private function replacePulseRecorder(): void
    {
        if (! $this->countsWithPulse()) {
            return;
        }

        $this->app->make('config')->set(
            'pulse.recorders.' . PulseExceptions::class . '.enabled',
            false,
        );
    }

    private function countsWithPulse(): bool
    {
        return (bool)$this->app->make('config')->get('flood-control.pulse', true)
            && class_exists(Pulse::class);
    }
}
