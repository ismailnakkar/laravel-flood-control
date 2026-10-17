<?php

declare(strict_types=1);

namespace FloodControl;

use Laravel\Pulse\Events\ExceptionReported;

/**
 * @internal
 *
 * Pulse::report() reaches the recorder through this event, never the handler, so the gate has no say:
 * the stock recorder's listener, which went with the swap.
 */
final class PulseReportListener
{
    public function __invoke(ExceptionReported $event): void
    {
        PulseExceptionRecorder::countAlways($event->exception);
    }
}
