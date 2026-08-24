<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class PulseDisabledTest extends TestCase
{
    /** @var list<string> */
    private array $pulseKeys = [];

    protected function setUp(): void
    {
        // Through the env, not defineEnvironment(): the recorder swap happens in register(), and
        // Testbench applies its environment callbacks after that. A real app reads the config file
        // during bootstrap, long before any provider registers.
        putenv('FLOOD_CONTROL_PULSE=false');

        parent::setUp();

        $this->skipWithoutPulse();
    }

    protected function tearDown(): void
    {
        putenv('FLOOD_CONTROL_PULSE');

        parent::tearDown();
    }

    #[Test]
    public function pulses_own_recorder_is_left_alone(): void
    {
        $this->assertNotFalse(config('pulse.recorders.' . PulseExceptions::class . '.enabled'));
    }

    #[Test]
    public function nothing_is_counted_by_the_package(): void
    {
        Pulse::shouldReceive('record')->andReturnUsing(function (string $type, string $key): Entry {
            $this->pulseKeys[] = "{$type}|{$key}";

            return new Entry(timestamp: time(), type: $type, key: $key, value: 1);
        });
        $this->captureReports();

        report(new RuntimeException('boom'));

        $this->assertSame([], $this->pulseKeys);
    }

    #[Test]
    public function the_throttle_still_works(): void
    {
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(1, $this->reported);
    }
}
