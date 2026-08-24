<?php

declare(strict_types=1);

namespace FloodControl\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * An app's own handler: the contract, plus the reportable() that Pulse and Sentry both call — and no
 * throttleUsing() and no inner handler to unwrap.
 */
class UngateableHandler implements ExceptionHandler
{
    /** @var list<callable> */
    private array $reportables = [];

    public function report(Throwable $e): void
    {
        foreach ($this->reportables as $reportable) {
            $reportable($e);
        }
    }

    public function reportable(callable $reportUsing): void
    {
        $this->reportables[] = $reportUsing;
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e) {}

    public function renderForConsole($output, Throwable $e): void {}
}
