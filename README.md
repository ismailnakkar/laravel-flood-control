# laravel-flood-control

Stop repeated exceptions and repeated log lines from flooding your log and your error tracker — while
Laravel Pulse still counts every one of them.

Requires PHP 8.2 and Laravel 12.1 or newer.

## Install

```bash
composer require ismailnakkar/laravel-flood-control
```

That is the whole install — no `bootstrap/app.php` edit. Defaults to 10 reports per exception class
per 5 minutes. `report($e)` at the call site stays exactly as it is.

To change the defaults, publish the config:

```bash
php artisan vendor:publish --tag=flood-control-config
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

An entry covers everything under it, class or interface, and a subtype beats its supertype. Entries
with no subtype relation — two unrelated interfaces, or an interface and a class — are tried in the
order written, so **put a catch-all like `Throwable::class` last**.

An entry sets the budget, not the bucket: the bucket is always the concrete exception class, so a
catch-all of 20 gives each subclass 20 of its own. Both keys are optional and fall back to the
defaults.

A limit or window below 1 means *no limit*, not *never report* — an empty env var reads as `0`. To
silence a class, use the framework's `$exceptions->dontReport()`.

Malformed `classes` entries throw at boot, naming the entry. The check is skipped when config is
cached, so `config:cache` is where a deploy catches one. Budgets are arrays, not `Limit` objects:
`config:cache` writes config with `var_export()`, and `Limit` has no `__set_state()`.

### Per call

```php
use FloodControl\Report;
use Illuminate\Cache\RateLimiting\Limit;

Report::exception($e, ['analyzer' => $analyzer::class]);
Report::exception($e, limit: Limit::perHour(1));
Report::exception($e, limit: Limit::perMinute(5)->by("tenant:{$tenant->id}"));
```

Context goes through `Context::scope()`, so it reaches the log record and the Sentry event and is
restored afterwards — which matters inside a loop, and when reporting itself throws.

A per-call `limit` caps that one report and does not reserve the class bucket. It is spent when the
report reaches the throttle — so one the handler drops earlier (`dontReport`, a duplicate) carries to
the next report of the same instance. `by()` replaces the per-class bucket, which is how you throttle
per tenant or across classes. It survives `$exceptions->map()`: the lookup walks `getPrevious()`.

With neither argument, `Report::exception($e)` is just `report($e)`. Use the plain helper.

## Log lines

The same idea for narration that is not an exception — a denied origin, a circuit opening, a feed
going stale:

```php
use FloodControl\LogThrottle;

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed', ['origin' => $origin]);
LogThrottle::once('ipqs:circuit', 43200)->error('IPQS circuit breaker opened');
```

```php
once(string $key, ?int $seconds, LoggerInterface|UnitEnum|array|string|null $channel = null)
```

The first call in the window returns the real logger, the rest return one that discards. It is an
`Illuminate\Log\Logger` either way, so every PSR level, `withContext()` and array messages work on
both — only `listen()` differs, which throws on the discarding one. A discarded line fires no
`MessageLogged`, so it stays out of Telescope and Sentry breadcrumbs too.

`$channel` takes anything Laravel's log manager does: a channel or stack name, an array of channel
names for an on-demand stack, an enum, a `Log::build()` logger, or null for the default.

```php
LogThrottle::once('client-error', 300, 'client_errors')->error($message);
LogThrottle::once('feed-stale', 900, ['single', 'slack'])->warning('Feed is stale');
```

The gate is `Cache::add()` on the `cache.limiter` store — the same store the exception throttle uses
— so it holds across workers rather than per process. A `null` or sub-1 window never throttles, and
a cache failure lets the line through rather than throwing.

Unlike the exception side, **the key is yours to pick**: a log line has no class to key on. Keep it a
literal or a code-owned value. A key built from a request header, an origin, or client-supplied text
is unbounded cache cardinality.

**Pair a throttled line with an unthrottled counter.** The line tells you what happened; without the
counter there is no way to tell one blip from every call failing.

```php
LogThrottle::once('ipqs:circuit', 43200)->error('IPQS circuit breaker opened', [...]);

