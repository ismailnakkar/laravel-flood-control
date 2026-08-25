<?php

declare(strict_types=1);

namespace FloodControl;

use RuntimeException;

/**
 * The throwable behind `Report::error()`: a log line that needs a human rather than a file.
 *
 * It carries no cause of its own — the message is the story, and anything underlying is chained as
 * `previous`. `Report::error()` buckets on the call site, not on this class.
 *
 * Not final on purpose. Subclass it to give one subsystem its own name and grouping in the sink,
 * and report that with `Report::exception()`; a single `flood-control.classes` entry for this class
 * still budgets every subclass, because entries match subtypes.
 */
class OperationalError extends RuntimeException {}
