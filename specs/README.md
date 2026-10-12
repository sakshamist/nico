# Nico — Spec-Driven Development

This directory holds the working specs for Nico. `ARCH.md` and `FLOW.md` describe
how the system is shaped; these files describe **what gets built, in what order,
and how we know each piece is done**.

We build in **sprints**. Each sprint is one file, one deliverable, one definition
of done. A sprint is not "done" because code exists — it is done when every
acceptance criterion in its file is verifiably true.

---

## Ground rules

1. **Specs are contracts, not suggestions.** Work outside a sprint's scope goes in
   a later sprint or a new one. Do not silently expand a sprint.
2. **Acceptance criteria are binary.** Every `AC-` is either true or false. If you
   cannot write a test or a command that shows it, rewrite the criterion.
3. **Stack stays abstract until the sprint starts.** Sprints specify *behaviour*.
   Concrete technology is chosen when the sprint begins and recorded in that
   file's **Decisions** section.
4. **Verified constraints are recorded, not re-derived.** Facts established during
   planning appear here so nobody re-litigates them.
5. **A blocked sprint stops.** If an `AC-` cannot be met, fix the sprint or cut
   scope deliberately — never mark it done and move on.

---

## Glossary

Shared vocabulary. Use these words exactly; do not invent synonyms.

| Term | Meaning |
|------|---------|
| **Resource** | One uploaded learning item. The parent of files, jobs, and outputs. |
| **Source** | The original uploaded artifact (PDF, image, audio, video, text, or a link). |
| **File** | A stored blob on the edge, either `raw` (as uploaded) or `derived` (produced by processing). |
| **Job** | A single unit of queued work with a type, a status, and a lease. |
| **Lease** | A time-bounded claim on a job. Expiry lets another worker pick it up. |
| **Artifact** | The payload a job produces and posts back (extracted text, a quiz, a lesson). |
| **Edge** | The always-on cPanel host: upload, storage, queue, results, delivery. |
| **Compute node** | The Mac that runs heavy work: OCR, parsing, transcription, generation. |
| **Ingestion** | Turning a source into extracted text (parse / OCR / transcribe / fetch). |
| **Chunk** | A retrievable slice of extracted text used as generation context. |
| **Embedding** | A fixed-length numeric vector representing a chunk's meaning. |
| **Output** | Generated content (quiz, lesson, material) attached to a resource. |
| **Attempt** | One execution of a job. Counted, capped, and used for dead-lettering. |

---

## Sprint index

| # | Sprint | Deliverable | Depends on | Status |
|---|--------|-------------|------------|--------|
| 1 | [Foundations](SPRINT-01-foundations.md) | Environment, schema, auth | — | not-started |
| 2 | [Upload & File Management](SPRINT-02-upload-file-management.md) | Files stored and listed | 1 | not-started |
| 3 | [Job Queue & Worker Protocol](SPRINT-03-job-queue-worker-protocol.md) | Queue + edge API | 1 | not-started |
| 4 | [Mac Worker & Ingestion](SPRINT-04-mac-worker-ingestion.md) | Worker + extracted text | 2, 3 | not-started |
| 5 | [Knowledge & Generation](SPRINT-05-knowledge-generation.md) | Chunks, quizzes, lessons | 4 | not-started |
| 6 | [Delivery & Hardening](SPRINT-06-delivery-hardening.md) | UI, PWA/blog, ops | 3, 4, 5 | not-started |

Sprints 1–3 are sequential. Sprint 4 needs 2 and 3. Sprints 5 and 6 build on 4.

---

## Verified constraints

Established during planning. **Do not re-open without new evidence.**

| Constraint | Detail | Affects |
|-----------|--------|---------|
| Atomic job claim | MariaDB ≥10.6 supports `SELECT … FOR UPDATE SKIP LOCKED`. cPanel defaults to MariaDB 10.11 on new installs (cPanel ≥122). | S3 |
| DB version unknown | The actual host version is **unverified** — it must be detected at runtime, not assumed. | S1, S3 |
| Upload ceiling | cPanel defaults `upload_max_filesize` to **2M**. Advertised video/audio upload requires raising it (MultiPHP INI Editor or `.htaccess` `php_value`), max 2GB. | S2 |
| Compute disk | Planning machine had **3.7 GB free** against a **~4.5 GB** install need. A hard gate on ≥15 GB free. | S1, S4 |
| Compute RAM | Whisper and the LLM are both resident; they contend. The worker **must serialize** work. | S4, S5 |
| Missing binaries | `ffmpeg` and `tesseract` were absent. Required for media + OCR. | S1, S4 |
| macOS quarantine | Gatekeeper can break installed binaries until the quarantine attribute is removed. | S1, S4 |
| Embeddings | `nomic-embed-text` via Ollama verified working at **768 dimensions**. Avoids a torch dependency. | S5 |
| Python wheels | ctranslate2 ships `cp314` arm64 wheels — no version pinning fight expected. | S4 |

---

## Sprint template

Every sprint file uses this structure:

1. **Goal** — one sentence.
2. **Depends on / Enables**
3. **Scope** — In / Out (non-goals are mandatory).
4. **User stories**
5. **Functional requirements** — numbered `FR-`.
6. **Behavioural contract** — data shapes, endpoint semantics, state transitions.
7. **Acceptance criteria** — numbered `AC-`, binary and testable.
8. **Open questions**
9. **Decisions** — filled in as the sprint runs.
10. **Status** — `not-started` → `spec-ready` → `building` → `verified`.
