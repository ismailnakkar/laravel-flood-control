<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\FloodControlServiceProvider;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * A scalar config type-checks nothing, so a typo'd class or key would silently fall back to the
 * default budget — a throttle you configured and never got.
 */
class ConfigValidationTest extends Orchestra
{
    /** @return array<string, array{mixed, string}> */
    public static function badBudgets(): array
    {
        return [
            'class that does not exist'   => [['App\\Nope' => ['limit' => 1]], 'not a class or interface'],
            'budget that is not an array' => [[RuntimeException::class => 5], "expected ['limit' => int"],
            'missing limit'               => [[RuntimeException::class => ['window' => 60]], "expected ['limit' => int"],
            'limit that is not an int'    => [[RuntimeException::class => ['limit' => '5']], "expected ['limit' => int"],
            'window that is not an int'   => [[RuntimeException::class => ['limit' => 1, 'window' => '60']], "'window' must be an int"],
            'misspelled key'              => [[RuntimeException::class => ['limit' => 1, 'seconds' => 60]], 'unknown key(s) seconds'],
        ];
    }

    #[Test]
    #[DataProvider('badBudgets')]
    public function it_refuses_a_malformed_budget(mixed $classes, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        $this->refreshApplicationWith($classes);
    }

    #[Test]
    public function it_accepts_a_well_formed_budget(): void
    {
        $this->refreshApplicationWith([
            RuntimeException::class => ['limit' => 1, 'window' => 60],
            Throwable::class        => ['limit' => 20],
        ]);

        $this->assertTrue($this->app->isBooted());
    }

    private function refreshApplicationWith(mixed $classes): void
    {
        $this->app = $this->createApplication();
        $this->app['config']->set('flood-control.classes', $classes);

        (new FloodControlServiceProvider($this->app))->boot();
    }

    protected function getPackageProviders($app): array
    {
        return [FloodControlServiceProvider::class];
    }
}
