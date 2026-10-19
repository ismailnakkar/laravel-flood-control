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
    /** @var list<Throwable> */
    protected array $reported = [];

    protected function getPackageProviders($app): array
    {
        // CI also runs the suite without laravel/pulse.
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

    /** Records what passed the gate; returning false skips the handler's log write. */
    protected function captureReports(): void
    {
        $this->reported = [];

        app(ExceptionHandler::class)->reportable(function (Throwable $e): bool {
            $this->reported[] = $e;

            return false;
        });
    }

    /**
     * Runs $work and flushes Pulse into a capturing ingest, with no database.
     *
     * @return list<string> keys of the `exception` entries Pulse recorded
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
