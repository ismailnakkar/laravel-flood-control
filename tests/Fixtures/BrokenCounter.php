<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use RuntimeException;
use Throwable;

class BrokenCounter
{
    public function __invoke(Throwable $e): void
    {
        throw new RuntimeException('the counter is down');
    }
}
