<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use DomainException;
use Exception;
use FloodControl\Tests\Fixtures\MarkedException;
use FloodControl\Tests\Fixtures\NarrowMarker;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class ClassResolutionTest extends TestCase
{
    #[Test]
    public function an_interface_entry_is_matched_regardless_of_linkage_order(): void
    {
        // class_implements(MarkedException) returns Throwable and Stringable BEFORE NarrowMarker,
        // so anything that trusts array order gives the catch-all entry the win no matter how the
        // config is written. NarrowMarker and Throwable are unrelated, so declaration order decides.
        config([
            'flood-control.window'  => 300,
            'flood-control.classes' => [
                NarrowMarker::class => ['limit' => 1],
                Throwable::class    => ['limit' => 20],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(MarkedException::class, 5);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function the_nearest_parent_wins_over_a_grandparent(): void
    {
        config([
            'flood-control.window'  => 300,
            'flood-control.classes' => [
                Exception::class      => ['limit' => 5],
                LogicException::class => ['limit' => 1],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(DomainException::class, 5);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_class_entry_beats_an_interface_entry(): void
    {
        config([
            'flood-control.window'  => 300,
            'flood-control.classes' => [
                Throwable::class        => ['limit' => 1],
                RuntimeException::class => ['limit' => 3],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 5);

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function a_per_class_window_overrides_the_default_window(): void
    {
        // Default window is unusable, so only a per-class window can produce a working budget.
        config([
            'flood-control.limit'   => 10,
            'flood-control.window'  => 0,
            'flood-control.classes' => [RuntimeException::class => ['limit' => 1, 'window' => 600]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_zero_limit_fails_open(): void
    {
        config(['flood-control.limit' => 0, 'flood-control.window' => 300]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_zero_window_fails_open(): void
    {
        config(['flood-control.limit' => 2, 'flood-control.window' => 0]);
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
