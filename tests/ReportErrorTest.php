<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\OperationalError;
use FloodControl\Report;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/** `Report::error()`: a message that deserves an issue, bucketed per call site. */
class ReportErrorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);
    }

    /** Two call sites, deliberately on their own lines — the line number is the bucket. */
    private function fromFirstSite(): void
    {
        Report::error('first site');
    }

    private function fromSecondSite(): void
    {
        Report::error('second site');
    }

    #[Test]
    public function it_reports_the_message_as_a_throwable(): void
    {
        $this->captureReports();

        Report::error('the feed is stale');

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(OperationalError::class, $this->reported[0]);
        $this->assertSame('the feed is stale', $this->reported[0]->getMessage());
    }

    #[Test]
    public function one_call_site_shares_one_budget(): void
    {
        $this->captureReports();

        foreach (range(1, 5) as $ignored) {
            $this->fromFirstSite();
        }

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function separate_call_sites_do_not_spend_each_others_budget(): void
    {
        // The whole point of keying on the call site: bucketing on OperationalError instead would
        // let whichever site fires first silence every other one.
        $this->captureReports();

        foreach (range(1, 5) as $ignored) {
            $this->fromFirstSite();
        }

        foreach (range(1, 5) as $ignored) {
            $this->fromSecondSite();
        }

        $this->assertSame(
            ['first site', 'second site'],
            array_map(static fn (Throwable $e): string => $e->getMessage(), $this->reported),
        );
    }

    #[Test]
    public function a_cause_is_chained_rather_than_flattened_into_the_message(): void
    {
        $this->captureReports();

        $cause = new RuntimeException('connect() timed out');
        Report::error('Feed fetch failed', previous: $cause);

        $this->assertSame('Feed fetch failed', $this->reported[0]->getMessage());
        $this->assertSame($cause, $this->reported[0]->getPrevious());
    }

    #[Test]
    public function a_cause_does_not_move_the_bucket_off_the_call_site(): void
    {
        // The chained throwable is evidence, not identity: two causes from one site still share
        // a budget, or a varying cause would mint a bucket per occurrence.
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            $this->withCause(new RuntimeException("attempt {$i}"));
        }

        $this->assertCount(1, $this->reported);
    }

    private function withCause(Throwable $cause): void
    {
        Report::error('upstream unreachable', previous: $cause);
    }

    #[Test]
    public function it_scopes_context_onto_the_report_and_restores_it(): void
    {
        $seen = null;
        app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$seen): bool {
            $seen = Context::all();

            return false;
        });

        Report::error('origin denied', ['origin' => 'example.test']);

        $this->assertSame(['origin' => 'example.test'], $seen);
        $this->assertSame([], Context::all());
    }

    #[Test]
    public function a_classes_entry_budgets_it_like_any_other_throwable(): void
    {
        config(['flood-control.classes' => [OperationalError::class => ['limit' => 3, 'window' => 300]]]);
        $this->captureReports();

        foreach (range(1, 6) as $ignored) {
            $this->fromFirstSite();
        }

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function the_gate_being_off_reports_every_one(): void
    {
        config(['flood-control.enabled' => false]);
        $this->captureReports();

        foreach (range(1, 4) as $ignored) {
            $this->fromFirstSite();
        }

        $this->assertCount(4, $this->reported);
    }
}
