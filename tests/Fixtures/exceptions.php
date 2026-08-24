<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use RuntimeException;

interface BroadMarker {}

interface NarrowMarker extends BroadMarker {}

/** Implements only the narrow one, so linkage order decides what class_implements() returns first. */
class MarkedException extends RuntimeException implements NarrowMarker {}

class MappedFrom extends RuntimeException {}

class MappedTo extends RuntimeException {}
