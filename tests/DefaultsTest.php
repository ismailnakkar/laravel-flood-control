<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\FloodControlServiceProvider;
use FloodControl\ThrottleConfig;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Touches no config at all. Without this, dropping mergeConfigFrom() leaves the suite green while
 * turning the package into a no-op in any app that never published the config.
 */
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
