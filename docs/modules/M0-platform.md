# M0 — Platform (foundation & shared contracts)

**Status:** baseline + Phase 1 extensions implemented (ex-Sprint 1: 30/30; M0: 47/47 local acceptance checks).
**Depends on:** —  
**Enables:** M1–M8

## Purpose
Provide the shared, reliable foundation every module builds on: identity/auth, persistence, storage, configuration, the async job/worker boundary (edge↔compute), and the core domain contracts (workspace/project, people/capabilities, resources, tasks/assignments, artifacts).

## Scope
### In
- Edge app skeleton, routing, config/env, health/reporting, structured logging.
- Database schema & idempotent migrations (MySQL/MariaDB), runtime capability probes (e.g. `SKIP LOCKED`).
- AuthN/AuthZ primitives: users, sessions, password hashing, ownership/permission checks.
- File/blob storage primitives (outside webroot), checksums/metadata hooks.
- **Async runtime:** durable job queue, leases/claims/heartbeat/retry/dead-letter, HMAC-authenticated worker protocol (edge side contract).
- **Shared domain contracts (extensible):** `Project/Workspace`, `User & CapabilityProfile`, membership/roles, `Resource/Source` refs, `Task & Assignment`, `Artifact` (typed, versioned, with provenance refs), status vocabularies.
- Compute preflight concept/contract for worker machines.

### Out
- Domain logic of M1–M7 (ingestion, generation, project workflows, reports) beyond shared entities/APIs.
- UI/delivery surfaces (M8), except minimal health/admin plumbing.
- External integrations (repos, calendars, conferencing platforms) — future, not MVP.

## Core entities & contracts (shared)
- **User:** identity, auth credentials (hashed), profile.
- **CapabilityProfile:** user capabilities/tags/roles and availability signals used by M5 auto-assignment (extensible; MVP fields minimal).
- **Project / Workspace:** container for ongoing work (`owner`, members, type/context such as live/hackathon/event-hosting, status). Hosts resources, tasks, hackathons/events, artifacts, reports.
- **Membership:** user↔project role/permissions.
- **Resource/Source:** reference to an ingested item (file/link/brief) and its extracted content metadata (defined concretely in M1, keyed here).
- **Task:** unit of work in a project/workspace (title, description, requirements/capability tags, due/context, status, parent/milestone).
- **Assignment:** task↔assignee(s)/team, assignment source (manual/auto), timestamps/status.
- **Artifact:** common output envelope — `{id, project_id?, resource_id?, module, type, schema_version, content_ref|content, provenance/source_refs, status, created_by, timestamps}`. Emitted by M3–M6/M7; rendered by M8; composed by M7.
- **Job:** queued work unit `{type, status, payload, attempts, lease, worker}` with constrained types/statuses (baseline from ex-Sprint 3 contract).

## Interfaces (indicative)
- **Edge HTTP:** health/config capability reporting; auth endpoints; CRUD/read APIs for core entities as needed by modules/M8 (ownership/permission enforced).
- **Worker API (edge, HMAC/HTTPS):** claim, fetch input, heartbeat, submit result/failure — stable contract consumed by compute worker (M1+).
- **Storage API:** write/read/delete blobs outside webroot by opaque path/ref; checksum/size recorded.
- **Module contract:** modules persist domain data via M0 schema/services and emit/consume `Artifact` records rather than ad-hoc blobs.

## Acceptance criteria (baseline + extensions)
- **AC-M0-1 (done):** Schema created idempotently; migrations re-runnable; core entities/status vocabularies constrained.
- **AC-M0-2 (done):** No secrets in tracked files; config via env.
- **AC-M0-3 (done):** Health reports DB/engine/version, migration state, capability probes (incl. atomic-claim support) and effective limits.
- **AC-M0-4 (done):** Register/login/logout with session rotation & full invalidation; passwords hashed (no plaintext); ownership enforced; auth failures indistinguishable where applicable.
- **AC-M0-5 (done):** Compute preflight script validates disk/RAM/binaries and exits non-zero naming shortfalls.
- **AC-M0-6 (done):** `Project/Workspace`, membership/permissions, `CapabilityProfile`, `Task/Assignment`, and versioned `Artifact` entities exist; project data is reachable only through membership.
- **AC-M0-7 (done):** Durable job queue & worker protocol operate per contract (atomic claim/no double-claim, lease+heartbeat, retries/backoff, attempt cap/dead-letter, result artifact linkage) — *reframes ex-Sprint 3*.
- **AC-M0-8 (done):** Artifact contract supports provenance/source refs and project/resource scoping; session API authorization and worker HMAC both verified.

## Implementation (Phase 1)
- **Schema:** additive `002_platform_contracts.php` (re-runnable) adds `projects`, `project_members`, `user_profiles`, `user_capabilities`, `tasks`, `task_assignment_log`, `artifacts`, and extends `jobs` (`project_id`, `result_artifact_id`, `lease_token`, `last_error`, `available_at`, `finished_at`, nullable `resource_id`, added job types + claim index).
- **Domain:** `ProjectRepository`, `MembershipRepository`, `ProfileRepository`, `TaskRepository`, `AssignmentService` (best-fit + manual override + release), `ArtifactRepository` (immutable versions, `is_current`).
- **Queue:** `JobQueue` (enqueue/claim with `SKIP LOCKED` fast path + CAS fallback, heartbeat, complete with retry/backoff/dead-letter, reaping); `bin/reap.php` for cron.
- **HTTP:** session API under `/projects`, `/profiles`, `/tasks`, `/artifacts`; HMAC worker API under `/worker/claim`, `/worker/jobs/{id}/heartbeat|file|result` (`WorkerAuth` canonical string `METHOD\npathAndQuery\ntimestamp\nsha256(body)`).
- **Config:** `WORKER_SECRET`, `JOB_LEASE_SECONDS` (300), `JOB_MAX_ATTEMPTS` (3), `JOB_RETRY_BACKOFF_SECONDS` (30), `WORKER_CLOCK_SKEW` (300), `REQUIRE_HTTPS` (0 locally).
- **Verification:** `./dev.sh test` (Sprint 1 regression) and `./dev.sh test:m0` (M0 contracts + queue + auth).

## Verified constraints (carry forward)
- cPanel edge: no long jobs/binaries/websockets, FTP deploy, low PHP limits; `upload_max_filesize` often 2M (`memory_limit ≥ post_max_size ≥ upload_max_filesize`). Host DB version/upload ceiling unverified until probed on host.
- MariaDB/MySQL: `SELECT … FOR UPDATE SKIP LOCKED` when available (≥10.6) — **probe at runtime**, provide fallback claim path.
- Compute behind NAT (pull model), HMAC-signed over HTTPS; worker serializes heavy work; preflight gate (≥15 GB free, ffmpeg/tesseract, sufficient RAM). Vectors kept compute-side with refs on edge.

## Decisions
- Zero third-party PHP runtime deps for edge (cPanel/FTP friendly), per Sprint 1.
- Shared `Artifact` envelope for cross-module composition/rendering; modules own domain specifics behind it.
- Capability model stored in M0 but interpreted by M5 (keeps assignment logic evolvable).

## Open items
- Confirm host DB version and raise/verify upload ceiling on deploy (blocks M1 media).
- Compute preflight must pass on the real worker machine (disk/RAM/binaries).
- M6 team/membership modeling remains in the M6 charter; Phase 1 tasks are single-assignee.
