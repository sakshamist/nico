# Nico — Architecture & Design

This document explains the design behind how Nico works (see `FLOW.md` for the flow itself). It covers the environment constraints, the edge/compute split, each component's responsibilities, the data model, the worker protocol, and the trade-offs made.

## 1. Environment Constraints

The target host is **cPanel shared hosting with FTP-only file transfer** (no SSH). This single constraint drives almost every design decision.

### What works on cPanel
- PHP + MySQL application.
- Multipart HTTP file uploads.
- Static assets and a responsive PWA.
- Outbound HTTPS requests (cURL).
- A blog (WordPress or a static/PHP generator) on the same host.
- Disk storage for source and derived files (within quota/inode limits).

### What does not work
- Installing binaries (`tesseract`, `ffmpeg`, local LLMs) — no shell.
- Long-running jobs: PHP `max_execution_time` (~30–60s) and `memory_limit` are too small for OCR/ASR/generation.
- Persistent worker processes on the host.
- WebSockets (usually blocked) — the UI must poll.
- `composer` / `npm install` on the server; dependencies must be vendored and uploaded via FTP.
- Vector databases, embeddings, and heavy ML.

### Consequence
cPanel is a **thin edge**: upload, store, queue, display, and deliver. Every heavy operation is offloaded to a separate **compute node** (the Mac). The design is therefore split into an **Edge layer** and a **Compute layer** joined by a queue.

## 2. Edge / Compute Split

| Layer | Runs on | Always-on? | Responsibility |
|-------|---------|-----------|----------------|
| Edge | cPanel | Yes | Upload, file storage, job queue, result store, delivery (Web/Blog/PWA) |
| Compute | Mac | Best-effort | OCR, parsing, transcription, knowledge modelling, content generation |

All heavy work happens on the Mac. The edge never blocks on it — it enqueues work and serves whatever results exist so far.

## 3. Components

| # | Component | Location | Responsibility |
|---|-----------|----------|----------------|
| 1 | Resource Upload UI | cPanel | Auth, multipart upload, validation |
| 2 | File Management System | cPanel | Raw + derived files on disk, checksums, quota, listing in UI |
| 3 | Metadata DB | cPanel | MySQL: users, resources, files, jobs, outputs |
| 4 | Job Queue + Worker API | cPanel | Atomic claim/lease/complete; the linchpin interface |
| 5 | Compute Worker | Mac | Runs ingestion, modelling, and generation jobs |
| 6 | Knowledge Store | Mac | Text chunks + embeddings; cPanel keeps only pointers |
| 7 | Result Store | cPanel | Extracted text, quizzes, lessons, materials |
| 8 | Delivery API | cPanel | One backend shared by Web, Blog, PWA |
| 9 | Web + Blog + PWA | cPanel | Consumer-facing UIs |

Note: the **File Management System is not a parallel pipeline** — it is the File Store + Metadata DB that feeds both the UI and the compute worker. It is drawn as a separate flow in `FLOW.md` only to show that files appear in the UI before processing finishes.

## 4. Why a Queue in the Middle

The original pipeline is linear and crosses both machines. Since the host cannot run workers or WebSockets and cannot be called reliably for long jobs, a **durable job queue in MySQL** is placed between the layers:

- The edge enqueues jobs and forgets them.
- The Mac pulls jobs, processes them, and posts results back.
- Progress survives restarts, network drops, and the Mac being offline.

This queue is the only new interface between the two layers.

## 5. Communication Model: Mac Pulls

The Mac is assumed to be behind NAT with no public inbound access. Therefore the Mac **polls** the edge rather than the edge pushing to the Mac.

- No port-forward, public IP, or tunnel required.
- Works even when the Mac sleeps or reboots (jobs simply wait).
- Trade-off: added latency (poll interval) instead of real-time delivery — acceptable for a small team/class.

The Worker API is authenticated with an **HMAC-signed shared secret** over HTTPS. An IP allowlist is used where feasible.

## 6. Worker Protocol

All endpoints are on the cPanel edge; the Mac is the client.

1. `POST /worker/claim` — atomically claim the next queued job, returning a lease/timeout (or `204` if none).
2. `GET  /worker/jobs/{id}/file` — download the source file.
3. `POST /worker/jobs/{id}/heartbeat` — keep the lease alive during long work.
4. `POST /worker/jobs/{id}/result` — post the artifact and mark the job done or failed (retryable).

Job types: `ocr`, `parse`, `transcribe`, `embed`, `generate_quiz`, `generate_lesson`, `generate_material`.

Jobs are **chained and fanned-out**, not a single linear step:
`ocr | parse | transcribe` → `embed` → N × `generate_*`.

The worker runs as a long-lived process on the Mac (launchd/systemd or a simple loop), so it does **not depend on cPanel cron** (whose availability was unconfirmed).

## 7. Data Model (MySQL, minimal)

- `users(id, email, password_hash, role)`
- `resources(id, user_id, title, source_type, status, created_at)`
- `files(id, resource_id, kind[raw|extracted|output], path, mime, bytes, sha256)`
- `jobs(id, resource_id, type, status[queued|claimed|running|done|failed], payload JSON, attempts, lease_expires_at, worker_id, created_at, updated_at)`
- `extracted_text(id, resource_id, text, meta JSON)`
- `knowledge_refs(id, resource_id, chunk_id, vector_ref, text)` — vectors live on the Mac
- `outputs(id, resource_id, type[quiz|lesson|material], content JSON, status, published)`
- `blog_posts(...)` — blog delivery

Atomic claiming uses `SELECT ... FOR UPDATE SKIP LOCKED`, or an equivalent UPDATE-with-lease if the MySQL version is older.

## 8. Design Decisions and Trade-offs

- **Edge/Compute split** — forced by shared-hosting limits; keeps the always-on, cheap side simple and the expensive side on hardware we control.
- **Pull, not push** — chooses NAT-friendliness and resilience over real-time latency.
- **Queue durability over synchronicity** — the UI shows partial progress rather than waiting for a full pipeline run.
- **Polling UI** — chosen because WebSockets are unavailable on the host.
- **Vectors on the Mac** — the edge stores only pointers, avoiding heavy storage and compute on the host.
- **Long-lived Mac worker** — removes dependence on cPanel cron.
- **Open-source, self-hosted stack** — required by the project; the compute node uses open-source OCR (Tesseract/PaddleOCR), parsing (PyMuPDF, python-docx), ASR (faster-whisper), embeddings (sentence-transformers), a vector store (Qdrant/Chroma), and an open-source LLM (e.g. Ollama with Llama/Qwen/Mistral).

## 9. Security and Operations

- Worker API: HMAC-signed requests, HTTPS only, IP allowlist where possible.
- Uploads: validate MIME and size; store outside the webroot; never execute.
- Reliability: job leases plus an `attempts` cap, with a dead-letter state for stuck jobs.
- Since everything is self-hosted and open source, budget for content safety filtering and model licensing.

## 10. Known Risks and Open Questions

- **cPanel cron / Node app support** — not required by this design, but useful as a fallback; confirm availability.
- **Mac uptime** — sleeping or rebooting just delays jobs; confirm the resulting latency is acceptable.
- **Upload size limits** — check `upload_max_filesize` / `post_max_size`; audio and video may need chunked uploads.
- **Blog hosting** — same cPanel (WordPress) or a static generator fed by the Delivery API?
- **Auth model** — single shared login for the team, or per-student accounts?
- **LLM choice on the Mac** — Ollama vs raw transformers; affects the shape of generation jobs.
