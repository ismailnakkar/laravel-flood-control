# laravel-flood-control

Stop repeated exceptions and repeated log lines from flooding your log and your error tracker.

A flood of the same exception tells you nothing the first few didn't, but it costs a Sentry event and
a synchronous HTTP send every time. This caps reports per exception class per window using the
framework's own `$exceptions->throttle()` hook — so a catch block stays `report($e)` and nothing
about rate, level or destination leaks into the call site — and gives log lines the same treatment
with a key you choose.

Requires PHP 8.5 and Laravel 13.

## Install

```bash
composer require ismailnakkar/laravel-flood-control
```

That is the whole install. The service provider registers the throttle on the exception handler, so
there is no `bootstrap/app.php` edit. Defaults to 10 reports per exception class per 5 minutes.

```bash
php artisan vendor:publish --tag=flood-control-config
```

## Configuration

```php
// config/flood-control.php
'limit'  => 10,
'window' => 300,

'classes' => [
    QueryException::class => ['limit' => 1, 'window' => 3600],
    Throwable::class      => ['limit' => 20],   // catch-all: last
],
```

An entry covers everything under it, class or interface, and a subtype always beats its supertype.
Entries with no subtype relation between them — two unrelated interfaces, or an interface and a
class — are tried in the order written, so **put a catch-all like `Throwable::class` last**.
Declaration order is what decides there; `class_implements()` order is linkage order, not
specificity, and is not consulted.

A limit or window below 1 means *no limit*, not *never report*: an empty env var reads as `0`, and
failing closed there would lose every error in production with nothing to show for it. To silence a
class, use the framework's `$exceptions->dontReport()`. `enabled => false` turns the whole thing off,
per-call overrides included.

Per call, when one site needs something different:

```php
use FloodControl\Report;
use Illuminate\Cache\RateLimiting\Limit;

Report::exception($e, ['analyzer' => $analyzer::class]);
Report::exception($e, limit: Limit::perHour(1));
Report::exception($e, limit: new Limit(maxAttempts: 1, decaySeconds: 300));
```

Context goes through `Context::scope()`, so it reaches the log record and the Sentry event and is
restored afterwards — which matters inside a loop, and when reporting itself throws.

Malformed entries are refused at boot with a message naming the entry — a typo'd FQCN or a
misspelled `window` would otherwise fall back to the default budget in silence, giving you a throttle
you configured and never got. The check is skipped once config is cached, so it costs `config:cache`
at deploy time and every dev and CI boot, and nothing on a production request.

The `classes` config takes arrays rather than `Limit` objects for one reason: `config:cache` writes
config with `var_export()`, and `Limit` has no `__set_state()`, so an object there turns every
deploy's cache step into a `LogicException`. At a call site there is no serialization, so it takes
the real thing.

A per-call `limit` is the ceiling for **that one report**. It is stored in a `WeakMap` keyed by the
throwable and spent when that report is judged, so it does not linger on the instance and a later
`report($e)` of the same object is judged normally. It also does not reserve the bucket: another
report of the same class is still measured against whatever ceiling *it* asks for. If the app remaps
the exception with `$exceptions->map()`, the override still applies — the lookup walks
`getPrevious()`, which is where the mapped exception keeps the original.

With neither argument, `Report::exception($e)` is just `report($e)` — use the plain helper.

## Log lines

The same idea for narration that is not an exception — a denied origin, a circuit opening, a feed
going stale:

```php
use FloodControl\LogThrottle;

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed', ['origin' => $origin]);
LogThrottle::once('ipqs:circuit', 43200)->error('IPQS circuit breaker opened');
LogThrottle::once('client-error', 300, 'client_errors')->error($message);
```

The first call in the window returns the real logger, the rest return a `NullLogger`. The gate is
`Cache::add()`, so it holds across workers rather than per process. A `null` or sub-1 window never
throttles. A cache failure logs rather than throwing — logging must never break its caller, and an
outage is exactly when the line is worth most.

Unlike the exception side, **the key is yours to pick**: a log line has no class to key on. Keep it a
literal or a code-owned value. A key built from a request header, an origin, or client-supplied text
is unbounded cache cardinality.

**Pair a throttled line with an unthrottled counter.** The line tells you what happened; without the
counter there is no way to tell one blip from every call failing, because a throttled line goes to a
`NullLogger` and leaves no trace at all.

```php
LogThrottle::once('ipqs:circuit', 43200)->error('IPQS circuit breaker opened', [...]);

Pulse::record('circuit_opened', 'ipqs')->count()->onlyBuckets();
```

## Keeping a rate signal

The gate runs inside `shouldntReport()`, **ahead of every `reportable` callback**. Anything that
counts exceptions from a `reportable` — Laravel Pulse's `Exceptions` recorder, for one — therefore
counts what survived the throttle, not what happened.

`laravel/pulse` is a **suggestion, not a requirement** — nothing in `require` pulls it in. With it
absent the counter is never registered, nothing touches Pulse config, `about` reports the counter as
`OFF`, and the throttle works exactly the same. CI runs the whole suite both ways.

If `laravel/pulse` is installed this is handled for you: the package registers a counter ahead of its
own gate and turns Pulse's recorder off, emitting the same `exception` type and `[class, location]`
key, so the stock Pulse card keeps working and reports the true rate while the narrative is capped.
Leaving both on would count every surviving exception twice.

The swap is not silent — `php artisan about` reports it:

```
  Flood Control ......................................................
  Enabled ..................................................... ENABLED
  Exception budget ........................................ 10 per 300s
  Per-class budgets ................................................. 0
  Pulse counter .......................... ON (replaces Pulse recorder)
```

Set `FLOOD_CONTROL_PULSE=false` to leave Pulse's recorder alone. Its numbers then agree with the
throttle rather than with reality — only do this if that is what you want.

Rolling your own is the same shape. A throttle callback that returns `null` falls through to the
next, so register yours ahead of the package:

```php
// bootstrap/app.php — withExceptions() runs during bootstrap, ahead of this package's provider
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->throttle(function (Throwable $e): null {
        Metrics::increment('exceptions', $e::class);

        return null;   // fall through to the package's gate
    });
})
```

That is also how you override the package outright: return a `Limit` or a `Lottery` and its callback
never runs.

## The Sentry double-send

Not this package's job, but it is the thing that most often makes a throttle look broken.

`Integration::handles($exceptions)` registers a `reportable` callback that returns `void`.
`Handler::reportThrowable()` only short-circuits on `=== false`, so it still falls through to
`$logger->error($e->getMessage(), ['exception' => $e])` — and if `sentry` is in your `LOG_STACK`,
`SentryHandler` captures the same throwable a second time. Every uncaught exception is billed twice.

Pick one sink. Keeping the log channel is usually right: it is also what turns a deliberate
`Log::error(..., ['exception' => $e])` into a full exception event, which `Integration::handles()`
does not do.

## Testing

```bash
composer test
```
