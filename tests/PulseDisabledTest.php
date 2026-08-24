<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\PulseExceptionRecorder;
use Laravel\Pulse\Pulse as PulseInstance;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** FLOOD_CONTROL_PULSE=false: Pulse behaves as if this package's counter did not exist. */
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
    public function pulse_keeps_its_own_recorder(): void
    {
        $recorders = app(PulseInstance::class)->recorders();

        $this->assertTrue($recorders->contains(fn (object $r): bool => $r instanceof PulseExceptions));
        $this->assertFalse($recorders->contains(fn (object $r): bool => $r instanceof PulseExceptionRecorder));
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
