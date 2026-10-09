# DecisionNode PHP SDK

The PHP client for the [DecisionNode](https://decisionnode.com) API: ask yes-or-no, choice, score and number questions about any text, JSON, image or video, point to a spot or draw a box in an image, and get probabilities back. One-off decisions, live sessions that answer a stream of frames, and batches at half the price. Boosters add specialist readings of your images (an age, whether a picture was AI-generated) to a decision as evidence, and your own fine-tuned models are called by name.

Implements version 1.3 of the DecisionNode SDK specification, which the package states as `DecisionNode::SPEC_VERSION` (also `Bynn\DecisionNode\SPEC_VERSION`). Requires PHP 8.2 or later.

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
| `point($instructions, $image, $units)` | `PointAnswer` | `x`, `y`, `present`, `confidence`, `image`, `width`, `height` (see [Points and boxes](#points-and-boxes)) |
| `box($instructions, $image, $units)` | `BoxAnswer` | `x1`, `y1`, `x2`, `y2`, `present`, `confidence`, `image`, `width`, `height` |

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

### Points and boxes

A `point` question finds one spot in an image and a `box` question a rectangle around something, for a robot to pick, a camera to frame or a reviewer to check.

```php
use Bynn\DecisionNode\Image;
use function Bynn\DecisionNode\{box, point, truth};

$d = $dn->decide(
    images: [Image::fromUrl('belt', 'https://files.example.com/line-3/cam-2/0917.jpg')],
    questions: [
        'pick' => point('Point to the yellow package closest to the robot arm.'),
        'damage' => box('Draw a box around the damaged package.', image: 'belt'),
        'any_damage' => truth('Is any package on the belt damaged?'),
    ],
);

$d['pick']->x;              // 812
$d['pick']->y;              // 455
$d['pick']->present;        // 0.97
$d['damage']->x1;           // 300 (and y1, x2, y2)
$d['damage']->confidence;   // 0.88
$d['any_damage']->truth;    // 0.93
```

- `$image` is the id of the image to look in, a string. With one image in the request it can be left out; with two or more it is required. A reference by number ("Picture 2") goes in the instructions.
- `$units` is `pixels` (the default: pixels of the image as sent, after its EXIF orientation) or `normalized` (fractions of the width and height, from 0 to 1).
- `x` and `y`, and the corners `x1`, `y1` (top left) and `x2`, `y2` (bottom right), count from the top-left corner of the image, `x` to the right and `y` down.
- `present` is the probability that what the instructions describe is in the image at all. Under a low `present` the place is only the best guess for something that is probably not there.
- `confidence` is the probability that the place is right if the thing is there: for a point, that it lies on the thing; for a box, that it overlaps the true box by an intersection over union of 0.5 or more.
- `image`, `width` and `height` name the image looked in and its size in pixels, so the answer can be drawn or converted without another lookup.

Every field is always there and every number is the one the API sent. Point and box questions work in `decide`, `decideMany` and batch requests, beside any other question type. A request with one needs at least one image.

Answers are read by question id, `$d['route']` or `$d->answers['route']`. An answer of a question type this SDK version does not know yet comes back as an `UnknownAnswer` with its `type` and `raw` fields. In `decide`, so does an answer of a known type whose fields are missing or of the wrong type (a choice without `probabilities`, a truth whose value is a string): the rest of the decision is read as usual. The one-question helpers `check`, `classify`, `score` and `number` promise a typed answer, so for them a missing or malformed answer is a `ServerError` (see below). Every answer class extends `Answer`.

## The client

| Method | Does | Returns |
|---|---|---|
| `decide($state, $questions, $images, $videos, $model, $boosters, $fallback)` | One `POST /v1/decide` | `Decision` |
| `decideMany($requests, $concurrency = 8)` | One decision per request, at most `$concurrency` at a time | A list of `DecisionResult` in input order: each a `Decision` or the `DecisionNodeError` that request ended with |
| `check($state, $instructions, $ifTrue, $ifFalse)` | One `truth` question | `TruthAnswer`; a `ServerError` when the answer is missing or malformed |
| `classify($state, $instructions, $options)` | One `choice` question | `ChoiceAnswer`; a `ServerError` when the answer is missing or malformed |
| `score($state, $instructions, $levels)` | One `score` question | `ScoreAnswer`; a `ServerError` when the answer is missing or malformed |
| `number($state, $instructions, $min, $max, $step)` | One `number` question | `NumberAnswer`; a `ServerError` when the answer is missing or malformed |
| `gate($action, $context)` | Whether an action should run: a `risk` score, a `touches_production` truth and an `allow`, `confirm` or `block` verdict (wording below) | `Decision` |
| `models()` | `GET /v1/models` | A list of `ModelInfo` with `name`, `description`, `releaseDate`, `version` and `limits` (every other field of the entry, such as `context_tokens`, `number_max_grid_values`, `upload_max_bytes`, `sessions` and `batch`); your fine-tuned models also have `fineTuned`, `base`, `adapterVersion` and `status` (see [Fine-tuned models](#fine-tuned-models)) |
| `upload($images)` | Uploads local images whatever their size | One upload id per image |
| `openSession(...)` | `POST /v1/sessions` | `Session` (see [Sessions](#sessions)) |
| `supervise($body, $feed, $onReply)` | Keeps a session running over a long feed | The last `SessionClosed`, or `null` |
| `batches->create(...)` and the other batch calls, `runBatch(...)` | The Batch API | `Batch`, `BatchResult` (see [Batches](#batches)) |
| `decideRaw($body)` | Sends `$body` exactly as given | The response body as an array |

`check`, `classify`, `score`, `number` and `gate` also take `$images`, `$videos`, `$model`, `$boosters` and `$fallback`, like `decide`. `$state` is a string or any JSON value; leaving it out sends an empty string. `decideMany` checks every request before it sends any: a `$concurrency` below 1, an argument problem in any one of the requests (an empty question map, say), or a question, image or video the API would refuse throws, and nothing is sent, not even the valid requests.

`gate` sends the state `{"action": ..., "context": ...}` and these three questions, word for word the same in every DecisionNode SDK:

| Id | Type | Instructions | Levels or options |
|---|---|---|---|
| `risk` | score | How much harm could this action cause if it is wrong? | `none`, `low`, `moderate`, `high`, `severe` |
| `touches_production` | truth | Does this action change a production system, production data or anything customers use? | |
| `verdict` | choice | Should this action run as it is, wait for a person to confirm it, or be blocked? | `allow` "safe to run without asking", `confirm` "a person should confirm it first", `block` "should not run" |

A `Decision` has `answers`, `model` (the resolved model version), `usage->inputTokens`, `usage->outputTokens`, `usage->videoFrames` and `usage->videoSeconds` (for a request with videos, `null` otherwise), `warnings` (a list of strings, empty unless the API sent some), `evidence`, `usage->boosterTokens` and `usage->boosters` (for a request with boosters, `null` otherwise; see [Boosters](#boosters)), `requestId`, `latencyMs` (the server's), `safety` and `raw` (the parsed body).

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

A URL must be `https`, at most 2,048 characters, on port 443, without user information and not a literal private address; the SDK checks that and sends it as it is, without fetching it. Local images work too:

```php
Image::fromPath('receipt', __DIR__ . '/receipt.jpg');
Image::fromData('photo', $bytes);                 // media type read from the bytes
Image::fromData('scan', $bytes, 'image/png');      // a given media type must match the bytes
Image::fromBase64('scan', $base64);
Image::fromStream('scan', fopen('scan.webp', 'rb'));  // a resource or a PSR-7 stream
Image::fromUploadId('scan', 'upl_...');           // an upload made earlier, within its hour
```

A file or stream is read when the `Image` is made, so a missing or unreadable file throws `InvalidArgumentException` there. Before any request, the SDK checks every local image against the API's rules and raises the API's own `BadRequestError` for a fault, without a round trip: JPEG, PNG or WebP by its first bytes, at most 10 MB, at most 40 megapixels read from the image header (the image is never decoded), ids unique. More than 16 images in a request (URLs included) is the API's `ValidationError`, raised before sending too. No image is resized or re-encoded.

How a local image travels is chosen for the shortest response time:

- **Small images go inline.** When a request's local images total under 512 KiB (`DecisionNode::DEFAULT_INLINE_THRESHOLD`, 524,288 bytes), they are sent as base64 inside the request.
- **Larger ones are uploaded once.** At or above that size, the SDK uploads them first, with one `POST /v1/uploads` for all of them and one `PUT` each straight to storage, side by side, then sends the request naming them by upload id. The `PUT` carries only the headers the API returned: never your key, never your custom headers, and no redirect is followed.
- **An upload is shared and reused.** Requests running side by side with the same image share one upload. The client remembers its uploads by the SHA-256 of the bytes, in memory, for the upload's hour (up to 5 minutes before it expires, at most 256 images). The same image in a later request, a `decideMany`, a session frame or a batch line is sent by its upload id whatever its size, with nothing uploaded again. If the API answers that an upload the SDK placed has expired or was used up, the SDK uploads the image again and sends the request once more.

A request body may be at most 25 MiB (26,214,400 bytes): inline images and video frames count toward it, uploads and URLs do not. A body over it is refused before anything is sent or uploaded, with a `RequestTooLargeError`: it is measured as it would go out, an image to upload by its upload id. Set `inlineThreshold` on the client to change the threshold (`0` uploads every local image). `upload($images)` uploads whatever the size and returns the upload ids, for a pipeline that wants to upload first and decide later.

How many large images fit in one request is the model's token limit, which the server checks: a request over it is a `ContextLimitError`.

### Referring to images and videos

There are two ways to say which image a question is about:

1. **By id (recommended).** Give each image a short, meaningful id and use it in the instructions, in backticks: "Does the total on `receipt` match the amount in the claim?"
2. **By number.** Images are numbered from 1 in the order of `images`: "image 2", "the second image", "Picture 2" and "the last image" all work.

The model sees each image as `Picture 1 (receipt):`, `Picture 2 (claim_photo):`, so both ways point to the same image. Prefer ids: they stay right when images are added or reordered. Use names, not digits, as ids (`front`, not `2`). Videos work the same way, by id or by number ("video 1"), numbered separately in the order of `videos`. The `image` of a point or box question takes the id.

```php
$d = $dn->decide(
    state: 'Expense claim 4411: 740.00 EUR.',
    images: [
        Image::fromUrl('receipt', 'https://files.example.com/claims/4411/receipt.jpg'),
        Image::fromUrl('claim_photo', 'https://files.example.com/claims/4411/photo.jpg'),
    ],
    questions: ['matches' => truth('Does the total on `receipt` match the amount in the claim?')],
);
```

## Videos

A video is sent as frames: still images you already hold, such as a camera's JPEG snapshots, each with its time in seconds. Only the frames go to the API: no video file, no video URL and no sound.

```php
use Bynn\DecisionNode\{Video, VideoFrame};

$d = $dn->decide(
    state: 'Warehouse camera 4, loading dock, shift B.',
    videos: [
        Video::of('dock', [
            VideoFrame::fromPath(30.0, '/frames/dock-0001.jpg'),
            VideoFrame::fromPath(30.5, '/frames/dock-0002.jpg'),
            VideoFrame::fromData(31.0, $jpegBytes),
        ]),
    ],
    questions: ['fall' => truth('Does a person fall in the video?')],
);

$d['fall']->truth;
$d->usage->videoFrames;    // the frames the API read
$d->usage->videoSeconds;   // the seconds of video they cover
$d->warnings;              // for example, a video read at a lower rate than asked
```

The API reads the frames of a video as one sequence, in order and with their times, and every question sees every video, every image and the state.

```php
VideoFrame::fromData(0.5, $bytes);                   // JPEG, PNG or WebP; the media type is read from the bytes
VideoFrame::fromBase64(0.5, $base64, 'image/jpeg');  // base64 text, sent as given, with its media type
VideoFrame::fromPath(0.5, '/frames/0002.jpg');       // read when the VideoFrame is made

Video::of('dock', $frames, fps: 4, start: 30, end: 60);
```

- `t` is the frame's time in seconds, at least 0 and larger than the frame before it. Seconds from the start of the clip are the usual form; a camera's own clock works too.
- `fps` caps how many frames a second the API reads (above 0, at most 4; 2 when left out). A frame closer to the one read before it than 1/fps seconds is skipped.
- `start` and `end` choose the window `[start, end)` on the frames' own times. Left out, every frame is read.
- Up to 4 videos a request, beside up to 16 images. Ids are unique across a request's images and videos.
- Frames always go inline as base64: they are never uploaded, never counted toward the inline threshold of images, and never resized or re-encoded.

Before sending, the SDK checks the rules above and raises the API's own error without a round trip: more than 4 videos is a `ValidationError`, as the API answers it; a missing or repeated id, no frames, a time that is negative or not larger than the one before it, a media type that is not JPEG, PNG or WebP, an `fps` outside the range, a negative `start` or `end` and an `end` not after `start` are a `BadRequestError` (`api_usage_error`) with the server's message. How many frames a request may carry, each frame's bytes and size, and the token budget are the server's to judge.

Videos work in `decide`, the one-question helpers, `gate`, `decideMany`, batch requests and `checkRequest`. Sessions take no videos. A video in its plain wire form, `['id' => 'dock', 'fps' => 2, 'frames' => [['t' => 0.0, 'media_type' => 'image/jpeg', 'data' => $base64]]]`, is accepted wherever a `Video` is.

To turn a video file into frames, use the `dn` command line, which samples the file on your machine and sends only the frames:

```sh
dn truth "Does a person fall in the video?" --video dock.mp4 --fps 2
```

## Boosters

A booster is a specialist model that reads the request's images before the decision. Its reading is added to what the model sees as evidence, and comes back in the response. The model still answers every question: a booster never answers one, and a request without boosters is answered exactly as before.

| Booster | Reads | Each reading |
|---|---|---|
| `age` (`Booster::AGE`) | A picture of a person; the largest face is read | `AgeReading`: `image`, `faces`, and `ageYears`, `low`, `high` (the range, in years) and `confidence`, each `null` when `faces` is 0 |
| `ai_generated` (`Booster::AI_GENERATED`) | Any picture | `AiGeneratedReading`: `image`, `generated` (the probability that an image generator made it), `confidence`, `method` (`inference`, read from the pixels, or `c2pa`, the file's Content Credentials say so), `topGenerator` (`['name' => ..., 'probability' => ...]`, `null` unless the picture reads as likely generated, and on every `c2pa` reading), `c2paSigner` (who signed the file's Content Credentials, `null` unless they are trusted) and `c2pa` (`untrusted` when the file carries Content Credentials that are not trusted, else `null`) |

```php
use Bynn\DecisionNode\{AgeReading, AiGeneratedReading, Image};

$d = $dn->decide(
    images: [
        Image::fromUrl('selfie', 'https://files.example.com/signup/88/selfie.jpg'),
        Image::fromUrl('listing', 'https://files.example.com/listings/4411/photo.jpg'),
    ],
    boosters: ['age', 'ai_generated'],
    questions: [
        'adult' => truth('Is the person in `selfie` an adult?'),
        'synthetic' => truth('Is `listing` AI-generated?'),
    ],
);

$d['adult']->truth;
foreach ($d->evidence['age']->results as $reading) {
    if ($reading instanceof AgeReading && $reading->faces > 0) {
        echo "{$reading->image}: about {$reading->ageYears} ({$reading->low} to {$reading->high})\n";
    }
}
foreach ($d->evidence['ai_generated']->results as $reading) {
    if ($reading instanceof AiGeneratedReading) {
        echo "{$reading->image}: {$reading->generated} by {$reading->method}\n";
    }
}
$d->usage->boosterTokens;  // the readings' share of the input tokens
$d->usage->boosters;       // the booster calls billed
```

`$boosters` takes up to 4 boosters, each at most once, in the order you give, usually by name (`Booster::AGE` and `Booster::AI_GENERATED` name the two). Names are plain strings, so a booster added to the API later works with this version too. A booster that needs a setting is a `Booster`:

```php
use Bynn\DecisionNode\Booster;

$boosters = ['age', new Booster('ai_generated', images: ['listing'], require: true)];
```

It is sent as `{"name": "ai_generated", "images": ["listing"], "require": true}`, and a booster in that wire form (`['name' => 'age', 'require' => true]`) is accepted too. A `Booster` with no option set is sent as the bare name.

| `new Booster(...)` argument | Sends |
|---|---|
| `name` | The booster's name. |
| `images` | The ids of the request's images the booster reads. Left out: every image of the request. |
| `require` | `true`: a booster that fails or does not answer in time fails the request with a 529 `OverloadedError` whose `errorType` is `booster_unavailable`, retried like any 529. Left out or `false`: the decision runs without that reading, and its evidence says why. |
| `options` | The booster's settings. Neither booster has any today. |

`$d->evidence` holds one `Evidence` per booster the request named, by name, in the request's order: `version` (the booster's version, such as `age-v3`), `status` (`ok`; `timeout`, no answer in its time; `error`; `skipped`, none of the request's images is one it reads), `latencyMs`, `billedCalls` (the calls of that booster billed on this request) and `results`, one reading per picture the booster was to read. A reading of a booster this version does not know, or one with a field missing or of the wrong type, is an `UnknownReading` whose `raw` holds its fields; the decision does not fail. So is a picture the booster did not read (it timed out or failed: `['image' => 'selfie']`, `status` says why) or could not decode (`['image' => 'selfie', 'readable' => false]`); neither is billed. Fields a reading adds that this version does not know are ignored. Without boosters, `evidence` and both usage fields are `null`.

Boosters read images in every form an image takes: URL, inline bytes and upload. Every question sees every reading. The one-question helpers, `gate`, `decideMany`, batch requests and `checkRequest` take boosters as `decide` does; sessions do not. The SDK checks nothing about boosters before sending: which boosters exist and which your workspace may use is the API's to answer, before any booster runs and before any charge. Every booster is on for a new workspace, and an owner or admin can turn one off in the console.

Booster calls are billed one per booster and image read with status `ok`, at the price per call on [Pricing](https://decisionnode.com/docs/pricing#boosters). A reading is billed every time a request uses it, one the API had already made for your workspace included; a booster that timed out or failed is free, and a request the safety check refuses pays for none. The readings' tokens are input tokens at the model's price.

## Fallback

When no worker can take a request for `decisionnode-1.0` at once, the API answers it with `decisionnode-1.0-flash` rather than make it wait. `$d->model` is then the model that answered, and `$d->fallback` says so: a `Fallback` with `servedModel` and `requestedModel`, from the `x-decisionnode-fallback` and `x-decisionnode-requested-model` headers; on an ordinary answer it is `null`. To have such a request wait for its own model instead, pass `fallback: false` (the helpers, `decideMany`'s requests and `BatchRequest` take it too): it then gets the 529, an `OverloadedError` retried like any other. Left out, nothing is sent and the API's default applies.

## Fine-tuned models

A model you fine-tune on your own labelled decisions, in the console, is called by its name as `$model`: `decisionnode-1.0-flash@acme/fraud` (the version deployed most recently) or `decisionnode-1.0-flash@acme/fraud:3` (one version). The name is sent as given; only your workspace's keys can call it, and a name the API does not know for your workspace is a `BadRequestError`, "Unknown model: <name>". `$d->model` carries the name pinned to the version that answered. `models()` lists your fine-tuned models beside the catalogue's, with `fineTuned` true (null for a catalogue model), `base` (the model it was trained from), `adapterVersion` (its version number) and `status`: `deployed` answers decisions, `ready` is trained and not deployed.

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
| `results($batchId)` | `GET /v1/batches/{id}/results`, the whole file | A list of `BatchResult` in the order the requests were added |
| `stream($batchId)` | The same, read line by line as the body arrives | A generator of `BatchResult`, so a large results file is never held in memory whole |
| `cancel($batchId)` | `POST /v1/batches/{id}/cancel` | `Batch` |

A request is an array with `custom_id` and the arguments of `decide` (`state`, `questions`, `images`, `videos`, `model`), or a `BatchRequest`. Every request is checked before anything is sent, and each line's local images follow the image rules above; a line's videos go with it, frames inline. `create`, `add` and `finalize` are retried only on 429 and 529, never after a timeout or a lost answer, so a batch is never created twice; a `finalize` whose earlier attempt went through returns the batch. The other calls are retried like every call.

## Errors

Every error derives from `Bynn\DecisionNode\Exception\DecisionNodeError`, so `catch (DecisionNodeError $e)` catches them all. Its message, `getMessage()`, is the server's message exactly as sent (empty when the server sent none), or the SDK's description when there was no response or the response could not be read. It also has `status`, `errorType`, `requestId` and `reason` (the server's explanation from the `x-decisionnode-error` header, for example "question 'q': a score takes up to 10 levels, got 11").

An error whose body is not JSON (a page from a proxy or the service's edge, or no body) is classed by its status alone, as in the table: a 413 is a `RequestTooLargeError`, a 400 a `BadRequestError` (never a `ContextLimitError`), a 403 a `PermissionDeniedError` (never a `RefusalError`), a 422 a `ValidationError` with no entries. `errorType` is `null`, `requestId` and `reason` come from their headers, nothing is read from the body, and the message is `HTTP <status> <text>`, then ` (request id <id>)` when the response has one: `HTTP 413 Content Too Large`, `HTTP 408 Request Timeout (request id req_...)`. Retries are as for any response: a 408 is retried, a 413 never.

Only a 2xx response with a JSON body is a success. Redirects are never followed, so your key never goes to another URL or over plain HTTP.

| Status | Exception | Extra fields |
|---|---|---|
| 400 | `BadRequestError`; `ContextLimitError` when the request is larger than the model's input limit | |
| 401 | `AuthenticationError` (also raised before sending when no API key is set) | |
| 402 | `InsufficientCreditError` | |
| 403 | `RefusalError` when the request is refused under the usage policy; `PermissionDeniedError` otherwise (`booster_not_enabled`, a suspended workspace) | `category` (`harm` or `self_harm`), `safety` |
| 404 | `NotFoundError` | |
| 413 | `RequestTooLargeError`, never retried | |
| 422 | `ValidationError` | `entries` |
| 429 | `RateLimitError`; `SessionLimitError` when a key holds as many open sessions as it may | `retryAfter` (seconds) |
| 529 (also `booster_unavailable`) | `OverloadedError` | `retryAfter` (seconds) |
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

A booster the API does not offer is a `BadRequestError` with `errorType` `booster_unknown`, and a booster with no image to read (or naming an image the request does not have) `booster_input_mismatch`; a malformed `$boosters` list is `api_usage_error` with the reason in `reason`. Every error of a request that named boosters carries `boosters`: how each booster ended, from the `x-decisionnode-boosters` header, by name in the request's order (`['age' => ['status' => 'timeout', 'latency_ms' => 800]]`), so you can see which required booster failed.

The SDK checks every fixed rule the API documents before sending, so the server never sees a request the client could have refused and a fault costs no round trip. A local error has the class, `errorType` and message the server would answer with, and no `status` or `requestId`:

| Fault | Raised as |
|---|---|
| A choice with no options or more than 255, a score with more than 10 levels, a truth question with neither instructions nor criteria, a number, point or box question without instructions | `BadRequestError` with the API's message and no `errorType` |
| An unknown question type, objects and arrays nested more than 128 deep, an image fault (not JPEG, PNG or WebP, over 10 MB or 40 megapixels, an empty id, an id over 64 characters counted as code points ("Image 1: its id is longer than 64 characters.", images counted from 0), a repeated id, an image URL that is not `https`), a video fault (see [Videos](#videos)), an upload id given with another media type, a batch request without a valid or with a repeated `custom_id`, an empty `add`, `list` paging out of range | `BadRequestError`, `errorType` `api_usage_error` |
| A request body, or a batch line, over 25 MiB (26,214,400 bytes): a long state, many video frames, inline images | `RequestTooLargeError`, `errorType` `request_too_large_error` |
| An image URL over 2,048 characters, with user information, on a port other than 443, or on a literal private, loopback or reserved address | `BadRequestError`, `errorType` `image_url_refused` |
| More than 16 images or more than 4 videos in a request (a held lower image limit once `models()` has been called): one `too_long` entry for each, after the questions' entries | `ValidationError` |
| A point or box question in a request without images, without `image` when the request has two or more, or with an `image` that is not one of the request's image ids, or `units` other than `pixels` and `normalized` | `ValidationError` with every such entry of the request in one list |
| A question or state of the wrong shape or type, a score with no levels, a number grid fault (`min`, `max` or `step` not a number, beyond 2^53, more than 6 decimals, `step` not above 0, `min` above `max`, more than 4,096 values), a session `window` outside 1 to 64 or `ttl_seconds` outside 1 to 3,600 | `ValidationError` with the `entries` the API would list |

A frame that breaks a rule (no frame and no image, an empty frame, an unknown or empty question list, a `seq` out of range, an image fault, an image by URL) makes `Session::send()` throw `BadRequestError` (`api_usage_error`): nothing goes on the socket and the frame takes no `seq`. Once `models()` has been called, the limits it lists (a model that answers no number questions or serves no sessions, `max_window`, `requests_per_batch`, `max_custom_id_chars`) are checked too; until then they are left to the server. A model is found in the held list by its name, or by the entry that names it as its alias or pinned version; a model the list does not name is left to the server. Token limits stay the server's, since they differ by model. `decideRaw` sends its body unchecked.

To check a request without sending it, for example while a user is still editing it, call `checkRequest()` with the call's name and arguments, or `checkFrame()` on a session with `send()`'s arguments. Each runs every check sending would run, throws the same error, sends and uploads nothing, and returns when the request would be sent:

```php
$dn->checkRequest('decide', ['state' => $state, 'questions' => $questions, 'images' => $images, 'videos' => $videos]);
$dn->checkRequest('openSession', ['questions' => $questions, 'window' => 16]);
$dn->checkRequest('batches.create', ['requests' => $requests]);   // also batches.add
$session->checkFrame(frame: $reading, questions: ['abort']);
```

A problem with the arguments themselves is PHP's own `InvalidArgumentException`, never a `DecisionNodeError`: an empty question map, a value that cannot be encoded as JSON, an image file it cannot read, a `timeout` of 0 or less, a negative `maxRetries` or `inlineThreshold`, a base URL that is not `https`, an `httpClient` the SDK cannot show refuses redirects or one that turns off TLS checks (see [HTTP client](#http-client)), a `$concurrency` below 1, a batch request without a `custom_id`, a `runBatch` poll interval under 10 s.

## Timeouts and retries

Each attempt times out after 10 seconds, counted over the whole attempt: from sending the request to the last byte of the response body, not per network read. A request is retried twice after the first attempt on 408, 429, any 5xx, timeouts and dropped connections, waiting what the server asks for (`retry-after-ms`, else `Retry-After` in seconds or as an HTTP date, never more than 60 s) or else backing off exponentially from 0.5 s with jitter, up to 8 s. 400, 401, 402, 403, 404 and 422 are never retried. A decision changes nothing on the server, so a retry is always safe. An image upload's `PUT` is retried the same way on 5xx and dropped connections, and has its own timeout, `uploadTimeout` (60 s by default), since an image of several MB may take longer than an API attempt is allowed. Batch `create`, `add` and `finalize` are the exception: retried only on 429 and 529 (see [Batches](#batches)).

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
| `uploadTimeout` | | `60.0` seconds for one storage `PUT` of an image upload; above 0 |
| `clock` | | the system clock: a `Closure` returning the Unix time in seconds, which upload URLs and cached uploads are judged by (for tests) |

An argument wins over the environment, which wins over the default. An empty string counts as not given, for the client's arguments and for the `$model` of a call. A base URL that is not `https`, from an argument or from the environment, throws `InvalidArgumentException` when the client is made, so the API key never travels unencrypted; plain `http` is accepted only for this machine (`localhost`, `127.0.0.1`, `::1`), for a local test server. Pin a model per client or per call with `decisionnode-1.0` or `decisionnode-1.0-flash`; `decisionnode-latest` and `decisionnode-flash-latest` follow the newest release. A [fine-tuned model](#fine-tuned-models) of yours goes in `model` too.

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

A session's socket uses [phrity/websocket](https://packagist.org/packages/phrity/websocket). It takes your client's TLS settings (a CA bundle given as `verify`, a client certificate and key) and its `https` proxy, through which it opens a tunnel (`proxy` given as a string, or as an array with `https` and the `no` hosts that bypass it); certificate and host name checks stay on. It follows no redirect, and its URL must be `wss` (plain `ws` only on this machine).

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

The conformance tests run the language-neutral DecisionNode conformance cases (suite 1.11) against their mock server, every case once through the blocking methods and once through their `Async` forms (sessions are blocking in both runs): set `DECISIONNODE_CONFORMANCE_DIR` to the suite's directory and run `composer conformance`. Without that variable the conformance tests fail; they never skip. CI checks the suite out at a pinned commit, set once as `CONFORMANCE_REF` in `.github/workflows/ci.yml`, and every action in the workflow is pinned to a commit SHA.

## License

MIT. See [LICENSE](LICENSE).
