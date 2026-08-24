<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use Illuminate\Support\Collection;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;

/** Pulse's write seam, so a test can count what really reached it without a database. */
class CapturingIngest implements Ingest
{
    /** @var list<Entry> */
    public array $entries = [];

    /** @param  Collection<int, Entry>  $items */
    public function ingest(Collection $items): void
    {
        foreach ($items as $item) {
            $this->entries[] = $item;
        }
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void {}
}
