<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Budget;
use FloodControl\LogThrottle;
use FloodControl\Tests\Fixtures\Channel;
use Illuminate\Cache\CacheManager;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;

class LogThrottleTest extends TestCase
{
    /** @var list<string> every line that reached a handler, as "{level}|{message}" */
    private array $written = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.default', 'discard');
        $app['config']->set('logging.channels.discard', ['driver' => 'monolog', 'handler' => NullHandler::class]);
        $app['config']->set('logging.channels.audit', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->written = [];

        Log::listen(function (MessageLogged $message): void {
            $this->written[] = "{$message->level}|{$message->message}";
        });
    }

    #[Test]
    public function the_first_line_in_a_window_is_written_and_the_rest_are_not(): void
    {
        LogThrottle::once('a-key', 60)->warning('first');
        LogThrottle::once('a-key', 60)->warning('second');

        $this->assertSame(['warning|first'], $this->written);
    }

    #[Test]
    public function a_bare_log_spy_is_enough_to_assert_on(): void
    {
        // Resolving Log::driver() here returned null under a bare spy, so every throttled line in a
        // suite blew up with a TypeError until the caller stubbed driver() by hand.
        Log::spy();

        LogThrottle::once('spy-key', 60)->warning('recorded');

        Log::shouldHaveReceived('warning')->with('recorded')->once();
    }

    #[Test]
    public function times_writes_the_budget_and_then_stops(): void
    {
        foreach (range(1, 5) as $i) {
            LogThrottle::times('burst', Budget::of(3, 300))->warning("line {$i}");
        }

        $this->assertSame(['warning|line 1', 'warning|line 2', 'warning|line 3'], $this->written);
    }

    #[Test]
    public function times_keeps_separate_keys_apart(): void
    {
        LogThrottle::times('one', Budget::of(1, 300))->warning('from one');
        LogThrottle::times('one', Budget::of(1, 300))->warning('from one again');
        LogThrottle::times('two', Budget::of(1, 300))->warning('from two');

        $this->assertSame(['warning|from one', 'warning|from two'], $this->written);
    }

    #[Test]
    public function an_unlimited_budget_never_throttles(): void
    {
        foreach (range(1, 3) as $i) {
            LogThrottle::times('no-gate', Budget::unlimited())->warning("line {$i}");
        }

        $this->assertCount(3, $this->written);
    }

    #[Test]
    public function the_gate_lives_in_the_cache_not_in_an_instance(): void
    {
        // A per-worker gate would satisfy every "logged once" assertion while each worker still
        // emitted the line.
        LogThrottle::once('shared', 60);

        $store = $this->app->make(CacheManager::class)->store(config('cache.limiter'));

        $this->assertTrue($store->has('log-throttle:' . hash('xxh128', 'shared')));
    }

    #[Test]
    public function the_window_ends(): void
    {
        LogThrottle::once('expiring', 60)->error('first');
        $this->travel(61)->seconds();
        LogThrottle::once('expiring', 60)->error('second');

        $this->assertSame(['error|first', 'error|second'], $this->written);
    }

    #[Test]
    public function distinct_keys_hold_separate_gates(): void
    {
        LogThrottle::once('key-a', 60)->error('a');
        LogThrottle::once('key-b', 60)->error('b');

        $this->assertSame(['error|a', 'error|b'], $this->written);
    }

    #[Test]
    public function a_null_window_never_throttles(): void
    {
        LogThrottle::once('never', null)->info('one');
        LogThrottle::once('never', null)->info('two');

        $this->assertSame(['info|one', 'info|two'], $this->written);
    }

    #[Test]
    public function a_window_below_one_never_throttles(): void
    {
        // Same rule as the exception side: a 0 from an empty config value must not silence a line.
        LogThrottle::once('zero', 0)->info('one');
        LogThrottle::once('zero', 0)->info('two');

        $this->assertSame(['info|one', 'info|two'], $this->written);
    }

    #[Test]
    public function a_cache_failure_logs_rather_than_throwing(): void
    {
        // Logging must never break its caller, and an outage is when the line is worth most.
        $this->app->bind('cache', function (): CacheManager {
            $cache = $this->createStub(CacheManager::class);
            $cache->method('store')->willThrowException(new RuntimeException('redis is down'));

            return $cache;
        });

        LogThrottle::once('outage', 60)->error('one');
        LogThrottle::once('outage', 60)->error('two');

        $this->assertSame(['error|one', 'error|two'], $this->written);
    }

    #[Test]
    public function a_suppressed_line_fires_no_log_event(): void
    {
        // A NullLogger would be silent too, but Telescope, Log::listen() and Sentry breadcrumbs all
        // hang off MessageLogged — a throttle that still fires it throttles nothing that matters.
        LogThrottle::once('quiet', 60)->error('first');
        LogThrottle::once('quiet', 60)->error('second');

        $this->assertSame(['error|first'], $this->written);
    }

    #[Test]
    public function a_suppressed_logger_accepts_everything_the_real_one_does(): void
    {
        // The throttled return value has to be a drop-in, or every call site that chains fatals on
        // its second pass through the window.
        LogThrottle::once('chained', 60)->withContext(['a' => 1])->warning(['array' => 'message']);
        LogThrottle::once('chained', 60)->withContext(['a' => 1])->warning(['array' => 'message']);

        $this->assertCount(1, $this->written);
    }

    #[Test]
    public function it_can_throttle_a_named_channel(): void
    {
        $this->assertSame(Log::channel('audit'), LogThrottle::once('channelled', 60, 'audit'));

        LogThrottle::once('channelled', 60, 'audit')->error('suppressed');

        $this->assertSame([], $this->written);
    }

    #[Test]
    public function it_can_throttle_an_enum_channel(): void
    {
        // Log::driver() only resolves enums itself from Laravel 13.3; before that it trims them.
        $this->assertSame(Log::channel('audit'), LogThrottle::once('enumerated', 60, Channel::Audit));
    }

    #[Test]
    public function it_can_throttle_an_on_demand_stack(): void
    {
        LogThrottle::once('stacked', 60, ['discard', 'audit'])->error('first');
        LogThrottle::once('stacked', 60, ['discard', 'audit'])->error('second');

        $this->assertSame(['error|first'], $this->written);
    }

    #[Test]
    public function it_can_throttle_a_logger_it_is_handed(): void
    {
        $built = Log::build(['driver' => 'monolog', 'handler' => NullHandler::class]);

        $this->assertSame($built, LogThrottle::once('built', 60, $built));
        $this->assertNotSame($built, LogThrottle::once('built', 60, $built));
        $this->assertInstanceOf(LoggerInterface::class, LogThrottle::once('built', 60, $built));
    }
}
