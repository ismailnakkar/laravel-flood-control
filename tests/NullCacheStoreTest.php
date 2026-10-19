<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\LogThrottle;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class NullCacheStoreTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'null');
        $app['config']->set('logging.default', 'discard');
        $app['config']->set('logging.channels.discard', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->written = [];

        Log::listen(function (MessageLogged $message): void {
            $this->written[] = $message->message;
        });
    }

    #[Test]
    public function a_no_op_cache_store_fails_open(): void
    {
        LogThrottle::once('same-key', 60)->error('first');
        LogThrottle::once('same-key', 60)->error('second');

        $this->assertSame(['first', 'second'], $this->written);
    }

    #[Test]
    public function the_exception_side_fails_open_too(): void
    {
        // The RateLimiter reads 0 attempts from a no-op store, so nothing is throttled.
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(3, $this->reported);
    }
}
