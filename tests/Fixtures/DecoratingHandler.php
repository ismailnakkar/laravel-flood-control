<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/** Like Collision's handler: wraps the real one and has no throttleUsing(). */
class DecoratingHandler implements ExceptionHandler
{
    public function __construct(private ExceptionHandler $inner) {}

    public function report(Throwable $e): void
    {
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }

    public function reportable(callable $reportUsing): mixed
    {
        return $this->inner->reportable($reportUsing);
    }
}
