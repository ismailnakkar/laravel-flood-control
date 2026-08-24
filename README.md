# laravel-flood-control

Rate-limit reported exceptions and repeated log lines without going blind — Laravel Pulse still counts
every one.

Requires PHP 8.2 and Laravel 12.1 or newer.

## Install

```bash
composer require ismailnakkar/laravel-flood-control
```

That is the whole install: no `bootstrap/app.php` edit, no change at the call site. Defaults to 10
reports per exception class per 5 minutes.

```bash
php artisan vendor:publish --tag=flood-control-config   # to change them
```

## Exceptions

```php
// config/flood-control.php
'enabled' => env('FLOOD_CONTROL_ENABLED', true),   // false stops all throttling
'limit'   => env('FLOOD_CONTROL_LIMIT', 10),
'window'  => env('FLOOD_CONTROL_WINDOW', 300),

'classes' => [
    \Illuminate\Database\QueryException::class => ['limit' => 1, 'window' => 3600],
    \Throwable::class                          => ['limit' => 20],   // catch-all: last
],
```

- An entry covers everything under it, class or interface, and a subtype beats its supertype.
  Unrelated entries are tried in the order written, so **put a catch-all last**.
- An entry sets the budget, not the bucket. The bucket is always the concrete class, so a catch-all of
  20 gives each subclass 20 of its own.
- A limit or window below 1 means *no limit*, not *never report*. To silence a class, use
  `$exceptions->dontReport()`.
- Budgets are arrays, not `Limit` objects — `config:cache` writes config with `var_export()`.
  Malformed entries throw at boot, naming the entry; the check is skipped once config is cached.

### Per call

```php
use FloodControl\Report;
use Illuminate\Cache\RateLimiting\Limit;

Report::exception($e, ['analyzer' => $analyzer::class]);
Report::exception($e, limit: Limit::perHour(1));
Report::exception($e, limit: Limit::perMinute(5)->by("tenant:{$tenant->id}"));
```

Context goes through `Context::scope()`, so it reaches the log record and the Sentry event and is
restored afterwards. A per-call `limit` caps that one report without reserving the class bucket; it is
spent when the report reaches the throttle, and it survives `$exceptions->map()`. `by()` replaces the
bucket, which is how you throttle per tenant. With neither argument, use `report($e)`.

## Log lines

For narration that is not an exception — a denied origin, a circuit opening, a feed going stale:

```php
use FloodControl\LogThrottle;

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed', ['origin' => $origin]);
LogThrottle::once('feed-stale', 900, ['single', 'slack'])->warning('Feed is stale');
```

```php
once(string $key, ?int $seconds, LoggerInterface|UnitEnum|array|string|null $channel = null)
```

The first call in the window returns the real logger, the rest return one that discards. Both are an
`Illuminate\Log\Logger`, so every PSR level, `withContext()` and array messages work either way — only
`listen()` differs, which throws on the discarding one. A discarded line fires no `MessageLogged`, so
it stays out of Telescope and Sentry breadcrumbs too.

`$channel` takes anything the log manager does: a channel or stack name, an array for an on-demand
stack, an enum, a logger you already hold, or null for the default.

The gate is `Cache::add()` on the `cache.limiter` store, so it holds across workers rather than per
process. A `null` or sub-1 window never throttles, and a cache failure lets the line through.

**The key is yours to pick** — a log line has no class to key on. Keep it a literal or a code-owned
value; a key built from client-supplied text is unbounded cache cardinality.

## Keeping a rate signal

The gate runs inside `shouldntReport()`, ahead of every `reportable`. Anything counting exceptions from
a `reportable` therefore counts what survived the throttle, not what happened.

**Pulse is handled for you.** The package binds Pulse's `Exceptions` recorder to one that counts in
front of the gate instead of behind it. Recording is still Pulse's own recorder, so the card, your
`ignore`, `sample_rate` and `location` settings, and `Pulse::report()` are unchanged. No Pulse config
is written and Pulse's own off switches still win. `php artisan about` shows the swap, and
`FLOOD_CONTROL_PULSE=false` turns it off. With `laravel/pulse` absent nothing is registered at all.

**Your own counters go in config:**

```php
// config/flood-control.php
'counters' => [\App\Reporting\CountExceptions::class],
```

```php
class CountExceptions
{
    public function __invoke(Throwable $e): void
    {
        Metrics::increment('exceptions', $e::class);
    }
}
```

Each is resolved from the container per exception and sees everything, throttled or not. Throws are
swallowed, a counter that reports is skipped rather than recursing, and a typo'd or non-invokable class
throws at boot.

Counting hangs off `dontReportWhen()`, which `shouldntReport()` runs ahead of the whole throttle chain
— so your own `$exceptions->throttle()` can return a `Limit` and replace the gate outright without
costing you the rate signal.

The rule: a **counter** wants the true rate, so it goes here. A **sink** wants fewer events, so it
stays a `reportable` and gets the throttled stream, which needs no configuration at all.

## Wiring a sink

Sentry, Flare, Bugsnag and Rollbar need nothing from this package — they are `reportable`s, already
behind the gate. They do need **exactly one hookup**, because wiring both sends everything twice:

| Hookup | Where | Catches |
| --- | --- | --- |
| Reportable | `Integration::handles($exceptions)` in `bootstrap/app.php` | reported throwables |
| Log channel | the sink's channel in `LOG_STACK` | reported throwables **and** `Log::error(..., ['exception' => $e])` |

Pick the log channel: it is the wider net and needs no `bootstrap/app.php` edit. Both fire when you
wire both because a reportable returning `void` does not stop the chain — the handler short-circuits
only on `=== false` — so `report()` still falls through to the log write.

## Testing

```bash
composer test     # phpunit
composer check    # pint --test, then phpunit
```

`LogThrottle::once()` resolves through `Log::driver()`, which a bare `Log::spy()` stubs to `null`:

```php
Log::spy()->shouldReceive('driver')->andReturnSelf();
```
