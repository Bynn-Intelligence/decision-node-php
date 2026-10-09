# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.3.0] - Unreleased

Implements version 1.3 of the DecisionNode SDK specification (`SPEC_VERSION` `"1.3"`): boosters and fine-tuned models.

### Added

- Boosters: `decide`, `check`, `classify`, `score`, `number`, `gate` (and their `Async` forms), `decideMany`, `BatchRequest` and `checkRequest` take `boosters`, up to 4 in order: a name (`Booster::AGE`, `Booster::AI_GENERATED`, or any string), a `Booster` (`name`, `images`, `require`, `options`, each left out of the request when not given) or a booster in its wire form. Without boosters the request body is unchanged. Nothing about boosters is checked locally except the API's nesting limit, which counts them. Sessions take no boosters.
- `Decision::$evidence`, by booster name in the request's order: `Evidence` with `version`, `status`, `latencyMs` and `results`, each result an `AgeReading`, an `AiGeneratedReading` (`topGenerator`, null unless the picture reads as likely generated, `c2paSigner` and `c2pa`) or, for a booster this version does not know or a reading with a field missing or of the wrong type, an `UnknownReading` with its raw fields. `null` when the request named no boosters.
- `Usage::$boosterTokens` and `Usage::$boosters`, `null` for a request without boosters.
- `DecisionNodeError::$boosters` on every error: the `x-decisionnode-boosters` header by booster name, in the request's order, each `['status' => ..., 'latency_ms' => ...]`, so a caller sees which required booster failed.
- Fine-tuned models: a fine-tuned model's name is sent as given in `model`, and `Decision::$model` carries the version that answered. `ModelInfo` gains `version`, `fineTuned`, `base`, `adapterVersion` and `status`, each null for a catalogue model.

### Changed

- The booster errors map to the existing classes: `booster_unknown` and `booster_input_mismatch` are a `BadRequestError`, `booster_not_enabled` a `PermissionDeniedError`, and `booster_unavailable` an `OverloadedError`, retried like any 529.
- The conformance tests run suite 1.11.

## [0.2.0] - Unreleased

Implements version 1.2 of the DecisionNode SDK specification (`SPEC_VERSION` `"1.2"`).

### Added

- Videos sent as frames: `Video::of($id, $frames, $fps, $start, $end)` and `VideoFrame::fromData()`, `VideoFrame::fromBase64()` and `VideoFrame::fromPath()`, each frame a JPEG, PNG or WebP image with its time in seconds. `decide`, `check`, `classify`, `score`, `number`, `gate`, `decideMany`, `BatchRequest` and `checkRequest` take `videos` beside `images`; a video in its plain wire form is accepted too. Frames always go inline as base64: never uploaded, never counted toward the inline threshold, never resized or re-encoded. Sessions take no videos.
- The video rules checked before sending, each raising `BadRequestError` (`api_usage_error`) with the server's message: an id that is a non-empty string and unique across images and videos, a non-empty list of frames, each frame's `t` a number of at least 0 and larger than the one before it, a JPEG, PNG or WebP media type, `fps` above 0 and at most 4, `start` and `end` of at least 0, `end` after `start`. Frame counts, frame bytes and pixels are left to the server.
- `Usage::$videoFrames` and `Usage::$videoSeconds`, and `Decision::$warnings` (empty when the API sent none), in decisions and batch results.
- Point and box questions: `point($instructions, $image, $units)` and `box(...)` (also `Question::point()` and `Question::box()`), answered as `PointAnswer` (`x`, `y`, `present`, `confidence`, `image`, `width`, `height`) and `BoxAnswer` (`x1`, `y1`, `x2`, `y2` and the same five). Every field is required: an answer with one missing or of the wrong type is an `UnknownAnswer`. They work in `decide`, `decideMany`, batch requests and `checkRequest`.
- The point and box rules checked before sending, as the API's `ValidationError` with every entry of the request in one list: an image in the request, `image` required with two or more images and naming one of them, `units` of `pixels` or `normalized`. Missing instructions is the API's plain `400`, `Point question must have instructions: <id>`.

### Changed

