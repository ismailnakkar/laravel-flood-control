<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Tests\Fixtures\DecoratingHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * throttleUsing() is not on the ExceptionHandler contract, so a decorator hides it. Collision wraps
 * the binding on every console run, which is where floods actually happen — queue:work.
 */
class DecoratedHandlerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);

        // Exactly what CollisionServiceProvider::register() does.
        $real = $app->make(ExceptionHandler::class);
        $app->singleton(ExceptionHandler::class, fn (): ExceptionHandler => new DecoratingHandler($real));
    }

    #[Test]
    public function the_throttle_still_reaches_the_handler_behind_a_decorator(): void
    {
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(1, $this->reported);
    }
}
