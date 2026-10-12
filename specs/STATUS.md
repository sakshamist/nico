# Nico — Build Status

**Last updated:** 2026-10-12
**Overall:** Sprint 1 built and locally verified. Sprints 2–6 not started.

Run `./dev.sh test` to re-verify. Current result: **30 / 30 passing**.

---

## Sprint status

| # | Sprint | Spec | Code | Verified | Blocking |
|---|--------|------|------|----------|----------|
| 1 | Foundations | done | done | 30/30 local | 2 items — see below |
| 2 | Upload & File Management | done | — | — | needs host upload ceiling |
| 3 | Job Queue & Worker Protocol | done | — | — | depends on 1 |
| 4 | Mac Worker & Ingestion | done | — | — | needs compute machine |
| 5 | Knowledge & Generation | done | — | — | depends on 4 |
| 6 | Delivery & Hardening | done | — | — | depends on 3, 4, 5 |

"Verified" means the acceptance criteria were executed and passed — not that the
code exists.

---

## Done

### Planning and validation

- `ARCH.md`, `FLOW.md` — architecture and end-to-end flow.
- Stack validated **before** committing to a plan. Four constraints were found and
  designed around rather than discovered mid-build:
  - Disk: 3.7 GB free vs ~4.5 GB install need → gate on ≥15 GB.
  - RAM: 8 GB with heavy swapping → worker must serialise work.
  - `ffmpeg` / `tesseract` absent → preflight gate.
  - `upload_max_filesize` = 2 MB → video impossible without raising it.
- `SKIP LOCKED` confirmed available (MariaDB 10.11), so the queue's fast claim path
  is safe. `ARCH.md`'s hedge about older MySQL turned out unnecessary.
- Ollama `nomic-embed-text` verified working at 768 dims — removes a ~2.3 GB
  torch dependency from the disk budget.

### Spec-driven plan

