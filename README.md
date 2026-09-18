# Jev SDK for PHP

A small, framework-agnostic PHP client for [TypeSafe](https://typesafe.ai)'s System One API.

You send a piece of state and a set of typed questions. You get back typed answers with calibrated
probabilities — a chosen option, a score, or the odds a statement is true. There is no prose to
parse and no output format to coax out of a prompt.

> Community package, not affiliated with TypeSafe.

## Requirements

| | Minimum |
|---|---|
| PHP | 8.1 |
| HTTP client | any [PSR-18](https://www.php-fig.org/psr/psr-18/) client — Guzzle, Symfony HttpClient, … |
| Framework | none — plain PHP, Laravel, Symfony, anything with Composer |
| Laravel | 8.x (on PHP 8.1+) |

## Install

```bash
composer require mzainzulifqar/jev-php-sdk
```

Laravel apps already have an HTTP client, so that's all. Outside Laravel, if you have no PSR-18
client yet, Composer asks whether to trust `php-http/discovery`; say yes and it installs one for
you. If you said no, or install non-interactively, install one yourself:

```bash
composer require guzzlehttp/guzzle
```

## Use it

```php
use Zain\Jev\Jev;
use Zain\Jev\Question;

$jev = new Jev($_ENV['JEV_API_KEY']);

$result = $jev->ask($email->body, [
    'is_complaint' => Question::noul('Is the writer complaining?'),

    'team' => Question::choice('Which team should answer this?', [
        'billing'   => 'Invoices, refunds and payment problems',
        'technical' => 'The product not working as expected',
        'other'     => 'Anything else',
    ]),

    'urgency' => Question::score('How urgent is this?', [
        'can wait', 'this week', 'today',
    ]),
]);

$result->choice('team')->choice;            // 'billing'
$result->choice('team')->confidence();      // 0.88
$result->noul('is_complaint')->isTrue();    // true
$result->score('urgency')->score;           // 1.4
$result->score('urgency')->nearestLevel();  // 'this week'
```

Ask everything you want to know about one state **in a single call**. The questions are answered in
parallel and only cost their own tokens, so three questions about one email cost barely more than
one — and a lot less than three requests.

## The three question types

| | Ask it when | You get back |
|---|---|---|
| `Question::noul()` | the answer is yes or no | `probability` (0–1) |
| `Question::choice()` | one option out of a fixed set | `choice`, `probabilities`, `confidence` |
| `Question::score()` | a rating along ordered levels | `score`, `legend`, `probabilities`, `confidence` |

**A yes/no answer has no separate confidence.** Its probability already carries that: `0.5` means
no information, and certainty grows towards either end. `confidence()` returns the distance from
that midpoint, so the interface stays the same across all three.

**Give `choice()` every option that could apply**, including an `other`. Without one the model has
to pick a wrong answer from the options you gave it.

**A score can land between levels.** Three levels give a `0..2` scale, so `1.4` means "past the
middle level, not quite the top one". Round only when you have a reason to.

## Acting on confidence

Calibrated probabilities are the point of this API: when it says 0.85, it should be right about 85%
of the time. That lets you split the work by how sure it is, rather than treating every answer the
same.

```php
$result = $jev->ask($ticket->body, $questions);

foreach ($result->uncertain(threshold: 0.8) as $name) {
    $ticket->flagForReview($name);   // the few a person should look at
}

if ($result->choice('team')->confidence() >= 0.8) {
    $ticket->routeTo($result->choice('team')->choice);   // the many that can just go
}
```

`ranked()` gives the options most to least likely, when you want the runner-up too:

```php
$result->choice('team')->ranked();   // ['billing' => 0.82, 'technical' => 0.13, 'other' => 0.05]
```

## Using it in Laravel

**1. Add the key** to `.env`:

```env
JEV_API_KEY=your-key
```

**2. Add it to `config/services.php`**, so it survives `php artisan config:cache`:

```php
'jev' => [
    'key' => env('JEV_API_KEY'),
],
```

**3. Bind the client once** in `AppServiceProvider::register()`:

```php
use Zain\Jev\Jev;

$this->app->singleton(Jev::class, fn () => new Jev((string) config('services.jev.key')));
```

**4. Type-hint it** anywhere the container builds — controllers, jobs, commands, listeners:

```php
use Zain\Jev\Jev;
use Zain\Jev\Question;

final class TicketController extends Controller
{
    public function store(Request $request, Jev $jev)
    {
        $data = $request->validate(['body' => ['required', 'string']]);

        $team = $jev->ask($data['body'], [
            'team' => Question::choice('Which team should answer this?', [
                'billing'   => 'Invoices, refunds and payment problems',
                'technical' => 'The product not working as expected',
                'other'     => 'Anything else',
            ]),
        ])->choice('team');

        // …
    }
}
```

**In a queued job**, let the queue own retries so a failure isn't retried twice over:

```php
use Zain\Jev\Jev;
use Zain\Jev\RetryPolicy;

final class ClassifyTicket implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(public Ticket $ticket) {}

    public function handle(): void
    {
        $jev = new Jev((string) config('services.jev.key'), retry: RetryPolicy::none());
        // …
    }
}
```

(Laravel 11+ job shown; on 8–10 use the usual `Dispatchable, InteractsWithQueue, Queueable, SerializesModels` traits.)

**In tests**, swap the binding for a client that never touches the network (see [Testing](#testing)):

```php
$this->app->instance(Jev::class, new Jev('test-key', http: $fakeClient, requests: $psr17, streams: $psr17));
```

## Many states

```php
$results = $jev->askEach($rows, $questions);   // keyed by the input keys
```

Requests are sequential and deliberately so: the result of each row stays attached to that row, and
failures are attributable. For a large job, put the parallelism in your queue or worker pool, where
you can also control how much of the API you use at once.

## Errors

Everything thrown extends `Zain\Jev\Exceptions\JevException`.

| | |
|---|---|
| `AuthenticationException` | 401 — key missing or rejected |
| `ValidationException` | 422 — the request was wrong; retrying will not help |
| `RateLimitException` | 429 |
| `OverloadedException` | 529 |
| `ConnectionException` | never reached the API |
| `InvalidQuestion` | a question was built wrongly, thrown before sending |
| `MissingAnswer` | an answer read by the wrong name or the wrong type |

Rate limits, overload and connection failures are retried automatically with exponential backoff
and jitter, honouring `Retry-After`. A rejected request is never retried. Tune or switch it off:

```php
use Zain\Jev\RetryPolicy;

new Jev($key, retry: new RetryPolicy(maxAttempts: 5, baseDelay: 0.5, maxDelay: 8.0));
new Jev($key, retry: RetryPolicy::none());   // e.g. inside a queued job that retries as a whole
```

## Testing

Pass your own PSR-18 client and nothing touches the network:

```php
$jev = new Jev('test-key', http: $fakeClient, requests: $psr17, streams: $psr17);
```

This package's own suite does exactly that — see `tests/FakeHttpClient.php`.

```bash
composer install
vendor/bin/phpunit
```

## Configuration

```php
new Jev(
    apiKey: $key,
    model: 'jev-latest',   // or pin a version so answers stay stable across deploys
    baseUrl: 'https://api.typesafe.ai/v1',
    retry: new RetryPolicy(),
);

$jev->withModel('jev-1.13.0');   // a copy against another model
```

## Licence

MIT.
