<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\FloodControlServiceProvider;
use FloodControl\ThrottleConfig;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Touches no config: an app that never published one must still get the shipped budget. */
class DefaultsTest extends TestCase
{
    #[Test]
    public function the_shipped_defaults_apply_without_publishing_the_config(): void
    {
        $this->captureReports();

        foreach (range(1, 15) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(10, $this->reported);
    }

    #[Test]
    public function the_shipped_window_is_read_from_the_package_config(): void
    {
        $this->assertSame(
            ['limit' => 10, 'window' => 300],
            app(ThrottleConfig::class)->for(new RuntimeException('boom')),
        );
    }

    #[Test]
    public function an_absent_config_key_falls_back_to_the_shipped_budget(): void
    {
        // A config cache written before the package was installed makes mergeConfigFrom() a no-op,
        // and a fallback of 0 would read as "no limit" — the package silently off.
        config(['flood-control' => null]);

        $this->assertSame(
            ['limit' => 10, 'window' => 300],
            app(ThrottleConfig::class)->for(new RuntimeException('boom')),
        );

        // The other half of the same fallback: a default of false would be the package off, silently.
        $this->assertTrue(app(ThrottleConfig::class)->enabled());
    }

    #[Test]
    public function artisan_about_reports_the_budget_that_is_actually_in_force(): void
    {
        // Reading the raw keys here would print `OFF` and `0 per 0s` while the gate throttles at 10.
        config(['flood-control' => null]);

        $this->withoutMockingConsoleOutput();
        $this->artisan('about --only=flood_control');

        $output = $this->app[Kernel::class]->output();

        $this->assertStringContainsString('10 per 300s', $output);
        $this->assertStringContainsString('ENABLED', $output);
    }

    #[Test]
    public function auto_discovery_points_at_a_provider_that_exists(): void
    {
        // Testbench registers the provider by hand, so nothing else exercises the install story.
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            [FloodControlServiceProvider::class],
            $composer['extra']['laravel']['providers'] ?? null,
        );
    }
}
