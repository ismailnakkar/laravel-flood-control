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
'enabled' => env('FLOOD_CONTROL_ENABLED', true),   // false stops exception throttling
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

### A message that needs an issue

Not every alert starts life as a throwable:

```php
Report::error('Stripe webhook signature verification failed', ['gateway' => 'stripe']);
```

It goes through the gate like any other report and writes the same log line `report()` would have
written. The bucket is the **call site**, not the exception type, so one noisy caller cannot spend
another's budget — and a `previous` is evidence, not identity, so it does not move the bucket either:

```php
Report::error('Feed fetch failed', ['feed' => $id], previous: $e);
```

Chaining beats flattening the cause into context: the sink keeps its stack trace. To budget these
together, name the type in `classes`:

```php
\FloodControl\OperationalError::class => ['limit' => 3, 'window' => 600],
```

Subclass `OperationalError` when a subsystem deserves its own name and grouping in the sink, and
report that with `Report::exception()`. The `classes` entry above still covers it — entries match
subtypes — but the bucket becomes the class rather than the call site.

For narration that only ever belongs in the file, use `LogThrottle::once()` instead.

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
process. A `null` or sub-1 window never throttles, and a cache failure lets the line through. This
half reads no `flood-control` config — the window is the one you pass, and `FLOOD_CONTROL_ENABLED`
does not reach it.

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

Sentry, Flare, Bugsnag and Rollbar are `reportable`s, so they already sit behind the gate and need
nothing from this package. Their **log channel** is a different hookup, and the difference matters:

| Hookup | Catches | Behind the gate? |
| --- | --- | --- |
| Reportable — `Integration::handles($exceptions)` | reported throwables | yes |
| Log channel in `LOG_STACK` | reported throwables **and every log line at that channel's level** | no |

Wire both and every exception arrives twice: a reportable returning `void` does not stop the chain —
the handler short-circuits only on `=== false` — so `report()` still falls through to the log write.

**Prefer the reportable.** The log channel looks like the wider net, and that is exactly the trap: the
log lines it carries never pass through `report()`, so the gate never sees them. One `Log::info()` on
a hot path becomes one issue per request. Worse, if the channel declares no `level` of its own,
sentry-laravel's handler defaults to `DEBUG` and takes everything you log.

To keep both — exceptions through the reportable, plain log lines through the channel — give the
channel a level and stop it handling exceptions:

```php
'sentry' => [
    'driver'            => 'sentry',
    'level'             => env('SENTRY_CHANNEL_LEVEL', 'error'),
    'report_exceptions' => false,
],
```

Note what that second key does: it drops the **whole record** when it carries an `exception` key, so
`Log::error($msg, ['exception' => $e])` silently stops reaching the sink. Route those through
`Report::exception()` or `Report::error()` instead — both go through the gate.

## Testing

```bash
composer test     # phpunit
composer check    # pint --test, then phpunit
```

`LogThrottle::once()` resolves through `Log::driver()`, which a bare `Log::spy()` stubs to `null`:

```php
Log::spy()->shouldReceive('driver')->andReturnSelf();
```
