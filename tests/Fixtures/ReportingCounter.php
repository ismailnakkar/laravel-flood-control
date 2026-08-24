<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use RuntimeException;
use Throwable;

/**
 * A counter that reports. Counters run in front of the gate, so without a re-entrancy guard the
 * gate can never stop the nesting — rescue() reporting a failed metric write is enough to trigger it.
 */
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
