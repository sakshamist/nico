# Nico — Modular Architecture (active plan)

Nico is developed as a set of **modules** with stable interfaces (not time-boxed sprints). Each module has its own charter: purpose, scope, entities/interfaces, dependencies, acceptance criteria, and status.

- **Historical (not in use):** sprint-based specs in [`../archive/sprints-2026/`](../archive/sprints-2026/).
- **System context:** [`../../ARCH.md`](../../ARCH.md), [`../../FLOW.md`](../../FLOW.md), [`../../README.md`](../../README.md).

## End-to-end flow

`M1 Injector → M2 Knowledge → (M3 Quizzes | M4 Lessons/Notes | M5 Live Projects → M6 Hackathons & Events) → M7 Thesis/Report → M8 Delivery`  
All modules run on **M0 Platform**. All outputs are **webpages** delivered in a simple **click-next** learning flow.

## Module index

| # | Module | Charter | Purpose | Depends on | Status |
|---|--------|---------|---------|------------|--------|
| M0 | [Platform](M0-platform.md) | foundation & shared contracts | Auth, DB/migrations, storage, jobs/worker contract, core entities & permissions | — | **Phase 1 implemented** (30/30 + 47/47 local) |
| M1 | [Injector / Ingestion](M1-injector-ingestion.md) | unified intake | Upload/link/brief → validate, store, extract (parse/OCR/ASR/fetch) to canonical content | M0 | not started (reframes S2,S4) |
| M2 | [Knowledge](M2-knowledge.md) | retrieval/grounding | Chunk, embed/index, retrieve context over ingested content | M0, M1 | **Implemented for authored sources** (M1 pending); lexical retrieval, chunks + provenance |
| M3 | [Quizzes & Assessments](M3-quizzes-assessments.md) | leg | Quizzes, checks, rubrics, feedback grounded in sources | M0–M2 | **Implemented**: grounded MCQs, click-next attempts, server-side scoring |
| M4 | [Lessons / Notes](M4-lessons-notes.md) | leg | Lessons, summaries, structured notes/paths | M0–M2 | **Implemented**: lesson steps, key points, progress, source refs |
| M5 | [Live Projects](M5-live-projects.md) | leg | Ongoing project workspaces, tasks/milestones, people & capability-based auto-assignment | M0, M1, M2 | **Implemented**: project/task UI, invites, profiles, best-fit/manual assignment, status, milestones, dependencies, artifact links |
| M6 | [Hackathons & Events](M6-hackathons-events.md) | leg (in workspace) | Hackathons (team) and Events/seminars (speakers) inside project workspaces, with countdown/timeline/resources | M0, M5, M1 | **Implemented**: events + hackathons, countdown/agenda/resources, RSVP/waitlist, teams/submissions, notes/Q&A artifacts |
| M7 | [Thesis / Report Composer](M7-thesis-report.md) | capstone | Auto-generate per-project report from work/artifacts with source references | M0–M6 | **Implemented**: versioned report artifact with citations to tasks/artifacts/sources/activities |
| M8 | [Delivery](M8-delivery.md) | experience | Web/PWA surfaces, navigation/progress, publishing, click-next learning flow | M0 + producers | **Implemented**: `/app` web workspace, renderer, click-next, CSRF-protected forms |

## Current implementation notes (M2–M8, M1 excluded)

- **Entry point:** server-rendered workspace at `/app`; JSON API remains under `/projects`, `/knowledge/*`, `/artifacts/*`, `/attempts/*` and HMAC worker paths under `/worker/*`.
- **M1 excluded:** M2 currently accepts authored text sources (`note` / `brief` / `transcript`). Each source creates a `resources` row (`source_type='text'`, `status='done'`) so artifacts can attach via `resource_id` and reuse M0 authorization.
- **Generation:** M3/M4/M7 use deterministic, edge-side template generation (`generator: nico-template-v1`) and write `status='ready'` artifacts synchronously. The artifact envelope is unchanged, so moving generation to the worker later does not change renderers.
- **M2 retrieval:** lexical keyword ranking over locally stored chunks with source/chunk provenance; vector embeddings remain the planned upgrade.
- **M3/M4 learning:** MASTERY attempts are private to the learner; quiz correctness is decided server-side. Lesson/quiz positions persist in `learning_progress` / `assessment_attempts`.
- **M5:** capability profile editing (`/app/profile`), project invites, task creation with milestones/dependencies/due dates, best-fit/manual assignment, release, status updates, and artifact-linked task context are available in the web workspace; assignment history remains on the JSON API.
- **M6:** activities are hosted per project; event RSVP enforces capacity with a waitlist; hackathons add teams, enforced team sizes, and submissions; both support agenda/timeline/resources and event notes/Q&A that are captured as versioned M6 artifacts.
- **M7:** reports cite current artifacts, knowledge sources, and activities, and cite task progress; regeneration creates a new artifact version.
- **Schema:** `edge/migrations/003_modules.php` adds knowledge, progress/attempt, and M6 tables; `004_task_artifact_link.php` links tasks to current project artifacts; `005_task_milestone_dependency.php` adds milestone/dependency planning fields. All are guarded and safe to re-run.
- **Verification:** `./dev.sh test` runs Sprint 1 (30), M0 (47), and modules (54); `./dev.sh test:modules` runs the M2–M8 workflow smoke test.