Pulse::record('circuit_opened', 'ipqs')->count()->onlyBuckets();
```

## Keeping a rate signal

The gate runs inside `shouldntReport()`, **ahead of every `reportable` callback**. Anything counting
exceptions from a `reportable` — Laravel Pulse's `Exceptions` recorder, for one — therefore counts
what survived the throttle, not what happened.

`laravel/pulse` is a suggestion, not a requirement. With it absent nothing is registered and the
throttle works the same. CI runs the suite both ways.

With Pulse installed this is handled for you. The package binds Pulse's `Exceptions` recorder to one
that counts from a throttle callback instead of a `reportable` — in front of the gate, so the card
shows what happened rather than what survived. Recording is still Pulse's own recorder, so the stock
card, your `ignore`, `sample_rate` and `location` settings, and `Pulse::report()` are unchanged. No
Pulse config is written, and Pulse's own off switches still win. The swap is not silent:

```
  Flood Control ......................................................
  Enabled ..................................................... ENABLED
  Exception budget ........................................ 10 per 300s
  Per class budgets ................................................. 0
  Pulse counter ....................... ON (counts in front of the gate)
```

Set `FLOOD_CONTROL_PULSE=false` to leave Pulse's recorder alone. Its numbers then agree with the
throttle rather than with reality. Pulse's own `PULSE_EXCEPTIONS_ENABLED=false` is different: it turns
exception counting off entirely, and this package adds nothing back.

**Your own counters go in config.** Anything listed here sees every exception the handler considers,
throttled or not:

```php
// config/flood-control.php
'counters' => [
    \App\Reporting\CountExceptions::class,
],
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

Each is resolved from the container per exception and invoked with the throwable, in front of the
gate. Return
values are ignored and throws are swallowed: a throw in a throttle callback reads as *do not
throttle*, so an unguarded one would switch the package off. A counter that reports is skipped on the
nested report rather than recursing — counters run in front of the gate, so the gate cannot stop it.
A typo'd or non-invokable class throws at boot, like a malformed budget; an interface works too, if
you bind one.

The rule for what goes where: a **counter** wants the true rate, so it goes here. A **sink** — Sentry,
Flare, a log write — wants fewer events, so it stays a `reportable` and gets the throttled stream,
which needs no configuration at all.

The `bootstrap/app.php` form does the same thing, and is where to go for a closure:

```php
// withExceptions() runs during bootstrap, ahead of this package's provider
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->throttle(function (Throwable $e): null {
        Metrics::increment('exceptions', $e::class);

        return null;   // fall through to the package's gate
    });
})
```

That is also how you override the package outright: return a `Limit` or a `Lottery` and the gate never
runs. `Handler::throttle()` stops at the first non-null return, and your callback is registered first.

Counting survives it either way. Both the Pulse counter and your configured `counters` hang off
`dontReportWhen()`, which `shouldntReport()` runs ahead of the whole throttle chain — so replacing the
gate does not cost you the rate signal.

## Wiring a sink

A sink needs nothing from this package — it is a `reportable`, so it already sits behind the gate and
receives the throttled stream. What it does need is **exactly one hookup**. Sentry, Flare, Bugsnag and
Rollbar all offer two, and wiring both sends every exception twice: a budget of 10 arrives as 20.

| Hookup | Where | Catches |
| --- | --- | --- |
| Reportable | `Integration::handles($exceptions)` in `bootstrap/app.php` | reported throwables |
| Log channel | the sink's channel in `LOG_STACK` | reported throwables **and** `Log::error(..., ['exception' => $e])` |

Pick one. The log channel is usually right: it is the wider net and needs no `bootstrap/app.php` edit.

Why both fire when you wire both — a reportable returning `void` does not stop the chain, because the
handler short-circuits only on `=== false`. So `report()` falls through to
`$logger->error($e->getMessage(), ['exception' => $e])`, and the sink's Monolog handler captures the
same throwable a second time.

Double-wiring does not break the throttle. It doubles what arrives at the far end of it.

## Testing

```bash
composer test     # phpunit
composer check    # pint --test, then phpunit
```

`LogThrottle::once()` resolves through `Log::driver()`, which a bare `Log::spy()` stubs to `null`.
Point it back at itself:

```php
Log::spy()->shouldReceive('driver')->andReturnSelf();
```
