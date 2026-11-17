# Sprint 4 — Mac Worker & Ingestion

**Status:** not-started

## Goal

Run a long-lived worker on the Mac that claims jobs from Sprint 3, reads any
supported source, and posts extracted text back — without crashing under the
machine's real memory and disk limits.

## Depends on / Enables

- **Depends on:** Sprint 2 (source files exist), Sprint 3 (worker API),
  Sprint 1 (compute preflight).
- **Enables:** Sprint 5 (needs extracted text), Sprint 6 (shows ingestion status).

## Scope

### In

- A long-lived worker process that polls the edge for work.
- HMAC request signing on the worker side, with clock-skew handling.
- **Hardware probe at startup** that selects a model tier fitting available RAM.
- Download of the source file for a claimed job.
- Ingestion handlers: PDF/document parsing, image OCR, audio/video transcription,
  plain-text read, link fetch.
- Extracting audio from video for transcription.
- Heartbeating during long jobs so the lease is never lost.
- Posting extracted text back, with metadata about how it was produced.
- **Serialised execution** — one heavy job at a time.
- Temp-file hygiene and crash-safe cleanup.
- A local run mode for iterating without the edge.

### Out

- Chunking, embeddings, vector storage (Sprint 5).
- Quiz/lesson/material generation (Sprint 5).
- Streaming partial results to the UI.
- More than one worker process on one machine.
- Distributed or multi-machine workers.

## User stories

- As the system, an uploaded PDF becomes extracted text without anyone watching.
- As the system, a photo of a whiteboard is OCR'd into text.
- As the system, a lecture recording becomes a transcript.
- As the system, a video's audio is transcribed.
- As the system, a submitted link is fetched and read.
- As the operator, the worker starts on a fresh machine and tells me if it is not ready.
- As the operator, the worker survives the edge going down and resumes when it returns.

## Functional requirements

- **FR-1** The worker runs as a long-lived process, polling on an interval, and does
  not depend on cPanel cron for operation.
- **FR-2** At startup the worker probes free disk, total/available RAM, and the
  presence and executability of required media binaries, then **selects the largest
  model tier that fits** its memory budget.
- **FR-3** If no tier fits, or a required binary is missing or not executable, the
  worker **refuses to start** with a clear, actionable message naming the shortfall.
- **FR-4** All worker API calls are HMAC-signed over a canonical request, with
  timestamps that tolerate reasonable clock skew between host and Mac.
- **FR-5** The worker processes **one heavy job at a time**. It must never hold two
  concurrent model loads.
- **FR-6** It heartbeats at an interval comfortably below the lease duration, so a
  long job never loses its lease.
- **FR-7** It downloads the claimed job's source to a temp directory, processes it,
  posts the result, and deletes the temp files — on success **and** on failure.
- **FR-8** Every handler writes extracted text plus metadata recording the method,
  tool, model, and timing used to produce it.
- **FR-9** Handlers distinguish **retryable** failures (network blip, file not yet
  readable, link temporarily down) from **permanent** ones (corrupt file, unsupported
  codec, unsupported language), and report the distinction so the edge retries only
  what is worth retrying.
- **FR-10** A long audio/video file is processed in bounded segments so memory does
  not scale with duration.
- **FR-11** If the edge is unreachable, the worker backs off and keeps retrying; it
  does not exit or lose the job.
- **FR-12** Model loading is lazy and cached — loading a model per job is avoided.
- **FR-13** The worker logs a structured line per job: type, resource, duration,
  outcome, and peak memory.

## Behavioural contract

### Worker loop

```
startup → probe hardware → select tier → verify binaries
   │
   └─► loop
         POST /worker/claim ──204──► sleep(poll interval) ──┐
              │                                              │
              job                                           │
              ├─ GET  /worker/jobs/{id}/file                 │
              ├─ run handler (heartbeat thread active)       │
              ├─ POST /worker/jobs/{id}/result  (done|failed)│
              └─ cleanup temp ───────────────────────────────┘
```

On any connection failure: log, back off, retry. Never exit on a network error.

### Ingestion handlers

| Job type | Input | Method |
|----------|-------|--------|
| `parse` | PDF, DOCX, PPTX, TXT, MD | Direct text extraction; fall back to OCR for scanned pages |
| `ocr` | Image (PNG, JPEG, TIFF) | OCR |
| `transcribe` | Audio, video | ASR; video first has its audio extracted |
| `parse` | Link | Fetch, then extract readable text |

### Memory discipline

- One heavy operation resident at a time (`FR-5`).
- Models are loaded once and reused (`FR-12`), and released when idle.
- Long media is segmented; peak memory must **not** scale with media duration (`FR-10`).
- The worker records peak memory per job so regressions are visible.

### Result payload

Text plus metadata: source type, method used, tool and model versions, page/segment
count, processing duration, and any quality signal worth surfacing later.

## Acceptance criteria

- **AC-1** The worker starts on a machine meeting the preflight and reports the tier
  it selected and why.
- **AC-2** On a machine with insufficient RAM or a missing binary, the worker **exits
  non-zero with a specific message** naming what is missing — it does not start and
  crash later.
- **AC-3** End-to-end: an uploaded PDF is queued, claimed, processed, and its
  extracted text is stored on the edge — verified with a real file, not a mock.
- **AC-4** An image upload produces OCR text.
- **AC-5** An audio upload produces a transcript.
- **AC-6** A video upload produces a transcript from its extracted audio.
- **AC-7** A link resource is fetched and read.
- **AC-8** A plain-text upload is read directly.
- **AC-9** The worker heartbeats throughout a job long enough to require it, and the
  job's lease is never lost mid-processing.
- **AC-10** Temp files are cleaned up after both success and failure — verified by
  inspecting the temp directory after a forced failure.
- **AC-11** Peak memory during a long transcription is **bounded and does not grow
  with file duration** — measured and recorded.
- **AC-12** Two jobs never execute concurrently; verified by observing that model
  loads never overlap.
- **AC-13** A retryable failure returns the job to `queued`; a permanent failure marks
  it `failed` — both demonstrated with real failures of each kind.
- **AC-14** With the edge unreachable, the worker keeps retrying and processes a job
  correctly once the edge returns.
- **AC-15** Every result's metadata identifies the method, tool, and model used.

## Open questions

- **Model tier is chosen at runtime, not pinned here.** Confirm the actual worker
  machine's RAM before locking a tier. The probe makes this a config value, not a
  hardcoded constant.
- ffmpeg is required for video audio extraction and some audio decoding. Confirm the
  codec set we must support.
- For scanned PDFs: per-page OCR fallback — at what point do we decide a PDF needs it?
- Link fetching: how do we handle paywalled, JavaScript-rendered, or auth-gated pages?
  Do we need a headless browser, or is static extraction enough for v1?
- Target languages for OCR/ASR? Accuracy expectations set the model tier.
- Should a transcript keep segment timestamps for later citation?
- Max audio/video duration we will accept, and whether to split oversized media across
  multiple jobs.
- macOS quarantine attribute issues on installed binaries — record the fix if hit.

## Decisions

_Filled in during the sprint._

## Status

`not-started`
