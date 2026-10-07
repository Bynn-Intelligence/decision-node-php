# DecisionNode PHP SDK

The PHP client for the [DecisionNode](https://decisionnode.com) API: ask yes-or-no, choice and score questions about any text, JSON or image, and get probabilities back.

Implements version 1.0 of the DecisionNode SDK specification, which the package states as `DecisionNode::SPEC_VERSION` (also `Bynn\DecisionNode\SPEC_VERSION`). Requires PHP 8.2 or later.

## Install

```sh
composer require bynn/decisionnode
```

## Set your API key

```sh
export DECISIONNODE_API_KEY="your-api-key"
```

## Quickstart

```php
use Bynn\DecisionNode\DecisionNode;
use function Bynn\DecisionNode\{truth, choice, score};

$dn = new DecisionNode(); // reads DECISIONNODE_API_KEY

$d = $dn->decide(
    state: ['ticket' => 'I was charged twice and nobody has replied for 3 days.'],
    questions: [
        'route' => choice('Where should this go?', ['billing' => 'money', 'bug' => 'broken', 'account' => 'login']),
        'urgency' => score('How urgent is this?', ['routine', 'today', 'urgent', 'critical']),
        'escalate' => truth('Escalate to a human now?'),
    ],
);

$d['route']->choice;          // "billing"
$d['route']->probabilities;   // ["billing" => 0.87, "bug" => 0.08, "account" => 0.05]
$d['urgency']->score;         // 2.31
$d['escalate']->truth;        // 0.94
```

Every number is the value the API sent: nothing is rounded, renormalised or thresholded. What 0.94 means for your application is your code's decision.

## Questions

| Builder | Answer | Fields |
|---|---|---|
| `truth($instructions, $ifTrue, $ifFalse)` | `TruthAnswer` | `truth`: the probability, 0 to 1, that the statement is true |
| `choice($instructions, $options)` | `ChoiceAnswer` | `choice`, `confidence`, `probabilities` keyed by option name |
| `score($instructions, $levels)` | `ScoreAnswer` | `score` (the expected level index), `confidence`, `probabilities` and `legend` keyed by level index |

- `truth`: `$ifTrue` and `$ifFalse` describe what counts as true and false. Both are optional.
- `choice`: `$options` is a map of option name to description, or a list of names. Names are sent byte for byte, in the order given.
- `score`: `$levels` runs from low to high.

Instructions, descriptions and levels can be strings or any JSON value. The builders are also static methods (`Question::truth(...)`), and a question in its plain wire form is accepted wherever a builder is:

```php
$dn->decide('Paid 740 SEK at Nordic Rail.', [
    'currency' => ['type' => 'choice', 'instructions' => 'Which currency?', 'criteria' => ['SEK' => null, 'EUR' => null]],
]);
```

Answers are read by question id, `$d['route']` or `$d->answers['route']`. An answer of a question type this SDK version does not know yet comes back as an `UnknownAnswer` with its `type` and `raw` fields. In `decide`, so does an answer of a known type whose fields are missing or of the wrong type (a choice without `probabilities`, a truth whose value is a string): the rest of the decision is read as usual. The one-question helpers `check`, `classify` and `score` promise a typed answer, so for them a missing or malformed answer is a `ServerError` (see below). Every answer class extends `Answer`.

## The client

| Method | Does | Returns |
|---|---|---|
| `decide($state, $questions, $images, $model)` | One `POST /v1/decide` | `Decision` |
| `decideMany($requests, $concurrency = 8)` | One decision per request, at most `$concurrency` at a time | A list of `DecisionResult` in input order: each a `Decision` or the `DecisionNodeError` that request ended with |
| `check($state, $instructions, $ifTrue, $ifFalse)` | One `truth` question | `TruthAnswer`; a `ServerError` when the answer is missing or malformed |
| `classify($state, $instructions, $options)` | One `choice` question | `ChoiceAnswer`; a `ServerError` when the answer is missing or malformed |
| `score($state, $instructions, $levels)` | One `score` question | `ScoreAnswer`; a `ServerError` when the answer is missing or malformed |
| `gate($action, $context)` | Whether an action should run: a `risk` score, a `touches_production` truth and an `allow`, `confirm` or `block` verdict (wording below) | `Decision` |
| `models()` | `GET /v1/models` | A list of `ModelInfo` with `name`, `description`, `releaseDate` and `limits` |
| `decideRaw($body)` | Sends `$body` exactly as given | The response body as an array |

`check`, `classify`, `score` and `gate` also take `$images` and `$model`, like `decide`. `$state` is a string or any JSON value; leaving it out sends an empty string. `decideMany` checks every request before it sends any: a `$concurrency` below 1, or an argument problem in any one of the requests (an empty question map, say), throws `InvalidArgumentException` and nothing is sent, not even the valid requests. An `Image` of an unknown format already throws when it is made.

`gate` sends the state `{"action": ..., "context": ...}` and these three questions, word for word the same in every DecisionNode SDK:

| Id | Type | Instructions | Levels or options |
|---|---|---|---|
| `risk` | score | How much harm could this action cause if it is wrong? | `none`, `low`, `moderate`, `high`, `severe` |
| `touches_production` | truth | Does this action change a production system, production data or anything customers use? | |
| `verdict` | choice | Should this action run as it is, wait for a person to confirm it, or be blocked? | `allow` "safe to run without asking", `confirm` "a person should confirm it first", `block` "should not run" |

A `Decision` has `answers`, `model` (the resolved model version), `usage->inputTokens`, `usage->outputTokens`, `requestId`, `latencyMs` (the server's), `safety` and `raw` (the parsed body).

```php
$answer = $dn->check('Refund me now or I cancel.', 'Is the customer asking for a refund?');
$answer->truth; // 0.97

$results = $dn->decideMany([
    ['state' => $ticketA, 'questions' => ['spam' => truth('Is this spam?')]],
    ['state' => $ticketB, 'questions' => ['spam' => truth('Is this spam?')]],
]);
foreach ($results as $result) {
    if ($result instanceof \Bynn\DecisionNode\Exception\DecisionNodeError) {
        // this request failed; the others are unaffected
    }
}
```

`decideRaw` is the way to use an API field this SDK version does not know yet.

### Async

Every method has an `Async` form (`decideAsync`, `checkAsync`, `modelsAsync`, ...) that returns a promise without blocking. Call `->wait()` for the result, or combine several with `GuzzleHttp\Promise\Utils::all()`. An argument problem is thrown as `InvalidArgumentException` when the method is called, before a promise is made; everything the API answers, and a failed connection, rejects the promise with a `DecisionNodeError`.

```php
$promise = $dn->decideAsync('...', ['q' => truth('...')]);
$decision = $promise->wait();
```

## Images

```php
use Bynn\DecisionNode\Image;

$d = $dn->decide(
    state: ['claim' => ['total' => '740.00']],
    images: [
        Image::fromPath('receipt', __DIR__ . '/receipt.jpg'),
        Image::fromData('photo', $bytes),            // media type read from the bytes
        Image::fromData('scan2', $bytes, 'image/png'),
        Image::fromUrl('scan', 'https://example.com/scan.webp'),
    ],
    questions: ['matches' => truth('Does the receipt show the claimed total?')],
);
```

A file is read and base64-encoded when the `Image` is made, so a missing or unreadable file throws `InvalidArgumentException` there. Its media type is taken from its first bytes (JPEG, PNG or WebP), and so is the media type of bytes given without one; bytes of any other format without a media type throw `InvalidArgumentException`. The API takes up to 4 images a request, 10 MB each, and only `https` URLs.

## Errors

Every error derives from `Bynn\DecisionNode\Exception\DecisionNodeError`, so `catch (DecisionNodeError $e)` catches them all. Its message, `getMessage()`, is the server's message exactly as sent (empty when the server sent none), or the SDK's description when there was no response or the response could not be read. It also has `status`, `errorType`, `requestId` and `reason` (the server's explanation from the `x-decisionnode-error` header, for example "question 'q': a score takes up to 10 levels, got 11").

Only a 2xx response with a JSON body is a success. Redirects are never followed, so your key never goes to another URL or over plain HTTP.

| Status | Exception | Extra fields |
|---|---|---|
| 400 | `BadRequestError`; `ContextLimitError` when the request is larger than the model's input limit | |
| 401 | `AuthenticationError` (also raised before sending when no API key is set) | |
| 402 | `InsufficientCreditError` | |
| 403 | `RefusalError` when the request is refused under the usage policy; `PermissionDeniedError` otherwise | `category` (`harm` or `self_harm`), `safety` |
| 404 | `NotFoundError` | |
| 422 | `ValidationError` | `entries` |
| 429 | `RateLimitError` | `retryAfter` (seconds) |
| 529 | `OverloadedError` | `retryAfter` (seconds) |
| other 5xx, a 2xx whose body is not JSON, or a 2xx where the answer of `check`, `classify` or `score` is missing or malformed | `ServerError` | |
| any other status (a 3xx, 405, 409, 408 once its retries are used up) | `DecisionNodeError` itself | |
| no response | `ConnectionError`, `TimeoutError` | |

```php
use Bynn\DecisionNode\Exception\{DecisionNodeError, RateLimitError, ValidationError};

try {
    $d = $dn->decide($state, $questions);
} catch (ValidationError $e) {
    var_dump($e->entries);
} catch (RateLimitError $e) {
    sleep((int) ceil($e->retryAfter ?? 1));
} catch (DecisionNodeError $e) {
    error_log($e->getMessage() . ' ' . $e->requestId);
}
```

The SDK checks very little before sending, and a problem it finds is PHP's own `InvalidArgumentException`, never a `DecisionNodeError`: an empty question map, a value that cannot be encoded as JSON, an image file it cannot read or bytes of an unknown image format, a `timeout` of 0 or less, a negative `maxRetries`, a base URL that is not `https`, an `httpClient` the SDK cannot show refuses redirects or one that turns off TLS checks (see [HTTP client](#http-client)), a `$concurrency` below 1, and any of these in one request of `decideMany`. Everything else is the server's to judge, since limits differ by model and are published at `models()`.

## Timeouts and retries

Each attempt times out after 10 seconds, counted over the whole attempt: from sending the request to the last byte of the response body, not per network read. A request is retried twice after the first attempt on 408, 429, any 5xx, timeouts and dropped connections, waiting what the server asks for (`retry-after-ms`, else `Retry-After` in seconds or as an HTTP date, never more than 60 s) or else backing off exponentially from 0.5 s with jitter, up to 8 s. 400, 401, 402, 403, 404 and 422 are never retried. A decision changes nothing on the server, so a retry is always safe.

```php
$dn = new DecisionNode(timeout: 5.0, maxRetries: 4);
```

`timeout` must be above 0 and `maxRetries` 0 or more; anything else throws `InvalidArgumentException`. There is no setting for an unlimited timeout.

## Safety

On the hosted API every decision response carries the result of a safety check in the `x-decisionnode-safety` header, which `$d->safety` holds: `checked`, `harm`, `selfHarm`, `flagged` (the categories above your key's thresholds) and, when the check did not run, `reason`. On a self-hosted server it is `null`. A refused request raises `RefusalError` and is never retried.

### The safety scores in the body

Send the request header `x-decisionnode-safety-body: true` and the API also returns the safety scores as a `safety` field of the response body, which you read from `$d->raw['safety']`. The SDK sends no such header unless you add it; set it for every request with the client's `headers` argument:

```php
$dn = new DecisionNode(headers: ['x-decisionnode-safety-body' => 'true']);

$d = $dn->decide('...', ['q' => truth('...')]);
$d->safety;          // parsed from the x-decisionnode-safety header, as always
$d->raw['safety'];   // the same scores, from the body
```

## Configuration

| Argument | Environment variable | Default |
|---|---|---|
| `apiKey` | `DECISIONNODE_API_KEY` | none: a call without a key fails before sending |
| `baseUrl` | `DECISIONNODE_BASE_URL` | `https://api.decisionnode.com`; must be `https` (plain `http` only for `localhost`, `127.0.0.1` and `::1`) |
| `model` | `DECISIONNODE_MODEL` | `decisionnode-latest` |
| `timeout` | | `10.0` seconds for a whole attempt; above 0 |
| `maxRetries` | | `2`; 0 or more |
| `headers` | | extra headers sent with every request |
| `httpClient` | | your own `GuzzleHttp\Client` (rules below) |
| `logger` | | none |

An argument wins over the environment, which wins over the default. An empty string counts as not given, for the client's arguments and for the `$model` of a call. A base URL that is not `https`, from an argument or from the environment, throws `InvalidArgumentException` when the client is made, so the API key never travels unencrypted; plain `http` is accepted only for this machine (`localhost`, `127.0.0.1`, `::1`), for a local test server. Pin a model per client or per call with `decisionnode-1.0` or `decisionnode-1.0-flash`; `decisionnode-latest` and `decisionnode-flash-latest` follow the newest release.

```php
$dn = new DecisionNode(
    apiKey: 'your-api-key',
    model: 'decisionnode-flash-latest',
    headers: ['x-team' => 'billing'], // sent with every request
);
```

### HTTP client

By default the SDK uses Guzzle and reuses its connections. Pass your own `GuzzleHttp\Client` to set a proxy, a CA bundle or other transport options. The SDK's rules still hold with it, and the client is checked when the `DecisionNode` is made:

- **Redirects are never followed.** Every request is sent with redirects switched off, whatever the client is configured to do (curl options you set are kept, except that curl is told not to follow redirects either). A response that came from another host, or a request sent more than once in one attempt (as a middleware that re-sends would do), is refused.
- **Only clients the SDK can inspect.** The client must be a `GuzzleHttp\Client` itself, not a subclass or another `ClientInterface`, and its handler, the code that does the transfer, must be one of Guzzle's own: curl, curl multi, stream or mock, alone or combined by Guzzle as `HandlerStack::create()` does. Any other handler might follow redirects by itself, so it is refused with `InvalidArgumentException`. Middleware you push onto the stack is kept. Other PSR-18 clients are not accepted either.
- **TLS checks stay on.** For an `https` base URL, a client that turns off certificate or host name checks (`'verify' => false`, curl's `CURLOPT_SSL_VERIFYPEER` or `CURLOPT_SSL_VERIFYHOST` off, or a `stream_context` with `verify_peer` or `verify_peer_name` off or `allow_self_signed` on) is refused with `InvalidArgumentException`. Pointing `verify` at your own CA bundle is fine.
- **The timeout covers the whole attempt** with your client too.

```php
$dn = new DecisionNode(httpClient: new \GuzzleHttp\Client(['proxy' => 'http://proxy.example.com:3128']));
```

### Logging

Pass a PSR-3 logger to get one debug line per attempt with the method, path, status, request id and duration. State and questions are never logged. Nothing else is sent anywhere: the only header the SDK adds is `User-Agent: decisionnode-php/<version>`.

### JSON objects in PHP

PHP encodes an empty array as `[]` and an array with keys `0, 1, ...` as a list. Questions and choice options are always sent as JSON objects. In your own data (`$state`, wire-form questions, `decideRaw` bodies), use an object such as `new \stdClass()` or `(object) [...]` where you need `{}` or an object with integer-like keys. `decideRaw` also accepts a JSON string, sent byte for byte.

## Development

```sh
composer install
composer test          # unit tests
composer analyse       # static analysis
```

The conformance tests run the language-neutral DecisionNode conformance cases against their mock server, every case once through the blocking methods and once through their `Async` forms: set `DECISIONNODE_CONFORMANCE_DIR` to the suite's directory and run `composer conformance`. Without that variable the conformance tests fail; they never skip. CI checks the suite out at a pinned commit, set once as `CONFORMANCE_REF` in `.github/workflows/ci.yml`, and every action in the workflow is pinned to a commit SHA.

## License

MIT. See [LICENSE](LICENSE).
