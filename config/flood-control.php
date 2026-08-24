<?php

declare(strict_types=1);

return [

    /*
     * Master switch for the exception gate. Off, no report is throttled — per-call
     * Report::exception() limits included. The Pulse counter below is unaffected, and so is
     * LogThrottle::once(), which carries its own window per call and reads nothing from here.
     */
    'enabled' => env('FLOOD_CONTROL_ENABLED', true),

    /*
     * On (with laravel/pulse installed): counts every reported exception in front of the gate, so
     * the exceptions card keeps showing the real rate. Pulse counts from a `reportable`, which runs
     * behind the gate; only that hook is replaced. Recording is still Pulse's own recorder, so its
     * ignore, sample_rate and location settings apply as written. Off, Pulse counts survivors only.
     */
    'pulse' => env('FLOOD_CONTROL_PULSE', true),

    /*
     * Your own counters: classes that see every reported exception, in front of the gate, so a rate
     * signal stays true while the reports themselves are throttled. Each is resolved from the
     * container per exception and invoked with the throwable. Return values are ignored and throws are swallowed,
     * because a throw here would read as "do not throttle".
     *
     *   \App\Reporting\CountExceptions::class,
     *
     * A counter that reports is not counted again on the nested report: counters run in front of
     * the gate, so nothing else could stop the recursion.
     *
     * Anything that should be throttled — Sentry, Flare, a log write — belongs in a `reportable`
     * instead. Those already sit behind the gate, which is the whole point of it.
     */
    'counters' => [],

    /*
     * The default budget: this many reports per exception class per window, in seconds.
     */
    'limit'  => env('FLOOD_CONTROL_LIMIT', 10),
    'window' => env('FLOOD_CONTROL_WINDOW', 300),

    /*
     * Per-class budgets. An entry covers everything under it, class or interface, and a subtype
     * beats its supertype. Entries with no subtype relation are tried in the order written, so a
     * catch-all like `\Throwable::class` belongs last.
     *
     *   \Illuminate\Database\QueryException::class => ['limit' => 1, 'window' => 3600],
     *   \Throwable::class                          => ['limit' => 20],
     *
     * An entry sets the budget, not the bucket: the bucket is always the concrete exception class,
     * so a catch-all of 20 gives each subclass 20. Both keys are optional and fall back to the
     * defaults above.
     *
     * Arrays, not Limit objects: `config:cache` writes this file with var_export(), and Limit has
     * no __set_state(), so an object here fails the cache step with a LogicException. Per call,
     * Report::exception() takes a real Limit.
     *
     * A limit or window below 1 means "no limit", not "never report". To silence a class, use
     * `$exceptions->dontReport()`.
     */
    'classes' => [
        //
    ],

];
