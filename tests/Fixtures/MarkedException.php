<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use RuntimeException;

class MarkedException extends RuntimeException implements NarrowMarker {}
