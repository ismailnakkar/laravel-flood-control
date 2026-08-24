<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\PulseExceptionRecorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\View\ViewException;
use Laravel\Pulse\Events\ExceptionReported;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Pulse as PulseInstance;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class PulseCounterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutPulse();
    }

    #[Test]
    public function every_exception_is_counted_even_when_the_report_is_throttled(): void
    {
        // The whole reason the counter is a throttle callback and not a reportable: the gate runs
        // first, so a counter behind it could only ever agree with the gate. Four, not five: the one
        // survivor must not also be counted by a reportable.
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        $counted = $this->pulseExceptions(function (): void {
            foreach (range(1, 4) as $i) {
                report(new RuntimeException("boom {$i}"));
            }
        });

        $this->assertCount(1, $this->reported);
        $this->assertCount(4, $counted);
    }

    #[Test]
    public function it_emits_the_shape_the_stock_pulse_card_reads(): void
    {
        $counted = $this->pulseExceptions(fn () => report(new RuntimeException('boom')));

        $this->assertSame(RuntimeException::class, json_decode($counted[0], true)[0]);
    }

    #[Test]
    public function it_keys_a_view_exception_under_the_exception_the_view_threw(): void
    {
        // Delegation earns this: keying on $e::class puts every Blade error on the card as
        // ViewException at a compiled-view path, which names neither the fault nor the file.
        $counted = $this->pulseExceptions(fn () => report(new ViewException(
            'Undefined variable (View: /app/resources/views/orders.blade.php)',
            0, 1, '/app/storage/framework/views/9f2.php', 12,
            new RuntimeException('inner'),
        )));

        $this->assertSame(RuntimeException::class, json_decode($counted[0], true)[0]);
    }

    #[Test]
    public function it_honours_pulses_own_ignore_list(): void
    {
        // The settings key on Pulse's class name, so only the stock recorder reads them right.
        config(['pulse.recorders.' . PulseExceptions::class . '.ignore' => ['/^' . preg_quote(RuntimeException::class, '/') . '$/']]);

        $this->assertSame([], $this->pulseExceptions(fn () => report(new RuntimeException('boom'))));
    }

    #[Test]
    public function pulse_report_still_records_after_the_swap(): void
    {
        // Pulse's recorder counts Pulse::report() from an event listener, not from the reportable
        // this package drops.
        $counted = $this->pulseExceptions(
            fn () => event(new ExceptionReported(new RuntimeException('boom'))),
        );

        $this->assertCount(1, $counted);
    }

    #[Test]
    public function a_counter_failure_does_not_disable_the_gate(): void
    {
        // The handler rescues a throwing throttle callback into "do not throttle", so an unguarded
        // throw here would turn counting into a kill switch for the whole package.
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        Pulse::partialMock()->shouldReceive('recorders')->andThrow(new RuntimeException('pulse is down'));
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function pulse_resolves_this_packages_recorder_in_place_of_its_own(): void
    {
        $recorders = app(PulseInstance::class)->recorders();

        $this->assertTrue($recorders->contains(fn (object $r): bool => $r instanceof PulseExceptionRecorder));
        $this->assertFalse($recorders->contains(fn (object $r): bool => $r instanceof PulseExceptions));
    }

    #[Test]
    public function the_recorder_swap_is_announced_by_artisan_about(): void
    {
        // Against the real buffer: `about` renders through its own formatter, which the
        // expectsOutputToContain() assertions do not see.
        $this->withoutMockingConsoleOutput();
        $this->artisan('about --only=flood_control');

        $output = $this->app[Kernel::class]->output();

        $this->assertStringContainsString('Pulse counter', $output);
        $this->assertStringContainsString('counts in front of the gate', $output);
        $this->assertStringContainsString('10 per 300s', $output);
        $this->assertStringContainsString('ENABLED', $output);
    }
}
