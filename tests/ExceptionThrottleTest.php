<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class ExceptionThrottleTest extends TestCase
{
    #[Test]
    public function it_registers_itself_without_a_bootstrap_edit(): void
    {
        // The package's whole install story: require it, and the budget applies.
        config(['flood-control.limit' => 2, 'flood-control.window' => 300]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 5);

        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function each_exception_class_gets_its_own_budget(): void
    {
        // One loud failure must not mask a different one.
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        report(new RuntimeException('first'));
        report(new RuntimeException('second'));
        report(new LogicException('other class'));

        $this->assertSame(
            [RuntimeException::class, LogicException::class],
            array_map(fn (Throwable $e): string => $e::class, $this->reported),
        );
    }

    #[Test]
    public function a_per_class_budget_overrides_the_default(): void
    {
        config([
            'flood-control.limit'   => 10,
            'flood-control.window'  => 300,
            'flood-control.classes' => [RuntimeException::class => ['limit' => 1, 'window' => 300]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);
        $this->reportTimes(LogicException::class, 3);

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_per_class_budget_falls_back_to_the_default_window(): void
    {
        config([
            'flood-control.limit'   => 10,
            'flood-control.window'  => 300,
            'flood-control.classes' => [RuntimeException::class => ['limit' => 1]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_parent_class_entry_covers_its_subclasses(): void
    {
        config([
            'flood-control.limit'   => 10,
            'flood-control.window'  => 300,
            'flood-control.classes' => [LogicException::class => ['limit' => 1]],
        ]);
        $this->captureReports();

        // Each subclass keys its own bucket, but both inherit the limit of 1.
        $this->reportTimes(DomainException::class, 3);
        $this->reportTimes(InvalidArgumentException::class, 3);

        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function an_interface_entry_covers_everything_under_it(): void
    {
        config([
            'flood-control.limit'   => 10,
            'flood-control.window'  => 300,
            'flood-control.classes' => [Throwable::class => ['limit' => 1]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function the_most_specific_entry_wins(): void
    {
        config([
            'flood-control.limit'   => 1,
            'flood-control.window'  => 300,
            'flood-control.classes' => [
                Throwable::class       => ['limit' => 1],
                LogicException::class  => ['limit' => 2],
                DomainException::class => ['limit' => 3],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(DomainException::class, 5);

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function disabling_the_package_stops_it_throttling_anything(): void
    {
        config(['flood-control.enabled' => false, 'flood-control.limit' => 1]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_missing_budget_fails_open_rather_than_silencing_everything(): void
    {
        // An empty env var reads as 0. Failing closed here would lose every error in production
        // with nothing to show for it, so an unusable budget means no budget.
        config(['flood-control.limit' => 0, 'flood-control.window' => 0]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }

    /** Fresh instances: throwables are uncloneable, and a repeat report needs a distinct one. */
    private function reportTimes(string $class, int $times): void
    {
        foreach (range(1, $times) as $i) {
            report(new $class("boom {$i}"));
        }
    }
}
