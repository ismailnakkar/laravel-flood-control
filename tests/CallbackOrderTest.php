<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Tests\Fixtures\RecordingCounter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * An app's own throttle callbacks are registered during bootstrap, so they sit ahead of this
 * package's. Handler::throttle() stops at the first non-null return — which must not be able to
 * starve the counters.
 */
class CallbackOrderTest extends TestCase
{
    /** @var list<class-string> */
    private static array $counted = [];

    private ?Limit $appLimit = null;

    private RecordingCounter $counter;

    protected function defineEnvironment($app): void
    {
        self::$counted = [];

        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);
        $app['config']->set('flood-control.counters', [RecordingCounter::class]);

        $app->instance(RecordingCounter::class, $this->counter = new RecordingCounter);

        $limit = $this->appLimit;

        // Registered before the package's provider boots, as withExceptions() would be.
        $app->afterResolving(ExceptionHandler::class, function ($handler) use ($limit): void {
            $handler->throttleUsing(function (Throwable $e) use ($limit): ?Limit {
                self::$counted[] = $e::class;

                return $limit;
            });
        });
    }

    #[Test]
    public function a_counter_registered_first_sees_every_exception(): void
    {
        $this->captureReports();

        foreach (range(1, 3) as $ignored) {
            report(new RuntimeException('boom'));
        }

        $this->assertCount(3, self::$counted, 'the counter must see every exception');
        $this->assertCount(1, $this->reported, 'and the package must still gate behind it');
    }

    #[Test]
    public function an_app_that_overrides_the_gate_does_not_starve_the_counters(): void
    {
        // Returning a Limit is how the README says to override the package outright. It ends the
        // throttle chain, so counting cannot live there.
        $this->appLimit = Limit::perMinute(3);
        $this->refreshApplication();
        $this->captureReports();

        foreach (range(1, 5) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(5, $this->counter->seen, 'the configured counters must still see everything');
        $this->assertCount(3, $this->reported, "and the app's own limit must still win");
    }

    #[Test]
    public function pulse_is_not_starved_either(): void
    {
        // Dropping Pulse's reportable leaves this package as the only counting hook, so starving it
        // takes the card below what stock Pulse would have shown.
        $this->skipWithoutPulse();

        $this->appLimit = Limit::perMinute(3);
        $this->refreshApplication();
        $this->captureReports();

        $counted = $this->pulseExceptions(function (): void {
            foreach (range(1, 5) as $i) {
                report(new RuntimeException("boom {$i}"));
            }
        });

        $this->assertCount(5, $counted);
        $this->assertCount(3, $this->reported);
    }
}
