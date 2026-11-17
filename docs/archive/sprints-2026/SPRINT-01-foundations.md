# Sprint 1 — Foundations

**Status:** not-started

## Goal

Stand up the two project skeletons, the database schema, and authentication, and
prove the compute machine can actually run the pipeline.

## Depends on / Enables

- **Depends on:** nothing. First sprint.
- **Enables:** every other sprint.

## Scope

### In

- Edge application skeleton (routing, config, error handling, logging).
- Configuration and secrets loading; no secret committed to the repository.
- Database schema for the entities in `ARCH.md` §7, plus a migration runner.
- **Runtime database-engine detection** — do not assume MySQL vs MariaDB.
- Health endpoint.
- Authentication: users, login, sessions, roles, and resource ownership.
- **Environment preflight for the compute machine** (see `FR-9`).
- A dev/seed path to create the first user.

### Out

- Uploading files (Sprint 2).
- Any job queue behaviour (Sprint 3).
- Any Mac-side processing (Sprint 4).
- OCR, ASR, embeddings, generation.
- Blog and PWA delivery (Sprint 6).

## User stories

- As an operator, I can hit a health endpoint and know the app and database are up.
- As a team member, I can sign in and see only my own resources.
- As a user, I can sign out and my session ends.
- As an operator, I can add a user without opening a database console.
- As the compute machine, I can prove I have the disk, RAM, and binaries needed
  before accepting any job.

## Functional requirements

- **FR-1** The edge app loads configuration from the environment, never from
  checked-in files containing secrets.
- **FR-2** A migration runner applies schema changes in order and records what it
  has applied. Running it twice is a no-op.
- **FR-3** The schema contains: `users`, `resources`, `files`, `jobs`,
  `extracted_text`, `knowledge_refs`, `outputs`, and blog delivery storage.
- **FR-4** The app detects the database engine and version at startup and records
  whether `SELECT … FOR UPDATE SKIP LOCKED` is available. Feature use depends on
  that detection, never on a hardcoded assumption.
- **FR-5** A health endpoint reports app status, database connectivity, engine
  version, and whether atomic-claim support is present.
- **FR-6** Passwords are stored as a salted adaptive hash. Plaintext passwords
  are never stored, logged, or emailed.
- **FR-7** A session is created on successful login and invalidated on logout.
  Sessions expire.
- **FR-8** Every resource is owned by exactly one user. Authorization checks
  ownership on every read and write involving a resource.
- **FR-9** A compute-node preflight reports — and enforces — the minimum
  environment: free disk ≥ 15 GB, required media binaries present and executable,
  and enough RAM for the selected model tier. It **exits non-zero with a clear
  message** when a requirement fails, rather than letting a later job crash.
- **FR-10** Role values are constrained to a known set and enforced in the data
  layer, not only in the UI.

## Behavioural contract

### Entities

```
users(id, email UNIQUE, password_hash, role, created_at)
resources(id, user_id → users, title, source_type, status, created_at)
files(id, resource_id → resources, kind[raw|extracted|output], path, mime, bytes, sha256)
jobs(id, resource_id → resources, type, status, payload JSON,
     attempts, lease_expires_at, worker_id, created_at, updated_at)
extracted_text(id, resource_id, text, meta JSON)
knowledge_refs(id, resource_id, chunk_id, vector_ref, text)
outputs(id, resource_id, type[quiz|lesson|material], content JSON, status, published)
```

`jobs.status` ∈ `queued | claimed | running | done | failed | dead`.
`resources.status` ∈ `uploaded | queued | ingesting | modelling | generating | done | failed`.
`files.path` is relative to a configured storage root and **never** inside the webroot.

### Indexes

- `jobs(status, created_at)` — the claim query's access path.
- `resources(user_id, created_at)`.
- `files(resource_id, kind)`.
- `outputs(resource_id, type)`.

### Startup sequence

1. Load config; fail fast if a required key is missing.
2. Connect to the database.
3. Detect engine + version; evaluate atomic-claim support.
4. Log a one-line capability summary.

### Health endpoint

Returns app status, DB connectivity, engine + version, atomic-claim support, and
migration state. Returns a non-2xx status when the database is unreachable.

## Acceptance criteria

- **AC-1** `migrate` on an empty database creates all eight entities plus indexes,
  and a second run changes nothing.
- **AC-2** No secret value appears in any tracked file; the repo's secret scan is clean.
- **AC-3** The health endpoint returns a non-2xx status when the database is down,
  and reports engine, version, and atomic-claim support when it is up.
