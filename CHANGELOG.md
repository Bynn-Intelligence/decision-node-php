# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

First release. Implements version 1.1 of the DecisionNode SDK specification, stated as `DecisionNode::SPEC_VERSION` and `Bynn\DecisionNode\SPEC_VERSION`.

### Added in specification 1.1

- `number()` questions (`Question::number()`, the `number()` helper on the client) and `NumberAnswer` with `number`, `expected`, `confidence` and `probabilities` keyed by the value as the API wrote it. An argument left out of the builder is left out of the request.
- `systemone()` for `POST /v1/systemone`, which always names a model.
- A truth answer is read into `TruthAnswer` whichever of the API's two wire names it carries.
- `models()` keeps every field of a model entry in `limits`, the number, upload, `sessions` and `batch` fields included.
- Images: `Image::fromBase64()`, `Image::fromStream()` and `Image::fromUploadId()`. Local images are checked before any request (JPEG, PNG or WebP by their first bytes, at most 10 MB, at most 40 megapixels read from the header, at most 4 images, unique ids), and image URLs must be `https` and at most 2,048 characters.
- Local images of a request go inline under `DEFAULT_INLINE_THRESHOLD` (524,288 bytes, the `inlineThreshold` client option) and are uploaded at or above it: one `POST /v1/uploads`, one `PUT` each to storage side by side with exactly the headers the API returned, then the request by upload id. Uploads are cached in memory by content hash until 5 minutes before they expire (256 entries), and reused whatever the size. An upload the API reports expired, consumed, not found or incomplete is uploaded again and the request sent once more.
- `upload($images)` uploads whatever the size and returns the upload ids.
- The API's fixed request rules are checked before any request, raising the API's own class (`BadRequestError`, or `ValidationError` for a number grid fault and an empty score) with no status and no request id: choice options, score levels, truth and number questions without instructions, number grids.
- Sessions: `openSession()`, `Session::connect()`, `send()`, `next()`, `replies()`, `end()` and `close()`, with `Reply`, `FrameError` and `SessionClosed` values, and `supervise()` for a long-lived feed. A 429 `session_limit_exceeded` raises the new `SessionLimitError`, a `RateLimitError`, and is never retried. Sessions use `phrity/websocket`.
- The Batch API as `$dn->batches` (`create`, `add`, `finalize`, `get`, `list`, `results`, `cancel`, each with an `Async` form) and `runBatch()`, with `Batch`, `BatchPage`, `BatchResult` and `BatchRequest`. Adds are split at 10 MiB. Create, add and finalize are retried only on 429 and 529.
- `DEFAULT_INLINE_THRESHOLD` as a class constant and as `Bynn\DecisionNode\DEFAULT_INLINE_THRESHOLD`.
- Every fixed rule the API documents is checked before sending, with the class, error type and message the server would answer: unknown question types (a number question on `systemone` too), the shapes and types of questions and states (a `ValidationError` with the server's entries), every number grid rule, the 128-level nesting limit, repeated or empty image ids, an upload id's media type, image URLs (`image_url_refused` for the length, user information, a port other than 443 and literal private addresses), session `window` and `ttl_seconds`, frames (`Session::send()` throws and the frame takes no `seq`), batch `custom_id` rules and an empty add, and `list` paging. Plain-string refusals carry no error type. Limits only the models list states are checked once `models()` has been called.
- A storage PUT answered 403 after its URL's time has passed is presigned again once.
- Requests running side by side with the same image share one upload.
- The `clock` client option: the time upload URLs and cached uploads are judged by.

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
- The conformance tests run every case of suite 1.5 through the blocking methods and through their `Async` forms, and fail when `DECISIONNODE_CONFORMANCE_DIR` is not set.
- CI pins every action to a commit SHA, checks out with `persist-credentials: false`, and checks the conformance suite out at one pinned commit.
