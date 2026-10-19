<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Budget;
use FloodControl\OperationalError;
use FloodControl\Report;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class ReportErrorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('flood-control.limit', 1);
        $app['config']->set('flood-control.window', 300);
    }

    /** Separate call sites: the file and line are the bucket. */
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
    public function it_still_reports_when_the_limiter_store_throws(): void
    {
        $this->captureReports();
        $this->app->bind(RateLimiter::class, function (): RateLimiter {
            $limiter = $this->createStub(RateLimiter::class);
            $limiter->method('attempt')->willThrowException(new RuntimeException('redis is down'));
            $limiter->method('tooManyAttempts')->willThrowException(new RuntimeException('redis is down'));

            return $limiter;
        });

        Report::error('the feed is stale');

        $this->assertCount(1, $this->reported);
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
    public function an_explicit_budget_sets_this_lines_own_pace(): void
    {
        // The configured budget is 1 per 300s.
        $this->captureReports();

        foreach (range(1, 6) as $ignored) {
            $this->atOwnPace();
        }

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function an_explicit_budget_changes_the_pace_but_not_the_bucket(): void
    {
        $this->captureReports();

        foreach (range(1, 6) as $ignored) {
            $this->atOwnPace();
        }

        $this->fromFirstSite();

        $this->assertSame(
            ['own pace', 'own pace', 'own pace', 'first site'],
            array_map(static fn (Throwable $e): string => $e->getMessage(), $this->reported),
        );
    }

    private function atOwnPace(): void
    {
        Report::error('own pace', budget: Budget::of(3, 300));
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
