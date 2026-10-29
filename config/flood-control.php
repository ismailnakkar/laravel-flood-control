<?php

declare(strict_types=1);

return [

    /*
     * Exception throttling on or off, per-call Report::exception() limits and the LogThrottle channel
     * tap included. Counting and LogThrottle::once()/times() are unaffected.
     */
    'enabled' => env('FLOOD_CONTROL_ENABLED', true),

    /*
     * With laravel/pulse installed, count every reported exception in front of the gate, so the
     * exceptions card shows the real rate. Off, Pulse counts survivors only. Read per exception.
     */
    'pulse' => env('FLOOD_CONTROL_PULSE', true),

    /*
     * Invokable classes called with every reported exception, throttled or not. Resolved from the
     * container per exception; throws are swallowed, and a report made from a counter is not
     * counted again.
     *
     *   \App\Reporting\CountExceptions::class,
     */
    'counters' => [],

    /*
     * The default budget: this many reports per exception class per window, in seconds.
     */
    'limit'  => env('FLOOD_CONTROL_LIMIT', 10),
    'window' => env('FLOOD_CONTROL_WINDOW', 300),

    /*
     * Per-class budgets. An entry covers its subtypes (class or interface) and the most specific
     * wins; unrelated entries are tried in order, so put a catch-all last. Absent keys fall back to
     * the defaults above, and each concrete class still gets its own bucket.
     *
     *   \Illuminate\Database\QueryException::class => ['limit' => 1, 'window' => 3600],
     *   \Throwable::class                          => ['limit' => 20],
     *
     * Arrays or a FloodControl\Budget, not a Limit: a Limit fails `config:cache`. A limit or window
     * below 1 means no limit; to silence a class, use `$exceptions->dontReport()`.
     */
    'classes' => [
        //
    ],

];