## Locked decisions

- **Modular, not sprints.** Track per module (charters, contracts, ACs). Sprint docs are archived, not active.
- **M5 Live Projects:** many ongoing projects; many tasks assigned to multiple people over time. Nico **auto-assigns tasks based on capabilities**. Hard filter: required tags present at `level >= required`, availability not `away`, open tasks `< max_open_tasks`. Score: `Σ(level − required_level) − open_task_count`; tie-break least recently assigned (never assigned wins), then `user_id ASC`; zero candidates → `needs_human`; manual override logged.
- **M6 Hackathons & Events (distinct, native to workspace):**
  - *Hackathon* = team event where multiple people join/form teams and work together (collab tasks/submissions).
  - *Event* = seminar/talk (e.g. voice seminar): industry speakers engage Nico users (speaker/topic/schedule, join info, materials, notes/Q&A). Nico owns the workspace page/coordination (countdown, agenda/timeline, checklist/resources; external live-delivery link as needed).
  - Both live **inside a project’s native workspace**.
- **M7 Thesis/Report:** auto-generated **per project** from its tasks/progress and produced artifacts, with citations/references to sources; regeneratable. Output as **webpage**.
- **M8 Delivery:** all outputs are **webpages**; learning content presented in a simple **click-next** flow.

## Shared concepts & contracts (cross-cutting)

- **Project / Workspace:** container for ongoing work; hosts resources, tasks, people, hackathons/events, and generated artifacts/reports.
- **User & Capability profile:** person on Nico with capabilities/tags/roles used by M5 for auto-assignment.
- **Resource / Source:** ingested learning item (file/link/brief) with extracted content (M1).
- **Task & Assignment:** unit of work in a project, assigned to person(s)/team (M5/M6), with status/progress.
- **Artifact (common contract):** typed, versioned output (`project_id?`, `resource_id?`, `module`, `type`, `content`, `provenance/source_refs`, `status`) emitted by M3–M6/M7 so M8 can render and M7 can compose uniformly.
- **Traceability:** generated outputs (esp. M7) carry provenance to artifacts and source resources.
- **Async boundary:** heavy ingestion/generation runs via the job/worker pipeline (edge queue + compute) per `ARCH.md` constraints; charters state sync vs queued operations.

## Dependency-driven build order

1. **M0** — extend shared contracts (project/workspace, capability/profile, task+assignment, artifact, queue/worker) on existing baseline.
2. **M1** Injector/Ingestion → **M2** Knowledge/retrieval.
3. **M5** Live Projects (workspace, tasks, capability auto-assign) and **M3/M4** in parallel once M2 is ready.
4. **M6** Hackathons & Events (distinct types inside workspace, countdown/timeline flows).
5. **M7** Per-project report composer → **M8** delivery/click-next experience layered throughout.

(Order reflects dependencies, not schedule; modules evolve independently behind contracts.)

## Verified constraints (carry forward)

Recorded in archived specs/`ARCH.md`; still authoritative for M0/M1/M2:
- Atomic job claim: `SKIP LOCKED` available on MariaDB ≥10.6 (cPanel default ~10.11) — **probe at runtime**; host version unverified.
- cPanel edge limits: no long jobs/binaries/websockets; FTP deploy; `upload_max_filesize` often 2M (must be raised/verified for media). Effective ceiling: `memory_limit ≥ post_max_size ≥ upload_max_filesize`.
- Compute: heavy work offloaded to Mac worker behind NAT (pull/HMAC); worker serializes work. Preflight gate (≥15 GB free, required binaries e.g. ffmpeg/tesseract). Planning machine currently fails preflight (disk/RAM/binaries).
- Embeddings: `nomic-embed-text` via Ollama (768 dims) validated as viable path.

## How to use these docs

- Treat each `M*.md` as the source of truth for that module’s contract and ACs.
- Change scope by updating the relevant charter (and dependencies) — do not reintroduce sprint docs.
- Mark a module done only when its acceptance criteria are verifiably true.
