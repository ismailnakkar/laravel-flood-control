<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\FloodControlServiceProvider;
use FloodControl\Tests\Fixtures\CapturingIngest;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Throwable;

abstract class TestCase extends Orchestra
{
    /** @var list<Throwable> every exception that survived the throttle */
    protected array $reported = [];

    protected function getPackageProviders($app): array
    {
        // Pulse is a suggestion, not a requirement. The suite runs both ways — see the CI matrix.
        return array_values(array_filter([
            self::hasPulse() ? PulseServiceProvider::class : null,
            FloodControlServiceProvider::class,
        ]));
    }

    protected static function hasPulse(): bool
    {
        return class_exists(PulseServiceProvider::class);
    }

    protected function skipWithoutPulse(): void
    {
        if (! self::hasPulse()) {
            $this->markTestSkipped('laravel/pulse is not installed.');
        }
    }

    /**
     * Reportable callbacks run only for exceptions that got past shouldntReport(), so this is the
     * throttle's decision made observable. Returning false stops the handler's own log write.
     */
    protected function captureReports(): void
    {
        $this->reported = [];

        app(ExceptionHandler::class)->reportable(function (Throwable $e): bool {
            $this->reported[] = $e;

            return false;
        });
    }

    /**
     * Runs $work and flushes Pulse through a capturing ingest: its real path, with no database.
     *
     * @return list<string> the key of every `exception` entry that reached Pulse
     */
    protected function pulseExceptions(callable $work): array
    {
        $this->app->instance(Ingest::class, $ingest = new CapturingIngest);

        $work();

        Pulse::ingest();

        return array_values(array_map(
            static fn (Entry $entry): string => $entry->key,
            array_filter($ingest->entries, static fn (Entry $entry): bool => $entry->type === 'exception'),
        ));
    }
}