- **AC-4** Atomic-claim support is **reported from a runtime probe**, not hardcoded.
- **AC-5** Registering, logging in, accessing a resource, and logging out works.
- **AC-6** Logging out invalidates the session — the old session cannot be reused.
- **AC-7** User B requesting User A's resource by direct ID gets a denial, not data.
- **AC-8** Passwords are hashed; no plaintext password exists in the database.
- **AC-9** The compute preflight exits non-zero with an actionable message when disk
  is below 15 GB free, and when a required binary is missing.
- **AC-10** The preflight passes on the actual worker machine, with its reported
  capability output captured in the sprint's Decisions section.

## Open questions

- Confirm the real host's MySQL/MariaDB version and `innodb_lock_wait_timeout`.
- Per-student accounts or a single shared login? Affects the `users` model.
- Does the team want password reset, or is manual provisioning enough for v1?
- Is the compute preflight a script, or does it live inside the worker binary?
- Session lifetime and whether "remember me" is needed.

## Decisions

Recorded during the sprint.

| Question | Decision | Why |
|----------|----------|-----|
| Language / runtime | PHP 8.5, no framework, **zero third-party dependencies** | cPanel has no `composer` (`ARCH.md` §1); a hand-written PSR-4 autoloader keeps the upload to plain PHP. A framework would have to be vendored over FTP. |
| Local database | Docker, **`mariadb:10.11` pinned** | Matches the cPanel default (`cPanel ≥122`) rather than drifting to brew's 13.x. Lets Sprint 3 test the real atomic-claim path. |
| Dev server | `php -S` built-in server | No Apache needed locally; production uses `public/.htaccess`. |
| Migrations are **not atomic** | Statements run sequentially; ledger recorded only on success | MySQL/MariaDB perform an **implicit commit** around DDL, so a `CREATE TABLE` cannot be rolled back. Wrapping DDL in an explicit transaction is actively harmful — the transaction is silently committed and the final `commit()` throws "no active transaction". Found by running it. |
| Capability detection | `SKIP LOCKED` support is decided by **executing** the statement against a temporary InnoDB table inside a rolled-back transaction | Version checks miss vendor backports and forks. Verified `true` on 10.11.19. |
| `JSON` columns | Native `JSON` type in the schema; `Database::probeJsonType()` records which it is | MariaDB aliases `JSON` to `LONGTEXT` + a check constraint. Both work; the health endpoint reports `alias` so it is never a surprise. |
| Status vocabularies | `ENUM` columns rather than free strings | Makes `FR-10` a database-level guarantee, not an application convention. Invalid roles are rejected by the engine. |
| Admin access | Admins bypass the ownership check; students and teachers do not | Sprint 1 open question — per-student vs shared login is unresolved, so the model supports both. |
| Self-service admin signup | Allowed **only** when the `users` table is empty | Prevents privilege escalation after bootstrap. Manual `bin/` provisioning otherwise. |
| Session hardening | `HttpOnly` + `SameSite=Lax`, id regenerated on login, record **destroyed** on logout | Regeneration blocks session fixation; destroying (not clearing) is what makes `AC-6` pass — a cleared session leaves a replayable cookie. |
| Unknown-email timing | A dummy hash comparison runs when the email is unknown | Keeps response time from revealing whether an account exists. |
| Preflight lives in | `worker/preflight.py`, standalone Python 3 (stdlib only) | Must run before the worker exists (Sprint 4), and must work on any machine. |

### Two bugs worth remembering

Both were found by running the code, not by reading it:

1. **DDL kills explicit transactions** (above). The migration runner was written
   transactionally and failed on first run.
2. **`information_schema.STATISTICS` is cached** and lags immediately after DDL.
   The `AC-1` index assertion failed against a table whose index genuinely
   existed. Fixed in the test with `ANALYZE TABLE` (whose result set must itself
   be drained, or the next query fails with "unbuffered queries are active").

### Preflight capability report

Recorded from the machine used during this sprint. **The production worker machine
has not been measured** — re-run `python3 worker/preflight.py` there before
starting Sprint 4.

```
platform : Darwin arm64 / python 3.14.8
disk     : 0.69 GB free  (need 15.0 GB)  FAIL
memory   : 0.29 GB available / 8.0 GB total  FAIL
asr tier : NONE FITTING (budget -1.71 GB)
binary   : ffmpeg      FAIL - not found on PATH
binary   : tesseract   FAIL - not found on PATH
binary   : python3     OK
PREFLIGHT FAILED (exit 1)
```

`AC-9` is satisfied — it exits non-zero naming each specific shortfall.
`AC-10` is **not yet satisfied**: no machine has passed this check.

## Status

`building` — code complete, **30/30 local acceptance checks passing**, but `AC-10`
(blocked) awaits a machine that passes preflight, and the two host facts
(DB version, upload ceiling) remain unverified against real cPanel.

