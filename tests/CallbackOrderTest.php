<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * The seam the README advertises: a throttle callback that returns null falls through to the next,
 * so an app can register a counter ahead of the package and see every exception, throttled or not.
 * If this breaks, every "unthrottled rate signal" built on it goes quiet without a failure.
 */
class CallbackOrderTest extends TestCase
{
    /** @var list<class-string> */
    public static array $counted = [];

    protected function defineEnvironment($app): void
    {
        self::$counted = [];

        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);

        // Registered before the package's provider boots, exactly as withExceptions() would be.
        $app->afterResolving(ExceptionHandler::class, function ($handler): void {
            $handler->throttleUsing(function (Throwable $e): null {
                self::$counted[] = $e::class;

                return null;
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
}
