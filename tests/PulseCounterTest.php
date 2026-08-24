<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;

class PulseCounterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutPulse();
    }

    /** @var list<string> every Pulse::record() since capturePulseKeys(), as "{type}|{key}" */
    private array $pulseKeys = [];

    #[Test]
    public function every_exception_is_counted_even_when_the_report_is_throttled(): void
    {
        // The whole reason the counter is a throttle callback and not a reportable: the gate runs
        // first, so a counter behind it could only ever agree with the gate.
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->capturePulseKeys();
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(1, $this->reported);
        $this->assertCount(4, $this->pulseKeys);
    }

    #[Test]
    public function it_emits_the_shape_the_stock_pulse_card_reads(): void
    {
        $this->capturePulseKeys();

        report(new RuntimeException('boom'));

        [$type, $key] = explode('|', $this->pulseKeys[0], 2);

        $this->assertSame('exception', $type);
        $this->assertSame(RuntimeException::class, json_decode($key, true)[0]);
    }

    #[Test]
    public function the_location_matches_what_pulses_own_recorder_would_produce(): void
    {
        // Reflection on purpose: if a Pulse upgrade changes resolveLocation(), the stock card
        // silently splits into two series, and this is the only thing that would notice.
        $e = new RuntimeException('boom');
        $recorder = app(PulseExceptions::class);
        $resolveLocation = new ReflectionMethod($recorder, 'resolveLocation');

        $this->capturePulseKeys();
        report($e);

        $ours = json_decode(explode('|', $this->pulseKeys[0], 2)[1], true);

        $this->assertSame($resolveLocation->invoke($recorder, $e), $ours[1]);
    }

    #[Test]
    public function pulses_own_recorder_is_turned_off_so_survivors_are_not_counted_twice(): void
    {
        $this->assertFalse(config('pulse.recorders.' . PulseExceptions::class . '.enabled'));
    }

    #[Test]
    public function the_reportable_ordering_that_makes_this_necessary_still_holds(): void
    {
        // If Laravel ever moved the throttle gate behind the reportable callbacks, replacing Pulse's
        // recorder would stop being necessary — and this whole class would be doing harm.
        $handler = new ReflectionMethod(app(ExceptionHandler::class), 'shouldntReport');
        $source = file($handler->getFileName());
        $body = implode('', array_slice($source, $handler->getStartLine() - 1, $handler->getEndLine() - $handler->getStartLine() + 1));

        $this->assertStringContainsString('$this->throttle($e)', $body);
    }

    #[Test]
    public function the_recorder_swap_is_announced_by_artisan_about(): void
    {
        // Turning off another package's recorder is defensible; doing it invisibly is not.
        // Against the real buffer: `about` renders through its own formatter, which the
        // expectsOutputToContain() assertions do not see.
        $this->withoutMockingConsoleOutput();
        $this->artisan('about --only=flood_control');

        $output = $this->app[Kernel::class]->output();

        $this->assertStringContainsString('Pulse counter', $output);
        $this->assertStringContainsString('replaces Pulse recorder', $output);
    }

    /** Mocks Pulse so the keys a report would emit are observable without a store. */
    private function capturePulseKeys(): void
    {
        $this->pulseKeys = [];

        Pulse::shouldReceive('record')->andReturnUsing(function (string $type, string $key): Entry {
            $this->pulseKeys[] = "{$type}|{$key}";

            return new Entry(timestamp: time(), type: $type, key: $key, value: 1);
        });
    }
}
