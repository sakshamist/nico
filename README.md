# Nico

Nico turns learning resources into lessons, quizzes, and study material.

Upload a PDF, image, audio, video, text, or link. Nico reads it, understands it, and creates content from it. You can then view everything on the web, blog, or app.

## How it works

```
Upload → Store → Queue → Process → Generate → Display
```

- **Upload & Store** — the website saves your file and shows it in your list right away.
- **Queue** — the file waits for its turn to be processed.
- **Process** — a separate machine reads it (text, images, audio, and video all work).
- **Generate** — it builds quizzes, lessons, and learning materials.
- **Display** — results appear on the web, blog, and app.

Processing happens out of sight, so the site stays fast while it works in the background.

## Docs

- [`FLOW.md`](FLOW.md) — how the project works, step by step (system context).
- [`ARCH.md`](ARCH.md) — the design and why it was chosen (system context).
- [`docs/modules/README.md`](docs/modules/README.md) — **active plan:** modular architecture, module index, and per-module charters (`M0`–`M8`).
- [`docs/modules/`](docs/modules/) — per-module charters (purpose, scope, interfaces, acceptance criteria).
- [`docs/archive/sprints-2026/`](docs/archive/sprints-2026/) — **OLD / not in use:** earlier sprint-based specs and status (kept for history; Sprint 1 was built & verified 30/30).
- [`docs/LOCALDEV.md`](docs/LOCALDEV.md) — local setup and cPanel deployment.

## Modular plan (active)

The project is developed as **modules** with stable interfaces (not time-boxed sprints). All outputs are **webpages** delivered in a simple **click-next** learning flow.

**Flow:** `M1 Injector → M2 Knowledge → (M3 Quizzes | M4 Lessons/Notes | M5 Live Projects → M6 Hackathons & Events) → M7 Thesis/Report → M8 Delivery`, all on **M0 Platform**.

| # | Module | Purpose |
|---|--------|---------|
| M0 | Platform | Auth, DB/migrations, storage, jobs/worker contract, shared entities & permissions (baseline built) |
| M1 | Injector / Ingestion | Unified intake: upload/link/brief → validate, store, extract (parse/OCR/ASR/fetch) |
| M2 | Knowledge | Chunking, embeddings/index, retrieval/grounding over ingested content |
| M3 | Quizzes & Assessments | Quizzes, checks, rubrics, feedback grounded in sources |
| M4 | Lessons / Notes | Structured lessons, summaries, study notes/paths |
| M5 | Live Projects | Project workspaces, tasks/milestones, people & capability-based auto-assignment over time |
| M6 | Hackathons & Events | Distinct hackathons (team) and events/seminars (speakers) inside project workspaces, with countdown/timeline |
| M7 | Thesis / Report Composer | Auto-generated per-project report from work/artifacts with source references |
| M8 | Delivery | Web/PWA surfaces, progress, publishing, click-next learning flow |

See [`docs/modules/README.md`](docs/modules/README.md) for dependencies, locked decisions, shared contracts, and per-module charters.

## Status

- **M0 Platform — Phase 1 built and locally verified** (30/30 + 47/47 acceptance checks), including projects/membership, capability profiles, tasks/best-fit assignment, versioned artifacts, durable queue, and HMAC worker API.
- **M1 Injector / Ingestion — not started.**
- **M2–M8 — implemented for the current scope** (authored knowledge sources instead of ingestion, deterministic edge-side generation, lexical retrieval). Web workspace is served at `/app`.
- Module workflow checks: 56/56 (`./dev.sh test:modules`).

Known items carried forward: ingestion/worker generation still needs compute preflight (disk/RAM/binaries); cPanel host DB version and upload ceiling must be verified/raised for media. Historical sprint status: [`docs/archive/sprints-2026/STATUS.md`](docs/archive/sprints-2026/STATUS.md).

### Running locally

```bash
./dev.sh db:start     # start the pinned MariaDB 10.11 container
./dev.sh migrate      # create the schema
./dev.sh serve        # edge app on http://127.0.0.1:8080
./dev.sh test         # verify Sprint 1, M0, and M2–M8 module workflow
./dev.sh preflight    # check the compute machine can run the pipeline
```

`dev.sh --help` lists everything. The edge app has no third-party PHP dependencies,
since cPanel offers no `composer`. See [`docs/LOCALDEV.md`](docs/LOCALDEV.md) for
the full local setup and troubleshooting.


