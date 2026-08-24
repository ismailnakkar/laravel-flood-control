<?php

declare(strict_types=1);

return [

    /*
     * Off means every reported exception is narrated. The counter you register alongside the
     * throttle is unaffected either way — see the README.
     */
    'enabled' => (bool)env('FLOOD_CONTROL_ENABLED', true),

    /*
     * The unthrottled rate signal.
     *
     * The gate runs inside `shouldntReport()`, ahead of every `reportable` callback — and Laravel
     * Pulse's own Exceptions recorder registers as one. Left to itself its card would count what
     * survived the throttle, not what happened.
     *
     * On (and with laravel/pulse installed) this registers a counter ahead of the gate and turns
     * Pulse's recorder off, emitting the same `exception` type and `[class, location]` key, so the
     * stock Pulse card keeps working and stays honest. Off leaves Pulse's recorder alone, and its
     * numbers then agree with the throttle rather than with reality.
     */
    'pulse' => (bool)env('FLOOD_CONTROL_PULSE', true),

    /*
     * The default budget: this many reports of one exception class per window, in seconds.
     * The key is the exception class, so one loud failure cannot mask a different one.
     */
    'limit'  => (int)env('EXCEPTION_THROTTLE_LIMIT', 10),
    'window' => (int)env('EXCEPTION_THROTTLE_WINDOW', 300),

    /*
     * Per-class budgets, for the ones whose rate says nothing new after the first few. An entry
     * covers everything under it, class or interface, and a subtype always beats its supertype.
     * Entries with no subtype relation between them are tried in the order written, so a catch-all
     * like `\Throwable::class` belongs last.
     *
     *   \Illuminate\Database\QueryException::class => ['limit' => 1, 'window' => 3600],
     *   \Throwable::class                          => ['limit' => 20],
     *
     * Arrays, not Limit objects: `config:cache` writes this file with var_export(), and Limit has
     * no __set_state(), so an object here turns every deploy's cache step into a LogicException.
     * Pass a Limit at the call site instead — Report::exception() takes one.
     *
     * A window is optional and falls back to the default above. A limit below 1 is treated as
     * "no limit", not as "never report" — to silence a class entirely use `$exceptions->dontReport()`,
     * which is the framework's own way to say it and costs nothing to evaluate.
     */
    'classes' => [
        //
    ],

];
