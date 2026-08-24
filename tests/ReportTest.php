<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Report;
use FloodControl\Tests\Fixtures\MappedFrom;
use FloodControl\Tests\Fixtures\MappedTo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Context;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class ReportTest extends TestCase
{
    #[Test]
    public function it_adds_context_for_that_report_only(): void
    {
        $seen = null;
        app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$seen): bool {
            $seen = Context::all();

            return false;
        });

        Report::exception(new RuntimeException('boom'), ['analyzer' => 'NetworkAnalyzer']);

        $this->assertSame(['analyzer' => 'NetworkAnalyzer'], $seen);
        $this->assertSame([], Context::all(), 'context must be restored, or a loop leaks it');
    }

    #[Test]
    public function it_restores_context_even_when_reporting_throws(): void
    {
        Context::add('outer', 'kept');

        app(ExceptionHandler::class)->reportable(function (Throwable $e): never {
            throw new RuntimeException('reporting blew up');
        });

        try {
            Report::exception(new RuntimeException('boom'), ['inner' => 'scoped']);
        } catch (RuntimeException) {
            // The handler rethrows; what matters is what context looks like afterwards.
        }

        $this->assertSame(['outer' => 'kept'], Context::all());
    }

    #[Test]
    public function it_can_tighten_the_limit_for_one_exception(): void
    {
        config(['flood-control.limit' => 10, 'flood-control.window' => 300]);
        $this->captureReports();

        Report::exception(new RuntimeException('first'), limit: new Limit(maxAttempts: 1, decaySeconds: 300));
        Report::exception(new RuntimeException('second'), limit: new Limit(maxAttempts: 1, decaySeconds: 300));

        // Same class, so the same bucket — the per-call limit shrank it from the configured 10.
        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function it_can_widen_the_limit_for_one_exception(): void
    {
        config(['flood-control.limit' => 1, 'flood-control.window' => 300]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            Report::exception(new RuntimeException("boom {$i}"), limit: new Limit(maxAttempts: 3, decaySeconds: 300));
        }

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function an_override_changes_the_ceiling_for_that_call_not_the_bucket(): void
    {
        config(['flood-control.limit' => 10, 'flood-control.window' => 300]);
        $this->captureReports();

        Report::exception(new RuntimeException('overridden'), limit: new Limit(maxAttempts: 1, decaySeconds: 300));
        report(new RuntimeException('plain'));

        // Each call is judged against its own ceiling, so an override does not reserve the bucket.
        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function a_non_positive_per_call_limit_means_no_limit(): void
    {
        // Same rule as the config path: a 0 from an empty value must not silence a class.
        config(['flood-control.limit' => 10, 'flood-control.window' => 300]);
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            Report::exception(new RuntimeException("boom {$i}"), limit: new Limit(maxAttempts: 0, decaySeconds: 300));
        }

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_per_call_window_is_used_instead_of_the_configured_one(): void
    {
        // The configured window is unusable, so only the per-call one can produce a real budget.
        config(['flood-control.limit' => 10, 'flood-control.window' => 0]);
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            Report::exception(new RuntimeException("boom {$i}"), limit: new Limit(maxAttempts: 1, decaySeconds: 600));
        }

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function the_kill_switch_beats_a_per_call_override(): void
    {
        // The switch is flipped mid-incident to see everything.
        config(['flood-control.enabled' => false]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            Report::exception(new RuntimeException("boom {$i}"), limit: new Limit(maxAttempts: 1, decaySeconds: 300));
        }

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function an_override_is_spent_by_the_report_it_was_set_on(): void
    {
        // Otherwise it lingers on the instance, and re-reporting a caught exception is ordinary.
        config(['flood-control.limit' => 10, 'flood-control.window' => 300]);
        $this->captureReports();

        $e = new RuntimeException('boom');
        Report::exception($e, limit: new Limit(maxAttempts: 1, decaySeconds: 300));
        report($e);

        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function an_override_survives_exception_mapping(): void
    {
        // $exceptions->map() replaces the throwable before shouldntReport() runs, so a lookup keyed
        // on the instance the call site held would miss and silently fall back to the config limit.
        config(['flood-control.limit' => 10, 'flood-control.window' => 300]);
        app(ExceptionHandler::class)->map(
            fn (MappedFrom $e): MappedTo => new MappedTo('mapped', 0, $e),
        );
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            Report::exception(new MappedFrom("boom {$i}"), limit: new Limit(maxAttempts: 1, decaySeconds: 300));
        }

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(MappedTo::class, $this->reported[0]);
    }

    #[Test]
    public function a_limit_with_a_key_picks_its_own_bucket(): void
    {
        // Limit::by() is the only way to throttle across classes or per tenant, and rebuilding the
        // Limit from maxAttempts/decaySeconds alone would drop it back to the per-class bucket.
        config(['flood-control.limit' => 10, 'flood-control.window' => 300]);
        $this->captureReports();

        Report::exception(new RuntimeException('first'), limit: Limit::perHour(1)->by('tenant:7'));
        Report::exception(new LogicException('different class, same bucket'), limit: Limit::perHour(1)->by('tenant:7'));

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function without_context_or_a_limit_it_is_just_report(): void
    {
        config(['flood-control.limit' => 2, 'flood-control.window' => 300]);
        $this->captureReports();

        foreach (range(1, 4) as $i) {
            Report::exception(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(2, $this->reported);
    }
}
