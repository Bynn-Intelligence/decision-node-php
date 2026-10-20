# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.4.0] - Unreleased

Implements version 1.4 of the DecisionNode SDK specification (`SPEC_VERSION` `"1.4"`): knowledge.

### Added

- Knowledge bases: `collections->create($name, type: 'text', scopeKey: ...)`; `Collection` gains `scopeKey`, `chunks`, `tokens`, `version`, `statusCounts` and `pinned`, each null on a face collection. A type other than `face` or `text` and a scope key not 1 to 40 of `a-z 0-9 _` are refused before anything is sent, with the API's entries.
- Documents: `Document::fromText($text, $format)`, `fromPath()`, `fromData()`, `fromStream()` and `fromUploadId()`; `collections->addDocument()` and `replaceDocument()` (with `title`, `reference`, `pinned`, `pinRank`, `label`, `metadata` and `wait`, each sent only when given, and an `Idempotency-Key`). Text in memory, and a text or Markdown file under the inline threshold that is UTF-8, are sent inline (a file titled by its name's stem); every other file is uploaded with `"purpose": "knowledge"` (its type from `contentType`, the file name's extension or its first bytes) and named by its `upload_id`. A stale upload the call made is uploaded again once, with a new key; `upload_purpose_mismatch` is raised at once. A document upload's `PUT` has 120 s per attempt unless `uploadTimeout` is given.
- `$dn->uploadDocuments([...])`: 1 to 16 documents uploaded ahead of time, one upload id each.
- `collections->updateItem()` takes `pinned`, `pinRank` and `title`; `getItem($collectionId, $itemId, includeText:)`; `listChunks()` returning a `ChunkPage` of `Chunk`s; `CollectionItem` gains the document's fields (`title`, `format`, `pinned`, `pinRank`, `source`, `file`, `status`, `stage`, `progress`, `serving`, `error`, `delayed`, `warnings`, `chunks`, `tokens`, `contentSha256`, `version`, `queuedAt`, `readyAt`, `text`); `Deleted::$status` (`purging` while a knowledge base's purge finishes).
- `collections->listItems()` takes `q` (1 to 200 characters) and `sort` (`newest`, `oldest` or `title`), each sent only when given and checked locally with the API's messages; `CollectionItemPage` gains `q` and `sort` as sent.
- `collections->deleteFailed($collectionId)`: `DELETE /v1/collections/{id}/items?status=failed`, every failed document deleted at once, answering an `ItemsDeleted` with the count `deleted`; retried as any call.
- `collections->waitUntilReady($collectionId, $itemId, $timeout, $pollInterval)`: reads a document until it is `ready` or `failed` (a failed document is returned, not raised), or without an item id a knowledge base until nothing is queued or processing; 1 s between reads, growing by half each time up to 10 s, for at most 300 s counted on the clock from the call, then `TimeoutError`. A document whose type cannot be told from its name or its first bytes is an `InvalidArgumentException`.
- `collections->search($collectionId, $query, ...)`, returning a `KnowledgeReading` with every passage's text.
- The `knowledge` booster: `Booster::KNOWLEDGE` and `Booster::knowledge($collection, query:, scope:, match:, filter:, topK:, maxTokens:, pinned:, minScore:, includeText:, require:)`, every option sent only when given and checked by the API. Its reading is a `KnowledgeReading` of `KnowledgePassage`s, each citing its document, chunk and UTF-8 byte offsets; a malformed reading or passage is an `UnknownReading`.
- Local checks for documents, as the API answers them: a title not 1 to 200 characters or missing, an empty text, a `pinRank` outside 0 to 1,000, a `format` other than `markdown` or `text`, the reference, label and metadata rules, and a file that is empty, over 50 MB or of a type a knowledge base does not take.
- A document call with `wait` has 20 s per attempt unless `timeout` is given. A 529 with the reason `ingestion_delayed` is never retried.

## [0.3.0] - Unreleased

Implements version 1.3 of the DecisionNode SDK specification (`SPEC_VERSION` `"1.3"`): boosters and fine-tuned models.

### Added

