<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Runs only in the CI job that uninstalls laravel/pulse. Pulse is a suggestion, and an app that
 * does not want it must get a package that quietly does nothing about counting.
 */
class WithoutPulseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (self::hasPulse()) {
            $this->markTestSkipped('laravel/pulse is installed.');
        }
    }

    #[Test]
    public function the_throttle_works_with_no_pulse_installed(): void
    {
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function about_reports_the_counter_as_off(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->artisan('about --only=flood_control');

        $output = $this->app[Kernel::class]->output();

        $this->assertStringContainsString('Pulse counter', $output);
        $this->assertStringNotContainsString('replaces Pulse recorder', $output);
    }
}
