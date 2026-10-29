<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;
use Monolog\Processor\IntrospectionProcessor;
use Throwable;

/**
 * @internal
 *
 * What the LogThrottle tap wraps each handler of a channel in: lines past the budget, per level and
 * call site, never reach the handler.
 */
final class ThrottledHandler extends HandlerWrapper
{
    /** Frames between the call site and the handler; Monolog's are skipped already. */
    private const SKIP = ['Illuminate\\Log\\', 'Illuminate\\Support\\Facades\\', self::class];

    /** @param  Budget|null  $budget  null reads the top-level limit and window per line. */
    public function __construct(HandlerInterface $handler, private readonly ?Budget $budget)
    {
        parent::__construct($handler);
    }

    public function handle(LogRecord $record): bool
    {
        // Never true for a dropped line: true stops Monolog's loop, so later handlers in a stack
        // would lose it too.
        return $this->passes($record) && $this->handler->handle($record);
    }

    public function handleBatch(array $records): void
    {
        $passing = [];

        // A loop, not array_filter(): a callback frame has no file, which would hide the call site.
        foreach ($records as $record) {
            if ($this->passes($record)) {
                $passing[] = $record;
            }
        }

        if ($passing !== []) {
            $this->handler->handleBatch($passing);
        }
    }

    private function passes(LogRecord $record): bool
    {
        // First: once a record is initialised, Monolog calls handle() without asking isHandling(),
        // so every debug line would cost a cache call.
        if (! $this->handler->isHandling($record)) {
            return false;
        }

        // Every reported exception is logged with one, and the exception gate already limited it.
        if (($record->context['exception'] ?? null) instanceof Throwable) {
            return true;
        }

        try {
            $config = app(ThrottleConfig::class);
            $budget = $this->budget ?? $config->default();

            if (! $config->enabled() || $budget->isUnlimited()) {
                return true;
            }

            // Resolved per line: under Octane a handler outlives the request.
            return app(RateLimiter::class)->attempt(
                $this->key($record),
                $budget->times,
                static fn (): bool => true,
                $budget->seconds,
            );
        } catch (Throwable) {
            // Fail open, as LogThrottle::times() does.
            return true;
        }
    }

    /**
     * The call site, not the message: processors fill placeholders before any handler runs, so a
     * message key is unbounded and never stops a flood.
     */
    private function key(LogRecord $record): string
    {
        // On a clone: the processor writes the call site into extra, which the handler would log.
        $extra = (new IntrospectionProcessor(skipClassesPartials: self::SKIP))(clone $record)->extra;

        $site = isset($extra['file']) ? $extra['file'] . ':' . $extra['line'] : $record->message;

        return 'flood-control:log:' . hash('xxh128', $this->handler::class . '|' . $record->level->name . '|' . $site);
    }
}
