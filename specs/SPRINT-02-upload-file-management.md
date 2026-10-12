# Sprint 2 — Upload & File Management

**Status:** not-started

## Goal

Accept a source of any supported type, store it safely outside the webroot, and
show it in the user's list immediately — before any processing has run.

## Depends on / Enables

- **Depends on:** Sprint 1 (auth, ownership, schema).
- **Enables:** Sprint 4 (worker downloads the file), Sprint 6 (listing UI).

## Scope

### In

- Multipart upload for PDF, image, audio, video, plain text, and link-only resources.
- Upload limit configuration and **verification against the real host**.
- Server-side MIME and size validation.
- Storage on disk outside the webroot, with a checksum and byte size recorded.
- Resource creation and `files` row creation in one transaction.
- Listing a user's resources and their files, with per-file status.
- Link-only resources: store the URL, mark that there is no local file.
- Graceful rejection with a clear message when a limit is exceeded.

### Out

- Enqueuing jobs (Sprint 3 attaches the queue trigger).
- Downloading or processing the file (Sprint 4).
- Thumbnails, previews, and inline playback.
- Chunked/resumable upload for very large media (explicit non-goal, see open questions).
- Deletion and retention policy (Sprint 6).

## User stories

- As a student, I upload a PDF and see it in my list right away.
- As a student, I upload a photo of a whiteboard and see it listed.
- As a student, I submit a YouTube link and see it listed as a link resource.
- As a student, I upload a file that is too large and get a clear message, not a blank error.
- As a student, I upload something that is not actually a PDF and get told why.
- As a student, I see which of my files are processed and which are still waiting.

## Functional requirements

- **FR-1** The app supports all six source types. Type is detected server-side and
  recorded on the resource; the client's declared type is never trusted.
- **FR-2** Uploaded files are written to a storage root **outside the webroot** and
  referenced by a relative path.
- **FR-3** Each stored file records MIME type, byte size, and a SHA-256 checksum.
- **FR-4** Validation runs before the resource is created. A rejected upload leaves
  no partial resource, file, or orphaned blob on disk.
- **FR-5** Resource and file rows are created in a single transaction — a failure in
  either leaves neither.
- **FR-6** If the same file content is uploaded twice by the same user, both resources
  exist and both point at correct file rows. (Dedup is a storage optimisation, not a
  correctness requirement — see open questions.)
- **FR-7** A link-only resource stores the URL and creates a `files` row of kind
  `raw` with no local bytes, so downstream jobs have one uniform way to fetch input.
- **FR-8** The configured upload ceiling is enforced, and **the effective ceiling on
  the real host is verified** — not assumed from local configuration.
- **FR-9** The listing endpoint returns only the requesting user's resources, ordered
  newest first, with per-file and per-resource status.
- **FR-10** Rejected uploads return a specific, actionable error: which limit was hit
  or which type was unsupported.

## Behavioural contract

### Upload flow

1. Authenticate and resolve the owning user.
2. Validate declared size against the configured ceiling **before** parsing.
3. Determine source type from content, not from the client.
4. Stream to a temporary path outside the webroot.
5. Validate MIME and size on the received bytes; delete the temp file on failure.
6. Compute SHA-256 and byte size.
7. In one transaction: create `resources` (status `uploaded`) and the `files` row
   (kind `raw`).
8. Move the temp file to its final path.
9. Return the resource. Sprint 3 will enqueue from here.

### Source types

| Type | Has local file | Notes |
|------|----------------|-------|
| `pdf` | yes | |
| `image` | yes | OCR path, Sprint 4 |
| `audio` | yes | ASR path, Sprint 4 |
| `video` | yes | ASR path, Sprint 4 |
| `text` | yes | direct read |
| `link` | no | URL stored; fetched by Sprint 4 |

### Effective upload ceiling

Resolved in this order, first match wins:

1. Host-configured value (MultiPHP INI Editor / `.htaccess` `php_value`), if higher.
2. Application-configured ceiling.
3. Built-in fallback.

The **effective** value is what is enforced, logged at startup, and exposed on the
health endpoint so a mismatch is visible rather than silent.

Required relationship: `memory_limit ≥ post_max_size ≥ upload_max_filesize`.

## Acceptance criteria

- **AC-1** Each of the six source types uploads successfully and appears in the list.
- **AC-2** A file appears in the UI **immediately on upload**, with no processing done.
- **AC-3** No stored file is reachable by direct URL through the web server.
- **AC-4** Uploading an executable or a file whose content contradicts its extension
  is rejected, and the message names the actual problem.
- **AC-5** An upload exceeding the effective ceiling is rejected with a message naming
  the limit — verified on the **real host**, with the effective value captured in Decisions.
- **AC-6** A rejected upload leaves no orphan row and no orphan file on disk.
- **AC-7** The recorded checksum matches an independent checksum of the stored bytes.
- **AC-8** Simulating a failure between file write and database insert leaves no
  half-created resource.
- **AC-9** User B cannot list or fetch User A's resources.
- **AC-10** A link-only resource creates a `files` row so Sprint 4 has a uniform fetch path.
- **AC-11** The effective upload ceiling is logged at startup and reported by the
  health endpoint.

## Open questions

- **Chunked/resumable upload?** Some shared hosts cap uploads below 2 GB. If video
  beyond that ceiling is a real requirement, this becomes its own sprint.
- What is the actual per-file ceiling on the target host, and the account disk quota?
- Should the same file uploaded twice by one user be deduplicated to save quota?
- Store originals forever, or apply a retention window? (Feeds Sprint 6.)
- How are inode limits tracked against a quota?
- Do we need a maximum resource count per user?

## Decisions

_Filled in during the sprint._

## Status

`not-started`
