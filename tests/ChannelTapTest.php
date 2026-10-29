<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use DateTimeImmutable;
use FloodControl\LogThrottle;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ChannelTapTest extends TestCase
{
    /** The tapped channel's handler, at error like a Sentry sink. */
    private TestHandler $sink;

    /** An untapped channel's handler, at debug like a daily file. */
    private TestHandler $file;

    /** A second tapped sink of another handler class, like Slack beside Sentry. */
    private TestHandler $otherSink;

    protected function defineEnvironment($app): void
    {
        $this->sink = new TestHandler(Level::Error);
        $this->file = new TestHandler(Level::Debug);
        $this->otherSink = new class(Level::Error) extends TestHandler {};

        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);
        $app['config']->set('logging.channels.sink', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('sink', [$this->sink]),
            'tap'    => [LogThrottle::class],
        ]);
        $app['config']->set('logging.channels.file', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('file', [$this->file]),
        ]);
        $app['config']->set('logging.channels.other-sink', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('other-sink', [$this->otherSink]),
            'tap'    => [LogThrottle::class],
        ]);
        $app['config']->set('logging.channels.sink-first', ['driver' => 'stack', 'channels' => ['sink', 'file']]);
        $app['config']->set('logging.channels.both-sinks', ['driver' => 'stack', 'channels' => ['sink', 'other-sink']]);
        $app['config']->set('logging.channels.file-first', ['driver' => 'stack', 'channels' => ['file', 'sink']]);
    }

    /**
     * Separate call sites: the file and line are the bucket.
     *
     * @param  array<string, mixed>  $context
     */
    private function fromFirstSite(string $message = 'first site', array $context = [], string $level = 'error'): void
    {
        Log::channel('sink')->log($level, $message, $context);
    }

    private function fromSecondSite(): void
    {
        Log::channel('sink')->error('second site');
    }

    /** @return array<int, string> */
    private static function messages(TestHandler $handler): array
    {
        return array_map(static fn (LogRecord $record): string => $record->message, $handler->getRecords());
    }

    private function tapped(): HandlerInterface
    {
        $logger = Log::channel('sink');
        $this->assertInstanceOf(Logger::class, $logger);

        return $logger->getHandlers()[0];
    }

    #[Test]
    public function one_call_site_is_cut_off_at_its_budget(): void
    {
        // Tapped before the change: the budget is read per line, not when the tap runs.
        Log::channel('sink');
        config(['flood-control.limit' => 2]);

        foreach (range(1, 5) as $ignored) {
            $this->fromFirstSite();
        }

        $this->assertCount(2, $this->sink->getRecords());
    }

    #[Test]
    public function interpolated_messages_from_one_site_share_a_bucket(): void
    {
        // Placeholders are filled before any handler runs, so the message is no key.
        foreach (range(1, 5) as $i) {
            $this->fromFirstSite("order {$i} failed");
        }

        $this->assertSame(['order 1 failed'], self::messages($this->sink));
    }

    #[Test]
    public function separate_call_sites_do_not_spend_each_others_budget(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->fromFirstSite();
            $this->fromSecondSite();
        }

        $this->assertSame(['first site', 'second site'], self::messages($this->sink));
    }

    #[Test]
    public function each_level_has_its_own_bucket(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->fromFirstSite('error');
            $this->fromFirstSite('critical', level: 'critical');
        }

        $this->assertSame(['error', 'critical'], self::messages($this->sink));
    }

    #[Test]
    public function the_call_site_lookup_leaves_the_record_alone(): void
    {
        $this->fromFirstSite();

        $this->assertSame([], $this->sink->getRecords()[0]->extra);
    }

    #[Test]
    public function a_line_below_the_handlers_level_costs_no_cache_call(): void
    {
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->expects($this->never())->method('attempt');
        $this->app->instance(RateLimiter::class, $limiter);

        // The file handler initialises the record, after which Monolog asks later handlers no isHandling().
        Log::channel('file-first')->info('chatter');

        $this->assertSame(['chatter'], self::messages($this->file));
    }

    #[Test]
    public function a_record_carrying_an_exception_passes_uncounted(): void
    {
        // The exception gate already limited it.
        foreach (range(1, 3) as $i) {
            $this->fromFirstSite("reported {$i}", ['exception' => new RuntimeException]);
        }

        $this->fromFirstSite('plain');
        $this->fromFirstSite('plain again');

        $this->assertSame(['reported 1', 'reported 2', 'reported 3', 'plain'], self::messages($this->sink));
    }

    #[Test]
    public function a_dropped_line_still_reaches_the_next_handler_in_a_stack(): void
    {
        foreach (range(1, 3) as $i) {
            Log::channel('sink-first')->error("line {$i}");
        }

        $this->assertSame(['line 1'], self::messages($this->sink));
        $this->assertSame(['line 1', 'line 2', 'line 3'], self::messages($this->file));
    }

    #[Test]
    public function two_tapped_sinks_in_a_stack_do_not_share_a_bucket(): void
    {
        foreach (range(1, 3) as $i) {
            Log::channel('both-sinks')->error("line {$i}");
        }

        $this->assertSame(['line 1'], self::messages($this->sink));
        $this->assertSame(['line 1'], self::messages($this->otherSink));
    }

    #[Test]
    public function a_limiter_failure_lets_every_line_through(): void
    {
        // Tapped before the binding: the limiter is resolved per line, as Octane needs.
        Log::channel('sink');

        $this->app->bind(RateLimiter::class, function (): RateLimiter {
            $limiter = $this->createStub(RateLimiter::class);
            $limiter->method('attempt')->willThrowException(new RuntimeException('redis is down'));

            return $limiter;
        });

        foreach (range(1, 3) as $ignored) {
            $this->fromFirstSite();
        }

        $this->assertCount(3, $this->sink->getRecords());
    }

    #[Test]
    public function the_switch_being_off_gates_nothing(): void
    {
        // Tapped before the change: the switch is read per line.
        Log::channel('sink');
        config(['flood-control.enabled' => false]);

        foreach (range(1, 3) as $ignored) {
            $this->fromFirstSite();
        }

        $this->assertCount(3, $this->sink->getRecords());
    }

    #[Test]
    public function tap_arguments_set_the_channels_own_budget(): void
    {
        // The configured budget is 1 per 300s.
        config(['logging.channels.sink.tap' => [LogThrottle::class . ':3,600']]);

        foreach (range(1, 5) as $ignored) {
            $this->fromFirstSite();
        }

        $this->travel(301)->seconds();
        $this->fromFirstSite();

        $this->assertCount(3, $this->sink->getRecords());
    }

    /** @return array<string, list<string>> */
    public static function malformedTapArguments(): array
    {
        return [
            'limit only'       => ['3', ''],
            'window only'      => ['', '600'],
            'not a number'     => ['three', '600'],
            'a unit'           => ['3', '10m'],
            'negative'         => ['-1', '600'],
            'a third'          => ['3', '600', '9'],
            'a trailing comma' => ['3', '600', ''],
        ];
    }

    #[Test]
    #[DataProvider('malformedTapArguments')]
    public function malformed_tap_arguments_throw(string ...$arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LogThrottle)(new Logger(new Monolog('tapped')), ...$arguments);
    }

    #[Test]
    public function a_batch_is_filtered_like_single_lines(): void
    {
        $record = static fn (string $message, array $context = []): LogRecord => new LogRecord(
            new DateTimeImmutable,
            'sink',
            Level::Error,
            $message,
            $context,
        );

        // One call site for the whole batch: where it is flushed.
        $this->tapped()->handleBatch([
            $record('kept'),
            $record('over budget'),
            $record('reported', ['exception' => new RuntimeException]),
        ]);

        $this->assertSame(['kept', 'reported'], self::messages($this->sink));
    }

    #[Test]
    public function a_later_tap_can_still_set_up_the_handler(): void
    {
        // What a tap listed after this one calls on each handler.
        $handler = $this->tapped();
        $this->assertInstanceOf(ProcessableHandlerInterface::class, $handler);
        $this->assertInstanceOf(FormattableHandlerInterface::class, $handler);

        $handler->pushProcessor(static function (LogRecord $record): LogRecord {
            $record->extra['tapped'] = true;

            return $record;
        });

        $this->fromFirstSite();

        $this->assertSame($this->sink->getFormatter(), $handler->getFormatter());
        $this->assertSame(['tapped' => true], $this->sink->getRecords()[0]->extra);
    }
}