- `specs/` — 6 sprint specs plus shared conventions, glossary, and a table of
  constraints already verified (so they don't get re-litigated).
- 78 acceptance criteria across 6 sprints, each binary and testable.

### Sprint 1 — Foundations

| Acceptance criterion | Result |
|---|---|
| AC-1 migration runner creates schema, idempotent | pass |
| AC-2 no secrets in tracked files | pass |
| AC-3 health reports status, 503 when DB down | pass |
| AC-4 atomic-claim support probed at runtime | pass |
| AC-5 register / login / access / logout | pass |
| AC-6 pre-logout session cannot be replayed | pass |
| AC-7 resource ownership enforced | pass |
| AC-8 passwords hashed, no plaintext | pass |
| AC-9 preflight exits non-zero naming shortfalls | pass |
| AC-10 preflight passes on a real worker machine | **blocked** |

Delivered:

- Edge app in PHP 8.5 with **zero third-party dependencies** — cPanel offers no
  `composer`, so there is no `vendor/` to upload over FTP.
- Schema for all 8 entities, `ENUM`-constrained status vocabularies, and the
  job-queue claim index.
- Migration runner that is idempotent and re-runnable.
- Health endpoint reporting engine, version, capability probe, migration state,
  and the effective upload ceiling.
- Auth: bcrypt with opportunistic rehash, session id regenerated on login, full
  session destruction on logout, indistinguishable responses for unknown email
  vs wrong password, and ownership enforced in the repository layer.
- `worker/preflight.py` — stdlib-only, runnable before any dependency exists.

### Two bugs the build itself caught

Both found by running the code, not by reading it:

1. **DDL causes an implicit commit** on MySQL/MariaDB. The migration runner was
   written transactionally and failed on first run — a `CREATE TABLE` cannot be
   rolled back, so the transaction is silently committed and the final
   `commit()` throws "no active transaction". Fixed: statements run
   sequentially, ledger recorded only on success.
2. **`information_schema.STATISTICS` is cached** and lags immediately after DDL.
   An index assertion failed against an index that genuinely existed. The test
   was wrong, not the schema; fixed with `ANALYZE TABLE` (whose result set must
   itself be drained).

### Tooling

`./dev.sh` — `db:start`, `db:stop`, `db:logs`, `migrate`, `serve`, `stop`,
`test`, `preflight`. MariaDB 10.11 pinned in Docker to match the cPanel default
rather than drifting to brew's 13.x.

---

## Blockers

### 1. Compute disk at 100% — blocks Sprint 4

```
/System/Volumes/Data   228Gi total   717Mi available   100% full
```

Sprint 4 needs ~4.5 GB for the processing stack; the preflight gate requires
15 GB free. Currently **0.7 GB**.

Largest reclaimable, all user data — deliberately left untouched:

| Path | Size |
|------|------|
| `~/Library` | 50 GB |
| `~/Downloads` | 9.1 GB |
| `~/.ollama` | 4.6 GB |

Only Docker build cache (567 MB) was pruned, since it is unambiguously
regenerable.

**Needed:** roughly 15 GB free before Sprint 4 can start.

### 2. No machine has passed preflight — blocks AC-10 and Sprint 4

The machine used for development reports 8 GB RAM (confirmed by `sysctl`,
`system_profiler`, and `sysconf`), with ~0.3 GB available. It cannot be the
worker.

```
disk     : 0.69 GB free  (need 15.0 GB)  FAIL
memory   : 0.33 GB available / 8.0 GB total  FAIL
asr tier : NONE FITTING
binary   : ffmpeg      FAIL - not found on PATH
binary   : tesseract   FAIL - not found on PATH
```

**Needed:** run `./dev.sh preflight` on the intended worker Mac and record the
output in `SPRINT-01-foundations.md`. The ASR tier is selected from measured
memory, so the spec stays valid on whatever machine it runs on.

### 3. cPanel host facts unverified — blocks Sprint 2's AC-5

Two facts that cannot be checked from a development machine:

- **Actual database engine and version** — cPanel defaults to MariaDB 10.11 on
  new installs, but the specific host is unconfirmed. The app probes this at
  runtime and reports it on `/health`, so this resolves itself on first deploy.
- **Effective upload ceiling** — the default is 2 MB, which makes video upload
  impossible. Must be raised (MultiPHP INI Editor or `.htaccess`) to at least
  500 MB, then verified with a real large upload.

---

## Remaining work

### Sprint 2 — Upload & File Management
Needs Sprint 1 (done).

Multipart upload for all six source types; server-side MIME and size validation;
storage outside the webroot with SHA-256 recorded; immediate listing before any
processing; link-only resources given a uniform fetch path; effective upload
ceiling resolved and enforced.

**Blocked on:** the host upload ceiling above.

### Sprint 3 — Job Queue & Worker Protocol
Needs Sprint 1 (done). **Can start now.**

Enqueue on upload; atomic claim with leases; heartbeat; result submission;
attempt cap and dead-letter; HMAC-authenticated worker API.

The `SKIP LOCKED` path is confirmed available, so this is the least risky sprint
remaining. The concurrency test (`AC-3`: N workers claiming in parallel, no job
handed out twice) is the criterion that matters most.

### Sprint 4 — Mac Worker & Ingestion
Needs Sprints 2 and 3, plus the compute machine.

Long-lived worker polling the edge; hardware probe selecting a model tier from
measured memory; download, process, post, clean up; handlers for parsing, OCR,
transcription, and link fetch; serialised execution; bounded memory on long media.

**Blocked on:** both compute-machine items above.

### Sprint 5 — Knowledge & Generation
Needs Sprint 4.

Chunking with overlap; embeddings via Ollama `nomic-embed-text` (768 dims);
vectors on the Mac with `knowledge_refs` pointers on the edge; job fan-out;
schema-validated quiz, lesson, and material outputs.

The content schema is not yet decided — open question in the spec. Worth
settling before starting, since it determines validation and retrieval.

### Sprint 6 — Delivery & Hardening
Needs Sprints 3, 4, and 5.

Status pipeline with polling UI; output display with source references; PWA;
blog delivery; retention and deletion cascading to Mac-side vectors; quota
enforcement; dead-letter surface; content safety filtering; security pass.

---

## Recommended next actions

1. **Free ~15 GB of disk.** Blocks Sprint 4, and Sprint 4 is the long pole.
2. **Run preflight on the worker Mac.** Closes `AC-10` and confirms the ASR tier.
3. **Start Sprint 3** — it is unblocked, high-risk-reducing, and independent of
   the compute machine.
4. **Confirm cPanel access** to verify the database version and raise the upload
   ceiling before Sprint 2.

Sprint 3 first is deliberate: it is the linchpin interface between the edge and
the compute node, it has no external dependencies, and building it early means
Sprint 4 has a real API to call rather than a contract to guess against.
