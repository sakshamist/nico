# Sprint 6 — Delivery & Hardening

**Status:** not-started

## Goal

Show the user what is happening and what was made, deliver it across web, blog and
PWA, and harden the system against the failure modes the queue makes possible.

## Depends on / Enables

- **Depends on:** Sprint 3 (status), Sprint 4 (ingestion), Sprint 5 (outputs).
- **Enables:** a usable product.

## Scope

### In

- Resource status pipeline and a user-facing progress view.
- Polling-based status refresh.
- Output display: quiz, lesson, study material.
- Web frontend for browsing and reading.
- PWA installability and offline shell.
- Blog delivery of published outputs.
- Retention and deletion of raw and derived files.
- Quota accounting and enforcement.
- Operational logging and basic monitoring.
- Dead-letter surfacing and manual retry.
- Content safety filtering for generated material.
- End-to-end security pass.

### Out

- Native mobile apps.
- Real-time push (WebSockets are unavailable on the host).
- Multi-tenant or per-cohort separation beyond ownership.
- Search across a user's whole library.
- Horizontal scaling of the edge.
- Analytics or engagement tracking.

## User stories

- As a student, I see "processing" and then my quiz appears without reloading manually.
- As a student, I can take the quiz and see correct answers.
- As a student, I can read the lesson on my phone.
- As a student, I can install the app to my home screen.
- As a student, I can read a published lesson on the blog.
- As a student, I delete a resource and its files go away.
- As an operator, I see which jobs are dead-lettered and can retry them.
- As an operator, I get alerted when the worker has been offline too long.

## Functional requirements

- **FR-1** Resource status reflects the job graph: `uploaded → queued → ingesting →
  modelling → generating → done`, plus `failed` for a terminal failure.
- **FR-2** Status is derived from job state, not stored redundantly — the two cannot
  drift apart.
- **FR-3** The UI polls for status. No WebSocket dependency.
- **FR-4** Generated outputs are displayed with their source references, so a user can
  see the material a question came from.
- **FR-5** The web app is installable as a PWA with an offline-capable shell.
- **FR-6** Published outputs are delivered to the blog through one shared delivery
  path — not a second integration.
- **FR-7** Deleting a resource removes its files from disk and its rows from the
  database. No orphans.
- **FR-8** Per-user storage quota is tracked and enforced at upload time.
- **FR-9** Dead-lettered jobs are visible in an operator surface and can be retried.
- **FR-10** Generated material passes a content-safety check before it is published.
  Failing content is withheld and flagged, not shown.
- **FR-11** Structured logs are emitted for job lifecycle, worker connectivity, upload
  events, and errors, with no secrets or user content in log lines.
- **FR-12** A basic health/metrics view reports queue depth, dead-letter count, worker
  last-seen time, and error rate.
- **FR-13** Polling backs off when nothing is changing, and stops when a resource
  reaches a terminal state.

## Behavioural contract

### Status derivation

Status is a **projection over jobs**, computed on read:

| Jobs for the resource | Resource status |
|------------------------|-----------------|
| Any ingestion/embed/generate queued or running | in-flight status by stage |
| All terminal, no failures | `done` |
| Any `failed` or `dead` | `failed` |
| None yet | `queued` |

### Delivery

One delivery API serves web, blog, and PWA. The blog is a consumer of that API —
not a parallel pipeline — so adding a channel never means re-implementing retrieval.

### Polling cadence

| Resource state | Interval |
|----------------|----------|
| In flight | Frequent (seconds). |
| Idle / terminal | Back off, then stop. |

### Retention

Deleting a resource cascades: `files`, `jobs`, `extracted_text`, `knowledge_refs`,
`outputs`, and the on-disk blobs. Vectors on the Mac are pruned for that resource too —
otherwise the Mac grows without bound as resources are deleted.

## Acceptance criteria

- **AC-1** A resource's status advances through the pipeline and the UI reflects each
  stage without a manual reload.
- **AC-2** Status is derived from jobs — deleting a job's record changes the derived
  status, proving there is no redundant stored copy.
- **AC-3** A failed or dead-lettered resource shows a clear failure state, not an
  infinite spinner.
- **AC-4** A real quiz is displayed, is answerable, and shows correct answers with
  explanations.
- **AC-5** Source references on generated content resolve to the user's actual material.
- **AC-6** The PWA installs and its shell loads offline.
- **AC-7** A published output appears on the blog through the shared delivery path.
- **AC-8** Deleting a resource removes all its rows and on-disk files — verified by
  inspecting both, including pruning the Mac-side vectors.
- **AC-9** Uploading beyond a user's quota is rejected with a clear message.
- **AC-10** A dead-lettered job is visible in the operator surface and can be
  retried successfully.
- **AC-11** Generated content that trips the safety check is withheld and flagged,
  and never displayed.
- **AC-12** Logs contain job lifecycle events and **no secrets and no user content**.
- **AC-13** The monitoring view reports queue depth, dead-letter count, and worker
  last-seen correctly.
- **AC-14** Polling stops for a terminal resource rather than polling forever.
- **AC-15** **Security pass complete:** worker HMAC verification, upload validation,
  files unreachable via the web, authorization on every endpoint, secrets absent from
  the repository, HTTPS enforced. Findings recorded, each resolved or explicitly
  accepted.

## Open questions

- **Blog hosting:** WordPress on the same cPanel, or a static generator fed by the
  delivery API? Static generation is more robust; WordPress is easier to edit. This
  was flagged in `ARCH.md` §10 and is still open.
- Retention window: keep originals indefinitely, or expire after N days?
- Does a user need to be able to delete a resource, or is that admin-only?
- PWA: offline read-only, or offline write too?
- What content-safety approach, and does it run on the edge or the Mac?
- Is a manual retry surface enough, or do we need alerts (email/webhook) on dead-letter?
- Do we need to show the worker as "offline" to end users?
- How long should the polling interval be on a slow connection?
- Blog publishing: automatic on `done`, or a human publishes?
- Do we need per-cohort/teacher review before a quiz is visible to students?

## Decisions

_Filled in during the sprint._

## Status

`not-started`
