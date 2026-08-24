<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\LogThrottle;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

class LogThrottleTest extends TestCase
{
    #[Test]
    public function the_first_line_in_a_window_is_logged_and_the_rest_are_not(): void
    {
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('a-key', 60));
        $this->assertInstanceOf(NullLogger::class, LogThrottle::once('a-key', 60));
    }

    #[Test]
    public function the_gate_lives_in_the_cache_not_in_an_instance(): void
    {
        // The property that matters: a per-worker gate would satisfy every "logged once" assertion
        // while each worker still emitted the line.
        LogThrottle::once('shared', 60);

        $this->assertTrue(app(Cache::class)->has('log-throttle:shared'));
    }

    #[Test]
    public function distinct_keys_hold_separate_gates(): void
    {
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('key-a', 60));
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('key-b', 60));
    }

    #[Test]
    public function a_null_window_never_throttles(): void
    {
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('never', null));
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('never', null));
    }

    #[Test]
    public function a_window_below_one_never_throttles(): void
    {
        // Same rule as the exception side: a 0 from an empty config value must not silence a line.
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('zero', 0));
        $this->assertNotInstanceOf(NullLogger::class, LogThrottle::once('zero', 0));
    }

    #[Test]
    public function a_cache_failure_logs_rather_than_throwing(): void
    {
        // Logging must never break its caller, and an outage is when the line is worth most.
        $this->app->bind(Cache::class, function (): Cache {
            $cache = $this->createStub(Cache::class);
            $cache->method('add')->willThrowException(new RuntimeException('redis is down'));

            return $cache;
        });

        $logger = LogThrottle::once('outage', 60);

        $this->assertNotInstanceOf(NullLogger::class, $logger);
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    #[Test]
    public function it_can_throttle_a_named_channel(): void
    {
        config(['logging.channels.audit' => ['driver' => 'single', 'path' => storage_path('logs/audit.log')]]);

        $first = LogThrottle::once('channelled', 60, 'audit');

        $this->assertSame(Log::channel('audit'), $first);
        $this->assertInstanceOf(NullLogger::class, LogThrottle::once('channelled', 60, 'audit'));
    }
}
