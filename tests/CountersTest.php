<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Tests\Fixtures\BrokenCounter;
use FloodControl\Tests\Fixtures\RecordingCounter;
use FloodControl\Tests\Fixtures\ReportingCounter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** config `counters`: the app's own way to see everything, without a bootstrap/app.php edit. */
class CountersTest extends TestCase
{
    /** @var list<class-string> */
    private array $counters = [];

    private RecordingCounter $counter;

    private ReportingCounter $reporter;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);
        $app['config']->set('flood-control.counters', $this->counters);

        $app->instance(RecordingCounter::class, $this->counter = new RecordingCounter);
        $app->instance(ReportingCounter::class, $this->reporter = new ReportingCounter);
    }

    protected function setUp(): void
    {
        $this->counters = [RecordingCounter::class];

        parent::setUp();
    }

    #[Test]
    public function a_counter_sees_every_exception_and_the_gate_still_throttles(): void
    {
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertSame(['boom 1', 'boom 2', 'boom 3', 'boom 4'], $this->counter->seen);
        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_throwing_counter_does_not_disable_the_gate(): void
    {
        // The handler rescues a throwing throttle callback into "do not throttle", so an unguarded
        // throw would turn one broken counter into a kill switch for the whole package.
        $this->counters = [BrokenCounter::class, RecordingCounter::class];
        $this->refreshApplication();
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(4, $this->counter->seen);
        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function counters_still_run_with_the_package_disabled(): void
    {
        // `enabled` governs throttling, not counting — same as the Pulse counter.
        config(['flood-control.enabled' => false]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(3, $this->counter->seen);
        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function a_counter_that_reports_does_not_recurse(): void
    {
        // Counters run in front of the gate, so the gate can never stop a nested report.
        $this->counters = [ReportingCounter::class];
        $this->refreshApplication();
        config(['flood-control.limit' => 100]);
        $this->captureReports();

        report(new RuntimeException('boom'));

        $this->assertSame(1, $this->reporter->maxDepth);
        $this->assertSame(1, $this->reporter->calls);

        // The nested report is not swallowed, only uncounted.
        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function no_counters_registers_nothing(): void
    {
        $this->counters = [];
        $this->refreshApplication();
        $this->captureReports();

        report(new RuntimeException('boom'));

        $this->assertSame([], $this->counter->seen);
        $this->assertCount(1, $this->reported);
    }
}