- Depth Vision and Segment Vision: `Booster::DEPTH` (`bynn_depth`) with `Booster::depth($gridSize)` and `DepthReading` (`ahead`, `directions`, `ground`, the grid and its nearest, farthest and median distances, in meters), and `Booster::SEGMENT` (`bynn_segment`) with `Booster::segment($prompt, ...)` and `SegmentReading` (the phrases read and each match as sent: label, score, box, outline, area, centre, opening, and with depth on its distance and size in meters). A reading with a field missing or of the wrong type is an `UnknownReading`. The README's booster table lists every booster.
- Boosters: `decide`, `check`, `classify`, `score`, `number`, `gate` (and their `Async` forms), `decideMany`, `BatchRequest` and `checkRequest` take `boosters`, up to 4 in order: a name (`Booster::AGE`, `Booster::AI_GENERATED`, or any string), a `Booster` (`name`, `images`, `require`, `options`, sent as `{"name", "images", "require", "options"}`, each left out when not given, or as the bare name when no option is set) or a booster in its wire form. Without boosters the request body is unchanged. Nothing about boosters is checked locally except the API's nesting limit, which counts them. Sessions take no boosters.
- `Decision::$evidence`, by booster name in the request's order: `Evidence` with `version`, `status`, `latencyMs`, `billedCalls` and `results`, each result an `AgeReading`, an `AiGeneratedReading` (`topGenerator`, null unless the picture reads as likely generated, `c2paSigner` and `c2pa`) or, for a booster this version does not know or a reading with a field missing or of the wrong type, an `UnknownReading` with its raw fields. `null` when the request named no boosters.
- `Usage::$boosterTokens` and `Usage::$boosters`, `null` for a request without boosters.
- `DecisionNodeError::$boosters` on every error: the `x-decisionnode-boosters` header by booster name, in the request's order, each `['status' => ..., 'latency_ms' => ...]`, so a caller sees which required booster failed.
- Fine-tuned models: a fine-tuned model's name is sent as given in `model`, and `Decision::$model` carries the version that answered. `ModelInfo` gains `version`, `fineTuned`, `base`, `adapterVersion` and `status`, each null for a catalogue model.

- The API's 25 MiB body limit: `RequestTooLargeError`. A request body, or a batch line, over 26,214,400 bytes is refused before anything is sent or uploaded (measured as it would go out, an image to upload by its upload id), and the API's 413 is the same error, never retried. Inline images and video frames count toward it; uploaded images and URLs do not, and local images at or above the inline threshold are uploaded.

- Fallback: a `fallback` option on `decide`, the helpers (and their `Async` forms), `decideMany`'s requests and `BatchRequest`, sent as `"fallback": true | false` only when given; `fallback: false` makes a request for `decisionnode-moe` wait for its own model (the 529, retried as any) rather than be answered by `decisionnode-flash`. `Decision::$fallback`, a `Fallback` (`servedModel`, `requestedModel`) from the `x-decisionnode-fallback` and `x-decisionnode-requested-model` headers, null on an ordinary answer.

- An error whose body is not JSON (an edge's or a proxy's page, or no body) is classed by its status alone, with `errorType` null, `requestId` and `reason` from their headers, nothing read from the body (`body` is null), and the message `HTTP <status> <text>` from a fixed table (`HTTP 413 Content Too Large`), then ` (request id <id>)` when sent.
- An image id over 64 characters (code points) is refused before anything is sent: "Image <n>: its id is longer than 64 characters.", after the empty-id check and before the duplicate check, on `decide`, the helpers, `decideMany` (for the whole call), batch lines and `checkRequest`; a session frame's image goes without its id and is not checked for it.

