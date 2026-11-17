# M1 — Injector / Ingestion

**Status:** not started (reframes archived Sprints 2 & 4 ingestion).  
**Depends on:** M0.  
**Enables:** M2, M3, M4, M5 (context), M6 (briefs/materials).

## Purpose
Single, unified **injector** for all learning inputs. Accept uploads, links, and briefs; validate and store them safely; extract canonical text/metadata so downstream modules work from a consistent source.

## Scope
### In
- Intake of supported source types: documents (PDF, text), images, audio, video, and link/URL resources (incl. event/hackathon briefs as content).
- Server-side type/size/MIME validation; storage outside webroot with checksum/size; immediate registration/listing of resources.
- Link-only resources represented uniformly (no local blob, fetchable reference).
- **Extraction pipeline (compute):** parse documents/text, OCR images/scans, transcribe audio/video (ASR), fetch/read links — producing extracted text and structured metadata.
- Job-driven processing via M0 queue/worker contract; status/progress per resource.
- Association of resources to a user and/or project/workspace (per M0).

### Out
- Chunking/embeddings/retrieval (M2).
- Domain generation: quizzes/lessons/tasks/events/reports (M3–M7).
- Chunked/resumable very-large uploads, previews/thumbnails/playback (unless later required).
- Deletion/retention policy (M8/hardening, unless specified).

## Entities & data (module-owned, via M0 contracts)
- **Resource:** owner, project/workspace (optional), title, source_type, status, timestamps.
- **Source/File:** kind (`raw`/`derived`), path/ref, mime, bytes, sha256; link resources carry URL without local bytes.
- **ExtractedContent/Artifact:** extracted text + metadata (language, duration/pages, confidence/segments as available) linked to resource; provenance to source.

## Interfaces
- **Edge:** create/upload resource, submit link/brief, list/get resources & status (scoped to owner/membership). Enforces effective upload ceiling and validation.
- **Worker/compute:** download/fetch input, run extraction handler per type, post extracted artifacts/results and status; respects preflight/serialization constraints.
- **To M2:** stable access to extracted text/metadata per resource (and project scope).

## Processing flow
1. Validate & persist source (transactional; no orphans on reject/failure).
2. Enqueue extraction job(s) per source type (`parse|ocr|transcribe|fetch` as applicable).
3. Worker claims, processes serially within capacity, heartbeats, posts result or retryable/permanent failure.
4. Resource/extraction status advances to ready/failed; artifacts recorded for downstream use.

## Acceptance criteria
- **AC-M1-1:** Supported source types (pdf, image, audio, video, text, link/brief) can be ingested and appear immediately in listings with correct metadata.
- **AC-M1-2:** Validation is server-side (content-based type, size/ceiling enforced); rejected intake leaves no orphan rows/blobs and returns a specific reason.
- **AC-M1-3:** Stored files are outside webroot, not directly URL-reachable; checksum/size recorded and verifiable.
- **AC-M1-4:** Intake is transactional (resource+file consistent); failures do not produce half-created state.
- **AC-M1-5:** Access scoped to owner/project membership.
- **AC-M1-6:** Extraction produces canonical text/metadata for each applicable type via the job/worker pipeline, with correct status transitions and retry/dead-letter behavior per M0.
- **AC-M1-7:** Effective upload ceiling resolved/enforced and observable (host-verified for deployment).

## Dependencies & constraints
- Requires M0 queue/worker protocol and storage/auth primitives.
- Compute dependencies/binaries (ffmpeg, tesseract, ASR runtime) and ≥preflight resources on worker; serialized execution to fit RAM/disk limits.
- Host upload ceiling must be raised/verified for media (known cPanel default ~2M).

## Open items
- Canonical extracted-text schema/segmentation contract with M2.
- Chunked/resumable upload need based on real media sizes.
- Link-fetch policy (e.g. YouTube/pages) and safety/size bounds.
