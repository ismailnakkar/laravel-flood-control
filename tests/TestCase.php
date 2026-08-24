<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\FloodControlServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
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
}
