<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use RuntimeException;

/** Implements only the narrow one, so linkage order decides what class_implements() returns first. */
class MarkedException extends RuntimeException implements NarrowMarker {}