- The boosters `face_detection`, `face_recognition` and `ocr`: `Booster::FACE_DETECTION`, `Booster::FACE_RECOGNITION` and `Booster::OCR`, and `Booster::ocr($prompt)` and `Booster::faceRecognition($collection, $threshold)` for their options, sent as given. Their readings: `FaceDetectionReading` (`image`, `faces`, `detections` of `box` and `confidence`), `FaceRecognitionReading` (`image`, `faces`, `matches` of `box`, `score`, `collection`, `reference`, and `name` or `label`, null when not sent) and `OcrReading` (`image`, `text`, `truncated`); a box holds its corners as sent.
- Collections of faces, which `face_recognition` searches: `$dn->collections` with `create($name, $type)`, `list()`, `get()`, `delete()`, `enroll($collectionId, $image, reference:, label:)`, `listItems($collectionId, offset:, limit:)` and `deleteItem()`, returning `Collection`, `CollectionItem`, `CollectionItemPage` and `Deleted` with every field as sent and `raw`. `create` and `enroll` send an `Idempotency-Key`, a new UUID for each call and the same on every attempt, and are retried like any call, a 409 `idempotency_in_progress` too after its wait; a 409 `idempotency_key_reused` is raised as the base error. A type other than `face`, a name not 1 to 80 characters, a reference not 1 to 64 of `A-Z a-z 0-9 _ . : -` and a label empty or over 128 characters are refused before anything is sent, as the API's `ValidationError` with its entries, each input echoed as the API echoes it (a string cut to 64 characters with "..."); the enrolled image is sent as a decision's, its URL checked by a decision's URL rules and any other fault left to the server's 422 `image_unreadable`; `listItems`' offset and limit are checked as `batches->list()` checks them.
- `FaceRecognitionReading::$collectionName`, an own collection's display name, and on each match its `collection_name` and the item's `metadata`, each null when not sent; either of the wrong type makes the reading an `UnknownReading`.
- `ModelInfo`'s prices as sent: `pricePerMillionInput`, `pricePerMillionOutput`, `pricePerMillionImage`, `pricePerMillionVideo` and `batchPriceFactor`, the share of the live prices a batch line pays (null on a fine-tuned model).
- Per-key usage: `$dn->usage` with `key($keyId, $start, $end, $granularity, $groupBy)` (`GET /v1/keys/{key_id}/usage`), `list($start, $end, ...)` (`GET /v1/usage`, filtered by `labels`, `deviceId`, `keyIds`, `scope`, `status` and `includeEmpty`, paged by `limit` and `cursor`) and `all()`, which follows `nextCursor` until it is null, each with its `Async` form, returning `KeyUsage`, `UsagePage` and `UsageItem` with their fields as sent and `raw`. The query's rules are checked locally with the API's messages.
- Keys for devices, with a Manage device keys key: `$dn->keys` with `create()` (with an `Idempotency-Key`, yours or a new UUID, the same on every attempt), `list()`, `all()`, `update()` (only the arguments given; `null` clears `labels`, `limits`, `dailySpendCapUsd` and `expiresAt`), `revoke()` and `revokeByLabels($labels, $deviceIds, $dryRun)`, each with its `Async` form, returning `CreatedKey` (the key and its secret, shown once), `Key`, `KeyPage`, `RevokedKey` and `KeysRevoked`. A name's length, a device id, the labels, the shape of `limits`, the daily spend cap, an update with nothing to change, an `idempotencyKey`, the list's filters and limit, and a bulk revoke without a label or with over 1,000 device ids are checked locally, as the API answers them.
- A publishable key's refusals: `origin_not_allowed` and `model_not_allowed` (with `details['allowed']`) are a `PermissionDeniedError`; its per-address rate and calls in flight a `RateLimitError` with `details['reason']` `client_rate` or `in_flight`, retried as any 429.
- Collection items described and edited: `enroll(..., metadata:)` and `CollectionItem::$metadata`; `updateItem($collectionId, $itemId, reference:, label:, metadata:)` and its `Async` form, a PATCH of only the arguments given (a `null` label sent as `null`, `[]` metadata as `{}`) with its own `Idempotency-Key`; `listItems(..., reference:, referencePrefix:)`, and the page's `totalCapped`, `reference` and `referencePrefix`. Metadata and the lookup are checked locally with the API's entries and messages.
- `DecisionNodeError::$details`, the body's `detail.details` when it is an object. A 529 with the reason `enrollment_unpriced` in `details` is never retried; a 403 `key_scope_denied` is a `PermissionDeniedError`.
- `Usage::$cost`, a `Cost` (`usd`, `modelUsd`, `boostersUsd`, `freeTier`): what an answer cost, exactly as the API sent it, on decisions, batch results and a session's open (a frame reply's `usage` array holds it as sent); null when absent.

- A decision whose boosters name `ocr` (by name or as an object) waits `DecisionNode::OCR_TIMEOUT`, 30 s, per attempt unless `timeout` was given, and is never retried after a timeout, since the server finishes that attempt and may bill it; its other retries stay. This holds for `decide`, the helpers and each `decideMany` request. The client's `timeout` argument is now nullable, null meaning the default.

- Several places in one answer: `point()` and `box()` (and `Question::point()`, `Question::box()`) take `max`, an integer from 2 to 16, sent in the question only when given. Such a question is answered as a `PointsAnswer` (`count`, `present`, `complete`, `points`, each `['x', 'y', 'confidence']`, `image`, `width`, `height`) or a `BoxesAnswer` (the same with `boxes`, each `['x1', 'y1', 'x2', 'y2', 'confidence']`), told by the answer's own list of places, in decisions and batch results alike; the places keep the API's order and numbers. A several-places answer with a field missing or of the wrong type is an `UnknownAnswer`.
- A `max` that is not an integer, under 2 or over 16 is refused before anything is sent, as the API's `ValidationError` (`int_type`, `greater_than_equal`, `less_than_equal`) at `["body", "questions", <id>, <type>, "max"]`. Whether a model version answers several places is the server's to say: its `422` `not_supported` is a `ValidationError`. A session's open leaves its point and box questions to the server.

### Changed

- A Depth Vision reading whose `directions` is not exactly 7 entries, or whose grid sides or picture size are not whole numbers of 0 or more, is an `UnknownReading`, as is a Segment Vision reading whose picture size is not, or a match whose size in meters is not two numbers; `ahead` and a match's depth medians may be left out (null). A Segment Vision match always carries `opening`, `depth` and `size_m`, null when not sent.
- `Batch` has no `priceFactor`: the API does not send it. The batch price is each model's `batchPriceFactor`, and each line's charge is in its usage.
- The default model is `decisionnode-moe` (it was `decisionnode-latest`), the family id that always means the current release; `decisionnode-flash` is the fast one. A pinned id is `decisionnode-<family>-<version>`, read from the model list, and an answer names the pinned id that answered. The earlier ids stay accepted by the API.
- The booster errors map to the existing classes: `booster_unknown` and `booster_input_mismatch` are a `BadRequestError`, `booster_not_enabled` a `PermissionDeniedError`, and `booster_unavailable` an `OverloadedError`, retried like any 529.
- A 429 `key_spend_cap_reached`, a key's daily spend cap, is never retried: it holds until 00:00 UTC.
- The conformance tests run suite 1.22.

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
