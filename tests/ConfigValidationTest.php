<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\ThrottleConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * A scalar config type-checks nothing, so a typo'd class or key would silently fall back to the
 * default budget — a throttle you configured and never got.
 */
class ConfigValidationTest extends TestCase
{
    private mixed $classes = [];

    private mixed $counters = [];

    private bool $configIsCached = false;

    /** @return array<string, array{mixed, string}> */
    public static function badBudgets(): array
    {
        return [
            'class that does not exist'   => [['App\\Nope' => ['limit' => 1]], 'not a class or interface'],
            'numeric key'                 => [[['limit' => 1]], 'not a class or interface'],
            'budget that is not an array' => [[RuntimeException::class => 5], "expected ['limit' => ?int"],
            'empty budget'                => [[RuntimeException::class => []], "expected ['limit' => ?int"],
            'limit that is not an int'    => [[RuntimeException::class => ['limit' => '5']], "'limit' must be an int"],
            'window that is not an int'   => [[RuntimeException::class => ['limit' => 1, 'window' => '60']], "'window' must be an int"],
            'misspelled key'              => [[RuntimeException::class => ['limit' => 1, 'seconds' => 60]], 'unknown key(s) seconds'],
        ];
    }

    #[Test]
    #[DataProvider('badBudgets')]
    public function it_refuses_a_malformed_budget(mixed $classes, string $expected): void
    {
        $this->classes = $classes;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        $this->refreshApplication();
    }

    #[Test]
    public function it_accepts_a_well_formed_budget(): void
    {
        $this->classes = [
            RuntimeException::class => ['limit' => 1, 'window' => 60],
            Throwable::class        => ['limit' => 20],
        ];

        $this->refreshApplication();

        $this->assertSame(
            ['limit' => 1, 'window' => 60],
            app(ThrottleConfig::class)->for(new RuntimeException('boom')),
        );
    }

    #[Test]
    public function a_window_only_entry_is_a_valid_shorthand(): void
    {
        // The limit falls back to the default, the way the window already does.
        $this->classes = [RuntimeException::class => ['window' => 3600]];

        $this->refreshApplication();

        $this->assertSame(
            ['limit' => 10, 'window' => 3600],
            app(ThrottleConfig::class)->for(new RuntimeException('boom')),
        );
    }

    /** @return array<string, array{mixed, string}> */
    public static function badCounters(): array
    {
        return [
            'class that does not exist' => [['App\\Nope'], 'not a class or interface that exists'],
            'not a string'              => [[123], 'not a class or interface that exists'],
            'nothing to invoke'         => [[ThrottleConfig::class], 'has no __invoke'],
        ];
    }

    #[Test]
    #[DataProvider('badCounters')]
    public function it_refuses_a_malformed_counter(mixed $counters, string $expected): void
    {
        $this->counters = $counters;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        $this->refreshApplication();
    }

    #[Test]
    public function a_cached_config_skips_the_check(): void
    {
        // The cost lands on config:cache at deploy time, never on a production boot.
        $this->classes = ['App\\Nope' => ['limit' => 1]];
        $this->configIsCached = true;

        $this->refreshApplication();

        if (! $this->app->configurationIsCached()) {
            $this->markTestSkipped('Application::configurationIsCached() reads this binding from Laravel 12.38.');
        }

        $this->assertTrue($this->app->isBooted());
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('flood-control.classes', $this->classes);
        $app['config']->set('flood-control.counters', $this->counters);

        if ($this->configIsCached) {
            // What Application::configurationIsCached() checks before touching the filesystem.
            $app->instance('config_loaded_from_cache', true);
        }
    }
}
