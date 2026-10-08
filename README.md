# DecisionNode PHP SDK

The PHP client for the [DecisionNode](https://decisionnode.com) API: ask yes-or-no, choice, score and number questions about any text, JSON or image, and get probabilities back. One-off decisions, live sessions that answer a stream of frames, and batches at half the price.

Implements version 1.1 of the DecisionNode SDK specification, which the package states as `DecisionNode::SPEC_VERSION` (also `Bynn\DecisionNode\SPEC_VERSION`). Requires PHP 8.2 or later.

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
| `number($instructions, $min, $max, $step)` | `NumberAnswer` | `number` (the most likely value), `expected`, `confidence` (the probability of `number`), `probabilities` keyed by the value as the API wrote it (`"7"`, `"3.5"`) |

- `truth`: `$ifTrue` and `$ifFalse` describe what counts as true and false. Both are optional.
- `choice`: `$options` is a map of option name to description, or a list of names. Names are sent byte for byte, in the order given.
- `score`: `$levels` runs from low to high.
- `number`: a grid from `$min` to `$max` in steps of `$step`, at most 4,096 values. An argument you leave out is left out of the request, so the server's defaults apply (0, 255 and 1).

```php
use function Bynn\DecisionNode\number;

$d = $dn->decide('Three cars and a van are parked outside.', ['vehicles' => number('How many vehicles?', 0, 20)]);
$d['vehicles']->number;          // 4
$d['vehicles']->expected;        // 4.08
$d['vehicles']->probabilities;   // [3 => 0.06, 4 => 0.87, 5 => 0.07]
```

PHP stores an array key that looks like a whole number (`"4"`) as an integer, so read a number answer's probabilities as `$answer->probabilities[4]` or `$answer->probabilities['4']`; a key such as `"3.5"` stays a string.

Instructions, descriptions and levels can be strings or any JSON value. The builders are also static methods (`Question::truth(...)`), and a question in its plain wire form is accepted wherever a builder is:

```php
$dn->decide('Paid 740 SEK at Nordic Rail.', [
    'currency' => ['type' => 'choice', 'instructions' => 'Which currency?', 'criteria' => ['SEK' => null, 'EUR' => null]],
]);
```

Answers are read by question id, `$d['route']` or `$d->answers['route']`. An answer of a question type this SDK version does not know yet comes back as an `UnknownAnswer` with its `type` and `raw` fields. In `decide`, so does an answer of a known type whose fields are missing or of the wrong type (a choice without `probabilities`, a truth whose value is a string): the rest of the decision is read as usual. The one-question helpers `check`, `classify`, `score` and `number` promise a typed answer, so for them a missing or malformed answer is a `ServerError` (see below). Every answer class extends `Answer`.

## The client

| Method | Does | Returns |
|---|---|---|
| `decide($state, $questions, $images, $model)` | One `POST /v1/decide` | `Decision` |
| `decideMany($requests, $concurrency = 8)` | One decision per request, at most `$concurrency` at a time | A list of `DecisionResult` in input order: each a `Decision` or the `DecisionNodeError` that request ended with |
| `check($state, $instructions, $ifTrue, $ifFalse)` | One `truth` question | `TruthAnswer`; a `ServerError` when the answer is missing or malformed |
| `classify($state, $instructions, $options)` | One `choice` question | `ChoiceAnswer`; a `ServerError` when the answer is missing or malformed |
| `score($state, $instructions, $levels)` | One `score` question | `ScoreAnswer`; a `ServerError` when the answer is missing or malformed |
| `number($state, $instructions, $min, $max, $step)` | One `number` question | `NumberAnswer`; a `ServerError` when the answer is missing or malformed |
| `gate($action, $context)` | Whether an action should run: a `risk` score, a `touches_production` truth and an `allow`, `confirm` or `block` verdict (wording below) | `Decision` |
| `systemone($state, $questions, $images, $model)` | One `POST /v1/systemone`, the path that takes only truth, choice and score questions and always names a model | `Decision` |
| `models()` | `GET /v1/models` | A list of `ModelInfo` with `name`, `description`, `releaseDate` and `limits` (every other field of the entry, such as `context_tokens`, `number_max_grid_values`, `upload_max_bytes`, `sessions` and `batch`) |
| `upload($images)` | Uploads local images whatever their size | One upload id per image |
| `openSession(...)` | `POST /v1/sessions` | `Session` (see [Sessions](#sessions)) |
| `supervise($body, $feed, $onReply)` | Keeps a session running over a long feed | The last `SessionClosed`, or `null` |
| `batches->create(...)` and the other batch calls, `runBatch(...)` | The Batch API | `Batch`, `BatchResult` (see [Batches](#batches)) |
| `decideRaw($body)` | Sends `$body` exactly as given | The response body as an array |

`check`, `classify`, `score`, `number` and `gate` also take `$images` and `$model`, like `decide`. `$state` is a string or any JSON value; leaving it out sends an empty string. `decideMany` checks every request before it sends any: a `$concurrency` below 1, an argument problem in any one of the requests (an empty question map, say), or a question or image the API would refuse throws, and nothing is sent, not even the valid requests.

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

Every HTTP method has an `Async` form (`decideAsync`, `checkAsync`, `modelsAsync`, `uploadAsync`, `openSessionAsync`, `runBatchAsync`, `$dn->batches->getAsync`, ...) that returns a promise without blocking. Call `->wait()` for the result, or combine several with `GuzzleHttp\Promise\Utils::all()`. A problem found before sending (an argument problem, or a question or image the API would refuse) is thrown when the method is called, before a promise is made; everything the API answers, and a failed connection, rejects the promise with a `DecisionNodeError`. A session's socket is blocking.

```php
$promise = $dn->decideAsync('...', ['q' => truth('...')]);
$decision = $promise->wait();
```

## Images

**Send an image by URL when you can.** It is the fastest input: nothing is uploaded, the request stays small, and the API fetches the image itself.

```php
use Bynn\DecisionNode\Image;

$d = $dn->decide(
    state: ['claim' => ['total' => '740.00']],
    images: [Image::fromUrl('receipt', 'https://files.example.com/claims/4411/receipt.jpg')],
    questions: ['matches' => truth('Does the receipt show the claimed total?')],
);
```

A URL must be `https` and at most 2,048 characters; the SDK checks that and sends it as it is, without fetching it. Local images work too:

```php
Image::fromPath('receipt', __DIR__ . '/receipt.jpg');
Image::fromData('photo', $bytes);                 // media type read from the bytes
Image::fromData('scan', $bytes, 'image/png');      // a given media type must match the bytes
Image::fromBase64('scan', $base64);
Image::fromStream('scan', fopen('scan.webp', 'rb'));  // a resource or a PSR-7 stream
Image::fromUploadId('scan', 'upl_...');           // an upload made earlier, within its hour
```

A file or stream is read when the `Image` is made, so a missing or unreadable file throws `InvalidArgumentException` there. Before any request, the SDK checks every local image against the API's rules and raises the API's own `BadRequestError` for a fault, without a round trip: JPEG, PNG or WebP by its first bytes, at most 10 MB, at most 40 megapixels read from the image header (the image is never decoded), at most 4 images a request (URLs included), ids unique. No image is resized or re-encoded.

How a local image travels is chosen for the shortest response time:

- **Small images go inline.** When a request's local images total under 512 KiB (`DecisionNode::DEFAULT_INLINE_THRESHOLD`, 524,288 bytes), they are sent as base64 inside the request.
- **Larger ones are uploaded once.** At or above that size, the SDK uploads them first, with one `POST /v1/uploads` for all of them and one `PUT` each straight to storage, side by side, then sends the request naming them by upload id. The `PUT` carries only the headers the API returned: never your key, never your custom headers, and no redirect is followed.
- **An upload is reused.** The client remembers its uploads by the SHA-256 of the bytes, in memory, for the upload's hour (up to 5 minutes before it expires, at most 256 images). The same image in a later request, a `decideMany`, a session frame or a batch line is sent by its upload id whatever its size, with nothing uploaded again. If the API answers that an upload the SDK placed has expired or was used up, the SDK uploads the image again and sends the request once more.

Set `inlineThreshold` on the client to change the threshold (`0` uploads every local image). `upload($images)` uploads whatever the size and returns the upload ids, for a pipeline that wants to upload first and decide later.

## Sessions

A session reads its state and questions once, then answers every frame you send on its socket: telemetry from a drone, a video feed's latest frame, a chat's latest message. Frames are sent without waiting; when they arrive faster than they are answered, the latest wins and its reply lists the frames it superseded in `dropped`.

```php
$session = $dn->openSession(
    state: ['mission' => 'Survey the north field'],
    questions: [
        'mode' => choice('Which flight mode now?', ['continue' => 'keep flying', 'hold' => 'hover', 'land' => 'land now']),
        'abort' => truth('Should the mission be aborted now?'),
    ],
);
$session->connect();
$session->send(frame: ['battery' => 0.31, 'wind_mps' => 11.4]);

foreach ($session->replies() as $event) {
    if ($event instanceof \Bynn\DecisionNode\Reply) {
        $event->answers['abort']->truth;
    } elseif ($event instanceof \Bynn\DecisionNode\FrameError) {
        // this frame was not answered: $event->errorType, $event->retryAfterMs
    } else {
        // a SessionClosed: $event->closeCode, $event->reopen
    }
}
$session->end();
```

- `openSession($questions, $state, $instructions, $model, $window, $ttlSeconds)` always names a model. A key that already holds as many open sessions as it may gets a `SessionLimitError` (a `RateLimitError`), which is never retried: end a session first.
- `connect()` opens the socket with your key in the handshake's `Authorization` header (never in the URL) and your custom headers. Pings are answered while you read.
- `send($frame, $image, $questions, $reset, $seq)` numbers frames from 1 unless you give `$seq`. A frame's image follows the image rules above (small bytes inline, larger or already uploaded ones by upload id); a frame takes no image URL.
- `next($timeout)` returns the next `Reply` or `FrameError`, or the `SessionClosed` that ends the socket, or `null` when nothing arrived in time. `replies()` yields them until the socket closes.
- `end()` ends the session; a session that is already gone counts as ended.

`SessionClosed::$reopen` is true for the close codes after which a new session should be opened (1001, 1006, 1011, 4404, 4408, 4409, 4410). On 1013 the session is kept and you connect to it again. `supervise($body, $feed, $onReply)` does all of that for a long-lived feed: it sends each reading as it arrives, hands every reply to `$onReply`, opens a new session when the server asks for it (at once when the session's time is up or it went idle, after a backoff of 1 to 30 s with jitter otherwise), and when the feed ends waits for the outstanding replies, ends the session and returns the last `SessionClosed`.

```php
$closed = $dn->supervise(
    ['state' => ['mission' => 'Survey'], 'questions' => ['abort' => truth('Abort?')]],
    $telemetry,   // any iterable of send() arguments, such as ['frame' => [...]]
    function ($reply) { /* ... */ },
);
```

## Batches

The Batch API answers many decisions at half the price, within 24 hours. `runBatch` runs the whole lifecycle:

```php
[$batch, $results] = $dn->runBatch([
    ['custom_id' => 'ticket-1', 'state' => 'I was charged twice.', 'questions' => ['refund' => truth('Is a refund asked for?')]],
    ['custom_id' => 'ticket-2', 'state' => 'The app crashes.', 'questions' => ['refund' => truth('Is a refund asked for?')]],
], pollInterval: 60);

foreach ($results as $line) {
    $line->customId;
    $line->decision?->answers['refund']->truth;   // a 200 line
    $line->error;                                 // any other line: the error decide() would have raised, as a value
}
```

It makes an empty draft, adds the requests in calls of at most 10 MiB, finalizes, reads the batch every `pollInterval` seconds (at least 10; the server's `Retry-After` when that is longer) until it ends, then reads the results. A failed add cancels the draft. Past `timeout` (26 hours by default) it throws `TimeoutError`.

Each call is also yours to use, as `$dn->batches->...`:

| Method | Sends | Returns |
|---|---|---|
| `create($requests = [])` | `POST /v1/batches`, JSON Lines; with no requests, an empty draft | `Batch` |
| `add($batchId, $requests)` | `POST /v1/batches/{id}/requests`, split into calls of at most 10 MiB, in order | `Batch` |
| `finalize($batchId)` | `POST /v1/batches/{id}/finalize` | `Batch` |
| `get($batchId)` | `GET /v1/batches/{id}` | `Batch` |
| `list($offset, $limit)` | `GET /v1/batches` | `BatchPage` with `batches`, `offset`, `limit`, `total`, `nextOffset` |
| `results($batchId)` | `GET /v1/batches/{id}/results` | A list of `BatchResult` in the order the requests were added |
| `cancel($batchId)` | `POST /v1/batches/{id}/cancel` | `Batch` |

A request is an array with `custom_id` and the arguments of `decide` (`state`, `questions`, `images`, `model`), or a `BatchRequest`. Every request is checked before anything is sent, and each line's local images follow the image rules above. `create`, `add` and `finalize` are retried only on 429 and 529, never after a timeout or a lost answer, so a batch is never created twice; a `finalize` whose earlier attempt went through returns the batch. The other calls are retried like every call.

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
| 429 | `RateLimitError`; `SessionLimitError` when a key holds as many open sessions as it may | `retryAfter` (seconds) |
| 529 | `OverloadedError` | `retryAfter` (seconds) |
| other 5xx, a 2xx whose body is not JSON, or a 2xx where the answer of `check`, `classify`, `score` or `number` is missing or malformed | `ServerError` | |
| any other status (a 3xx, 405, 409, 410, 413, 408 once its retries are used up) | `DecisionNodeError` itself | |
| an image upload's storage refused the bytes | `DecisionNodeError` itself, `errorType` `upload_failed`, the storage's error code in `reason` | |
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

The SDK checks a request before sending it, so a request the API would refuse costs no round trip. A fault in the request's content raises the class the API would answer with, with no `status` and no `requestId`: `BadRequestError` for an image that is not JPEG, PNG or WebP, over 10 MB or over 40 megapixels, more than 4 images, an image URL that is not `https` or longer than 2,048 characters, a choice with no options or more than 255, a score with more than 10 levels, a truth question with neither instructions nor criteria, a number question without instructions; `ValidationError` for a score with no levels and a number grid with `step` not above 0, `min` above `max` or more than 4,096 values. Token limits stay the server's, since they differ by model and are published at `models()`. `decideRaw` sends its body unchecked.

A problem with the arguments themselves is PHP's own `InvalidArgumentException`, never a `DecisionNodeError`: an empty question map, a value that cannot be encoded as JSON, an image file it cannot read, a `timeout` of 0 or less, a negative `maxRetries` or `inlineThreshold`, a base URL that is not `https`, an `httpClient` the SDK cannot show refuses redirects or one that turns off TLS checks (see [HTTP client](#http-client)), a `$concurrency` below 1, a batch request without a `custom_id`, a `runBatch` poll interval under 10 s.

## Timeouts and retries

Each attempt times out after 10 seconds, counted over the whole attempt: from sending the request to the last byte of the response body, not per network read. A request is retried twice after the first attempt on 408, 429, any 5xx, timeouts and dropped connections, waiting what the server asks for (`retry-after-ms`, else `Retry-After` in seconds or as an HTTP date, never more than 60 s) or else backing off exponentially from 0.5 s with jitter, up to 8 s. 400, 401, 402, 403, 404 and 422 are never retried. A decision changes nothing on the server, so a retry is always safe. An image upload's `PUT` is retried the same way on 5xx and dropped connections. Batch `create`, `add` and `finalize` are the exception: retried only on 429 and 529 (see [Batches](#batches)).

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
| `headers` | | extra headers sent with every API request and session handshake (never with an image upload's `PUT` to storage) |
| `httpClient` | | your own `GuzzleHttp\Client` (rules below) |
| `logger` | | none |
| `inlineThreshold` | | `524288` bytes: local images of a request under it in all go inline, at or above it they are uploaded |

An argument wins over the environment, which wins over the default. An empty string counts as not given, for the client's arguments and for the `$model` of a call. A base URL that is not `https`, from an argument or from the environment, throws `InvalidArgumentException` when the client is made, so the API key never travels unencrypted; plain `http` is accepted only for this machine (`localhost`, `127.0.0.1`, `::1`), for a local test server. Pin a model per client or per call with `decisionnode-1.0` or `decisionnode-1.0-flash`; `decisionnode-latest` and `decisionnode-flash-latest` follow the newest release.

```php
$dn = new DecisionNode(
    apiKey: 'your-api-key',
    model: 'decisionnode-flash-latest',
    headers: ['x-team' => 'billing'], // sent with every request
);
```

### HTTP client

By default the SDK uses Guzzle and reuses its connections, for API requests and image uploads alike. Pass your own `GuzzleHttp\Client` to set a proxy, a CA bundle or other transport options. The SDK's rules still hold with it, and the client is checked when the `DecisionNode` is made:

- **Redirects are never followed.** Every request is sent with redirects switched off, whatever the client is configured to do (curl options you set are kept, except that curl is told not to follow redirects either). A response that came from another host, or a request sent more than once in one attempt (as a middleware that re-sends would do), is refused.
- **Only clients the SDK can inspect.** The client must be a `GuzzleHttp\Client` itself, not a subclass or another `ClientInterface`, and its handler, the code that does the transfer, must be one of Guzzle's own: curl, curl multi, stream or mock, alone or combined by Guzzle as `HandlerStack::create()` does. Any other handler might follow redirects by itself, so it is refused with `InvalidArgumentException`. Middleware you push onto the stack is kept. Other PSR-18 clients are not accepted either.
- **TLS checks stay on.** For an `https` base URL, a client that turns off certificate or host name checks (`'verify' => false`, curl's `CURLOPT_SSL_VERIFYPEER` or `CURLOPT_SSL_VERIFYHOST` off, or a `stream_context` with `verify_peer` or `verify_peer_name` off or `allow_self_signed` on) is refused with `InvalidArgumentException`. Pointing `verify` at your own CA bundle is fine.
- **The timeout covers the whole attempt** with your client too.

A session's socket uses [phrity/websocket](https://packagist.org/packages/phrity/websocket) with PHP's own TLS checks; it follows no redirect, and its URL must be `wss` (plain `ws` only on this machine).

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

The conformance tests run the language-neutral DecisionNode conformance cases (suite 1.4) against their mock server, every case once through the blocking methods and once through their `Async` forms (sessions are blocking in both runs): set `DECISIONNODE_CONFORMANCE_DIR` to the suite's directory and run `composer conformance`. Without that variable the conformance tests fail; they never skip. CI checks the suite out at a pinned commit, set once as `CONFORMANCE_REF` in `.github/workflows/ci.yml`, and every action in the workflow is pinned to a commit SHA.

## License

MIT. See [LICENSE](LICENSE).