- Up to 16 images a request (4 before) and up to 4 videos. More is refused before sending as the API's `ValidationError`, one `too_long` entry on `["body", "images"]` or `["body", "videos"]` in the same list as the request's other entries, after the questions' (a local image is shown by its `id` and `media_type`, without its bytes). A lower limit held from `models()` is read from the entry's `max_images`, else its `images.max_images`. One upload call takes all of a request's images to upload, and `upload()` with more than 16 images raises that call's own `BadRequestError`, `files: 1 to 16 files, got 17.`
- `videos` sits before `model` in the argument lists of `decide`, the one-question helpers, `gate` and `BatchRequest`; pass `model` by name.
- The conformance tests run suite 1.10.

## [0.1.0] - Unreleased

First release. Implements version 1.1 of the DecisionNode SDK specification, stated as `DecisionNode::SPEC_VERSION` and `Bynn\DecisionNode\SPEC_VERSION`.

### Added in specification 1.1

- `number()` questions (`Question::number()`, the `number()` helper on the client) and `NumberAnswer` with `number`, `expected`, `confidence` and `probabilities` keyed by the value as the API wrote it. An argument left out of the builder is left out of the request.
- A truth answer is read into `TruthAnswer` whichever of the API's two wire names it carries.
- `models()` keeps every field of a model entry in `limits`, the number, upload, `sessions` and `batch` fields included.
- Images: `Image::fromBase64()`, `Image::fromStream()` and `Image::fromUploadId()`. Local images are checked before any request (JPEG, PNG or WebP by their first bytes, at most 10 MB, at most 40 megapixels read from the header, at most 4 images, unique ids), and image URLs must be `https` and at most 2,048 characters.
- Local images of a request go inline under `DEFAULT_INLINE_THRESHOLD` (524,288 bytes, the `inlineThreshold` client option) and are uploaded at or above it: one `POST /v1/uploads`, one `PUT` each to storage side by side with exactly the headers the API returned, then the request by upload id. Uploads are cached in memory by content hash until 5 minutes before they expire (256 entries), and reused whatever the size. An upload the API reports expired, consumed, not found or incomplete is uploaded again and the request sent once more.
- `upload($images)` uploads whatever the size and returns the upload ids.
- The API's fixed request rules are checked before any request, raising the API's own class (`BadRequestError`, or `ValidationError` for a number grid fault and an empty score) with no status and no request id: choice options, score levels, truth and number questions without instructions, number grids.
- Sessions: `openSession()`, `Session::connect()`, `send()`, `next()`, `replies()`, `end()` and `close()`, with `Reply`, `FrameError` and `SessionClosed` values, and `supervise()` for a long-lived feed. A 429 `session_limit_exceeded` raises the new `SessionLimitError`, a `RateLimitError`, and is never retried. Sessions use `phrity/websocket`.
- The Batch API as `$dn->batches` (`create`, `add`, `finalize`, `get`, `list`, `results`, `cancel`, each with an `Async` form) and `runBatch()`, with `Batch`, `BatchPage`, `BatchResult` and `BatchRequest`. Adds are split at 10 MiB. Create, add and finalize are retried only on 429 and 529.
- `DEFAULT_INLINE_THRESHOLD` as a class constant and as `Bynn\DecisionNode\DEFAULT_INLINE_THRESHOLD`.
- Every fixed rule the API documents is checked before sending, with the class, error type and message the server would answer: unknown question types, the shapes and types of questions and states (a `ValidationError` with the server's entries), every number grid rule, the 128-level nesting limit, repeated or empty image ids, an upload id's media type, image URLs (`image_url_refused` for the length, user information, a port other than 443 and literal private addresses), session `window` and `ttl_seconds`, frames (`Session::send()` throws and the frame takes no `seq`), batch `custom_id` rules and an empty add, and `list` paging. Plain-string refusals carry no error type. Limits only the models list states are checked once `models()` has been called.
- A storage PUT answered 403 after its URL's time has passed is presigned again once.
- Requests running side by side with the same image share one upload.
- The `clock` client option: the time upload URLs and cached uploads are judged by.
- `checkRequest($method, $request)` and `Session::checkFrame()`: every local check a call runs before sending, with the same error, sending and uploading nothing.
- Limits held from `models()` are found by a model's name, or by the entry that names it as its alias or pinned version.
- Storage PUTs have their own timeout, the `uploadTimeout` option (60 s by default), apart from the API attempt's `timeout`.
- `$dn->batches->stream()` reads batch results line by line as the body arrives; `results()` reads the whole file.
- A session's socket takes the HTTP client's TLS settings and its `https` proxy (through a tunnel).

### Changed in specification 1.1

- Image bytes of an unknown format raise `BadRequestError` when the request is made, no longer `InvalidArgumentException` when the `Image` is made.

### Added

- `DecisionNode` client for `POST /v1/decide` and `GET /v1/models`, configured by arguments or the `DECISIONNODE_API_KEY`, `DECISIONNODE_BASE_URL` and `DECISIONNODE_MODEL` environment variables.
- `decide`, `decideMany`, `check`, `classify`, `score`, `gate`, `models` and `decideRaw`, each with a non-blocking `Async` form returning a promise. `check`, `classify`, `score` and `gate` take `images` and `model` like `decide`.
- `gate` asks three questions with fixed wording, identical in every DecisionNode SDK.
- `decideMany` returns a list of `DecisionResult` (a `Decision` or a `DecisionNodeError`). It checks every request before sending any, so a concurrency below 1 or an argument problem in any one request throws `InvalidArgumentException` and nothing is sent.
- `models()` returns a list of `ModelInfo`.
- Question builders `truth()`, `choice()` and `score()`, also as `Question::truth()` and friends. Plain wire-form arrays are accepted wherever a builder is.
- Typed answers: `TruthAnswer`, `ChoiceAnswer`, `ScoreAnswer`, and `UnknownAnswer` for question types this version does not know, all extending `Answer`. In `decide`, an answer of a known type with missing or wrongly typed fields is also an `UnknownAnswer` with its raw fields, and the decision does not fail. `check`, `classify` and `score` promise a typed answer, so for them a missing or malformed answer is a `ServerError` with the response's status, request id and `x-decisionnode-error` reason.
- `Image` from a file path or raw bytes (media type read from the first bytes when not given) or an `https` URL. A file is read when the `Image` is made.
- The safety check result on `Decision::$safety`, parsed from the `x-decisionnode-safety` header. The README shows how to send `x-decisionnode-safety-body: true` to get the scores in the body as well.
- Timeouts (10 s covering the whole attempt, to the last byte of the body) and retries (2 by default) on 408, 429, 5xx, timeouts and dropped connections, honouring `retry-after-ms` and `Retry-After` (seconds or an HTTP date) up to 60 s, with exponential backoff and jitter otherwise.
- Redirects are never followed, also with a Guzzle client of your own that is configured to follow them; a 3xx is a `DecisionNodeError` with its status.
- The base URL must be `https`; plain `http` is accepted only for `localhost`, `127.0.0.1` and `::1`.
- Local argument problems throw PHP's own `InvalidArgumentException` before anything is sent, never a `DecisionNodeError`: an empty question map, a value that cannot be encoded as JSON, an unreadable image file, a `timeout` of 0 or less, a negative `maxRetries`, a base URL that is not `https`, a `decideMany` concurrency below 1. `Async` methods throw them when called.
- Exceptions under `Bynn\DecisionNode\Exception`: `DecisionNodeError` and `BadRequestError`, `ContextLimitError`, `AuthenticationError`, `InsufficientCreditError`, `RefusalError`, `PermissionDeniedError`, `NotFoundError`, `ValidationError`, `RateLimitError`, `OverloadedError`, `ServerError`, `ConnectionError`, `TimeoutError`. An error's message is the server's message, `""` (never null) when the server sent none; any status without its own class, a 3xx included, is a `DecisionNodeError` with that status.
- An empty API key, base URL or model counts as not given: the environment, then the default, applies.
- Guzzle as the HTTP client (the default one, or your own for a proxy or TLS options), and optional PSR-3 debug logging. A caller's client must be a `GuzzleHttp\Client` with one of Guzzle's own handlers (curl, curl multi, stream or mock); any other client or handler type is refused with `InvalidArgumentException`, since the SDK cannot show that it never follows a redirect. A response from another host, or a request sent more than once in one attempt, is refused. Other PSR-18 clients are not accepted.
- For an `https` base URL, a caller's client that turns off TLS certificate or host name checks (`verify` off, curl's peer or host verification off, or an unsafe `stream_context`) is refused with `InvalidArgumentException`.
- The base error is exported under the name `DecisionNodeError`, and every error class extends it.
- The conformance tests run every case of suite 1.6 through the blocking methods and through their `Async` forms, and fail when `DECISIONNODE_CONFORMANCE_DIR` is not set.
- CI pins every action to a commit SHA, checks out with `persist-credentials: false`, and checks the conformance suite out at one pinned commit.
