<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Pulse's own off switches. The counter goes through Pulse's registered recorders, so a recorder
 * Pulse did not register counts nothing — no config of Pulse's is read, or written.
 */
class PulseOptOutTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $pulseConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutPulse();
    }

    #[Test]
    public function pulse_disabled_wholesale_stops_the_counter(): void
    {
        $this->pulseConfig = ['pulse.enabled' => false];
        $this->refreshApplication();

        $this->assertSame([], $this->pulseExceptions(fn () => report(new RuntimeException('boom'))));
    }

    #[Test]
    public function a_disabled_recorder_stops_the_counter(): void
    {
        $this->pulseConfig = ['pulse.recorders.' . PulseExceptions::class . '.enabled' => false];
        $this->refreshApplication();

        $this->assertSame([], $this->pulseExceptions(fn () => report(new RuntimeException('boom'))));
    }

    #[Test]
    public function a_recorder_disabled_as_a_bare_false_stops_the_counter(): void
    {
        // Pulse::register() reads `Recorder::class => false` as disabled, not only ['enabled' => false].
        $this->pulseConfig = ['pulse.recorders.' . PulseExceptions::class => false];
        $this->refreshApplication();

        $this->assertSame([], $this->pulseExceptions(fn () => report(new RuntimeException('boom'))));
    }

    protected function defineEnvironment($app): void
    {
        foreach ($this->pulseConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
