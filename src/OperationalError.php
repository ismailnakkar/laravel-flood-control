<?php

declare(strict_types=1);

namespace FloodControl;

use RuntimeException;

/**
 * The throwable Report::error() reports. Not final: subclass it to give a subsystem its own name in
 * the sink, and report the subclass with Report::exception().
 */
class OperationalError extends RuntimeException {}
