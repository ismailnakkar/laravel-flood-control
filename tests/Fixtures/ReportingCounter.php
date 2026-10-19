<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use RuntimeException;
use Throwable;

/** A counter that reports, as rescue() around a failed metric write would. */
class ReportingCounter
{
    public int $calls = 0;

    public int $maxDepth = 0;

    private int $depth = 0;

    public function __invoke(Throwable $e): void
    {
        $this->calls++;
        $this->depth++;
        $this->maxDepth = max($this->maxDepth, $this->depth);

        // Bounded so a regression fails the test instead of exhausting memory.
        if ($this->depth < 5) {
            report(new RuntimeException('from the counter'));
        }

        $this->depth--;
    }
}
