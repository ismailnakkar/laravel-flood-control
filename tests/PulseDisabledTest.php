<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use Laravel\Pulse\Events\ExceptionReported;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * FLOOD_CONTROL_PULSE=false: Pulse counts as if this package's counter did not exist. The swap stays
 * wired either way, so the switch is read per exception.
 */
class PulseDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        // Via the env, so the documented FLOOD_CONTROL_PULSE key stays wired to config/flood-control.php.
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
    public function pulse_report_is_still_counted(): void
    {
        // The stock recorder's listener went with the swap, so this package's must stand in for it.
        $counted = $this->pulseExceptions(
            fn () => event(new ExceptionReported(new RuntimeException('boom'))),
        );

        $this->assertCount(1, $counted);
    }

    #[Test]
    public function switching_it_on_after_boot_counts_in_front_of_the_gate(): void
    {
        config(['flood-control.pulse' => true, 'flood-control.limit' => 1, 'flood-control.window' => 300]);
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
    public function only_survivors_are_counted(): void
    {
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        $counted = $this->pulseExceptions(function (): void {
            foreach (range(1, 4) as $i) {
                report(new RuntimeException("boom {$i}"));
            }
        });

        $this->assertCount(1, $this->reported);
        $this->assertCount(1, $counted);
    }
}
