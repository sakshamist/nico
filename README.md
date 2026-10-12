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

- [`FLOW.md`](FLOW.md) — how the project works, step by step.
- [`ARCH.md`](ARCH.md) — the design and why it was chosen.
- [`specs/`](specs/) — the build plan: what gets built, in what order, and how we know each piece is done.

## Build plan

The project is built in **sprints**. Each sprint has its own spec with numbered
requirements (`FR-`) and acceptance criteria (`AC-`), and is only done when every
criterion is verifiably true.

| # | Sprint | Delivers |
|---|--------|----------|
| 1 | [Foundations](specs/SPRINT-01-foundations.md) | App skeleton, database schema, auth, environment preflight |
| 2 | [Upload & File Management](specs/SPRINT-02-upload-file-management.md) | Files stored safely and listed immediately |
| 3 | [Job Queue & Worker Protocol](specs/SPRINT-03-job-queue-worker-protocol.md) | Durable queue, leases, the edge worker API |
| 4 | [Mac Worker & Ingestion](specs/SPRINT-04-mac-worker-ingestion.md) | Worker daemon, OCR/ASR/parsing, extracted text |
| 5 | [Knowledge & Generation](specs/SPRINT-05-knowledge-generation.md) | Chunks, embeddings, quizzes, lessons, materials |
| 6 | [Delivery & Hardening](specs/SPRINT-06-delivery-hardening.md) | Progress UI, web/PWA/blog, ops and security |

See [`specs/README.md`](specs/README.md) for the shared glossary, the sprint
template, and the constraints already verified during planning.

## Status

Design complete. Implementation has not started — Sprint 1 is next.
