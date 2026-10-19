<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Tests\Fixtures\UngateableHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Pulse\Events\ExceptionReported;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** A handler with no throttleUsing() to find: nothing is gated, and Pulse still counts once. */
class UngateableHandlerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app->singleton(ExceptionHandler::class, fn (): ExceptionHandler => new UngateableHandler);
    }

    #[Test]
    public function reports_are_still_counted_once(): void
    {
        $this->skipWithoutPulse();

        $counted = $this->pulseExceptions(fn () => report(new RuntimeException('boom')));

        $this->assertCount(1, $counted);
    }

    #[Test]
    public function pulse_report_is_still_counted_once(): void
    {
        $this->skipWithoutPulse();

        $counted = $this->pulseExceptions(
            fn () => event(new ExceptionReported(new RuntimeException('boom'))),
        );

        $this->assertCount(1, $counted);
    }

    #[Test]
    public function booting_still_succeeds(): void
    {
        $this->assertTrue($this->app->isBooted());
    }
}
