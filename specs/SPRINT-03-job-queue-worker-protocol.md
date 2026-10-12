# Sprint 3 — Job Queue & Worker Protocol

**Status:** not-started

## Goal

Build the durable job queue and the edge-side worker API — the linchpin interface
that lets a machine behind NAT pull work, process it, and post results back.

## Depends on / Enables

- **Depends on:** Sprint 1 (schema, atomic-claim detection).
- **Enables:** Sprint 4 (the worker consumes this API), Sprint 6 (status display).

## Scope

### In

- Enqueueing jobs, triggered by a completed upload.
- The job state machine and legal transitions.
- Atomic claim with a lease; lease expiry and reclamation.
- Heartbeat to extend a lease during long work.
- Result submission: success, retryable failure, permanent failure.
- The attempt cap and the dead-letter transition.
- The HMAC-authenticated worker API endpoints.
- Worker identity and multi-worker safety.
- Backoff between retry attempts.

### Out

- The Mac worker itself (Sprint 4).
- Any actual processing logic.
- Job prioritisation or scheduling policy beyond FIFO.
- Cancellation and manual retry UI (Sprint 6).

## User stories

- As the system, an uploaded file produces work that is queued automatically.
- As the compute node, I claim one job at a time and get a lease to do it.
- As the compute node, I keep my lease alive while working on a long job.
- As the system, if I crash mid-job, the job becomes claimable again after the lease expires.
- As the system, a job that fails repeatedly is not retried forever.
- As an operator, I know which jobs are stuck.

## Functional requirements

- **FR-1** A completed upload enqueues the initial ingestion job for its resource,
  in the same transaction as the file record, so a stored file always has work queued.
- **FR-2** Job types are constrained to the known set:
  `ocr | parse | transcribe | embed | generate_quiz | generate_lesson | generate_material`.
- **FR-3** Claiming is **atomic** — two concurrent claims never return the same job,
  even under contention, verified by a concurrency test.
- **FR-4** A claim sets `status=claimed`, records `worker_id`, and sets
  `lease_expires_at`. The claimer receives the lease duration.
- **FR-5** Claim returns an empty response (`204`) when no work is available —
  never an error.
- **FR-6** Heartbeat extends the lease only for the job's current leaseholder.
  A stale worker's heartbeat is rejected and does not extend anything.
- **FR-7** An expired lease returns the job to `queued` for re-claim, incrementing
  `attempts`.
- **FR-8** Result submission accepts success (marking `done`) or failure. A failure
  marked retryable returns the job to `queued` with backoff up to the attempt cap;
  a permanent failure goes straight to `failed`.
- **FR-9** When `attempts` reaches the cap, the job enters `dead` and is **not**
  re-queued. Its resource is marked for attention.
- **FR-10** Every worker endpoint requires a valid HMAC signature over a canonical
  request representation. Unsigned or mis-signed requests are rejected.
- **FR-11** Signature comparison is constant-time. The shared secret is never
  logged, never echoed in an error, and never included in a URL.
- **FR-12** The API runs over HTTPS only; plain HTTP is refused.
- **FR-13** Workers can run concurrently without duplicating work.

## Behavioural contract

### State machine

```
                 ┌──────────► dead  (attempts exhausted)
                 │
queued ──claim──► claimed ──heartbeat──► claimed
   ▲                 │
   │                 ├──result ok──► done
   │                 │
   │                 ├──result retryable──► queued ──► (backoff)
   │                 │
   │                 └──result permanent──► failed
   │
   └─── lease expired (from claimed) ──┘
```

Terminal states: `done`, `failed`, `dead`. Only `queued` is claimable.

### Endpoints

All on the edge, all HMAC-authenticated, all HTTPS-only.

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/worker/claim` | Atomically claim next queued job; returns job + lease, or `204`. |
| `GET` | `/worker/jobs/{id}/file` | Download the source file for a job. |
| `POST` | `/worker/jobs/{id}/heartbeat` | Extend the lease. |
| `POST` | `/worker/jobs/{id}/result` | Submit the artifact; mark done or failed. |

### Claim semantics

1. Begin a transaction.
2. Select one `queued` job ordered by `created_at`, atomically — `FOR UPDATE SKIP LOCKED`
   when the runtime probe says it is available; otherwise an equivalent
   `UPDATE … SET lease … WHERE status='queued'` guarded by a lease timestamp.
3. Update to `claimed`, set `worker_id`, `lease_expires_at = now + lease`, `attempts += 1`.
4. Commit, return the job.

### Request signing

Canonical string over method, path, timestamp, and a hash of the body. The client
sends the signature and timestamp; the server recomputes, rejects stale timestamps
outside a window, and compares in constant time.

### Lease defaults

Lease duration and heartbeat interval are configuration. The heartbeat interval must
be comfortably **less than** the lease duration, so a healthy worker never loses its
lease mid-job. Record the chosen values in Decisions.

## Acceptance criteria

- **AC-1** A completed upload results in exactly one queued ingestion job for its resource.
- **AC-2** Claiming with an empty queue returns `204` and no body.
- **AC-3** **Concurrency test:** N workers claim in parallel and the union of returned
  job IDs contains no duplicates — no job is handed out twice.
- **AC-4** A claimed job is not returned to a second claim while its lease is valid.
- **AC-5** A heartbeat from the correct leaseholder extends the lease.
- **AC-6** A heartbeat from a **stale** worker is rejected and does not extend the lease.
- **AC-7** A job whose worker dies mid-job becomes claimable again after lease expiry,
  and `attempts` has incremented.
- **AC-8** A retryable failure returns the job to `queued`; permanent failure sets `failed`.
- **AC-9** A job failing up to the attempt cap lands in `dead` and is never re-queued.
- **AC-10** Unsigned, mis-signed, and stale-timestamp requests are all rejected `401/403`.
- **AC-11** A correct signature against the real shared secret is accepted — verified
  end-to-end with a reference client, not just a unit test.
- **AC-12** Signature comparison resists trivial timing observation (constant-time path).
- **AC-13** Plain HTTP to any worker endpoint is refused.
- **AC-14** The file download endpoint returns the correct bytes for the job's resource,
  and refuses any resource the worker is not currently leasing.
- **AC-15** Restarting the edge mid-queue loses no queued job.

## Open questions

- Lease duration and heartbeat interval — tuned against the slowest real job (an
  hours-long video transcription may need minutes, not seconds).
- Attempt cap — 3 or 5? Depends on how often transient failures occur.
- Backoff schedule: fixed interval, or exponential?
- Should dead-lettered jobs be re-queued manually from an admin surface in Sprint 6?
- IP allowlist for workers — feasible on the actual host? (NAT means the compute node
  may have a stable outbound IP, which makes this possible.)
- Claim ordering: strict FIFO, or priority first with FIFO within a priority?
- Should a claim request be able to filter by job type?

## Decisions

_Filled in during the sprint._

## Status

`not-started`
