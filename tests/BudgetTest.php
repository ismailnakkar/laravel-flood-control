<?php

declare(strict_types=1);

namespace FloodControl\Tests;

use FloodControl\Budget;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use PHPUnit\Framework\Attributes\Test;

class BudgetTest extends TestCase
{
    #[Test]
    public function the_named_constructors_say_what_they_mean(): void
    {
        $this->assertSame([1, 60], [Budget::perMinute()->times, Budget::perMinute()->seconds]);
        $this->assertSame([3, 3600], [Budget::perHour(3)->times, Budget::perHour(3)->seconds]);
        $this->assertSame([5, 86400], [Budget::perDay(5)->times, Budget::perDay(5)->seconds]);
    }

    #[Test]
    public function a_sub_one_side_means_no_limit_rather_than_never(): void
    {
        $this->assertTrue(Budget::of(0, 300)->isUnlimited());
        $this->assertTrue(Budget::of(10, 0)->isUnlimited());
        $this->assertTrue(Budget::of(-1, -1)->isUnlimited());
        $this->assertTrue(Budget::unlimited()->isUnlimited());

        $this->assertFalse(Budget::of(1, 1)->isUnlimited());
    }

    #[Test]
    public function an_unlimited_budget_becomes_a_limit_the_handler_ignores(): void
    {
        $this->assertInstanceOf(Unlimited::class, Budget::unlimited()->toLimit());
        $this->assertInstanceOf(Unlimited::class, Budget::of(0, 300)->toLimit('a-key'));
    }

    #[Test]
    public function a_key_becomes_the_bucket_and_no_key_leaves_it_to_the_handler(): void
    {
        $keyed = Budget::of(3, 600)->toLimit('flood-control:error:abc');
        $this->assertSame('flood-control:error:abc', $keyed->key);
        $this->assertSame(3, $keyed->maxAttempts);
        $this->assertSame(600, $keyed->decaySeconds);

        $this->assertSame('', Budget::of(3, 600)->toLimit()->key);
        $this->assertInstanceOf(Limit::class, Budget::of(3, 600)->toLimit());
    }

    #[Test]
    public function it_survives_the_round_trip_config_cache_performs(): void
    {
        // As config:cache does: var_export() to a file, then require() it.
        $exported = var_export(['classes' => ['App\\Foo' => Budget::perHour(7)]], true);

        $file = tempnam(sys_get_temp_dir(), 'fc') . '.php';
        file_put_contents($file, '<?php return ' . $exported . ';');

        try {
            $restored = require $file;
        } finally {
            @unlink($file);
        }

        $this->assertEquals(Budget::perHour(7), $restored['classes']['App\\Foo']);
    }

    #[Test]
    public function a_config_entry_fills_absent_keys_from_the_default(): void
    {
        $default = Budget::of(10, 300);

        $this->assertEquals(Budget::of(3, 600), Budget::fromConfig(['limit' => 3, 'window' => 600], $default));
        $this->assertEquals(Budget::of(3, 300), Budget::fromConfig(['limit' => 3], $default));
        $this->assertEquals(Budget::of(10, 600), Budget::fromConfig(['window' => 600], $default));
        $this->assertEquals($default, Budget::fromConfig([], $default));
    }
}
