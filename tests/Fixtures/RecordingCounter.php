<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use Throwable;

/** A counter shaped the way the config expects: invokable, one throwable in, nothing out. */
class RecordingCounter
{
    /** @var list<string> */
    public array $seen = [];

    public function __invoke(Throwable $e): void
    {
        $this->seen[] = $e->getMessage();
    }
}
