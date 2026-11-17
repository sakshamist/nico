# M4 — Lessons / Notes (leg)

**Status:** implemented: structured lesson generation from chunks (template + Ollama), per-user step progress, source references; synchronous generation is an MVP exception.
**Depends on:** M0, M1, M2.
**Enables:** M7, M8.

## Purpose
Turn ingested knowledge into structured learning material — lessons, summaries, and study notes — that users consume step by step.

## Scope
### In
- Generate lessons/summaries/notes from selected resources or project knowledge (via M2 retrieval).
- Structured, ordered content suitable for sequential (click-next) consumption (sections/steps, key points, examples as applicable).
- Ground outputs in sources with references/provenance.
- Emit typed **Artifacts** (common contract) for rendering (M8) and composition (M7).

### Out
- Content extraction/indexing (M1/M2).
- Interactive tutoring/chat beyond generated material (unless later added).
- Rich WYSIWYG authoring inside Nico (MVP: generated/regeneratable).

## Entities & data
- **Lesson/Notes (Artifact):** ordered sections/steps, summaries, highlights, metadata, `source_refs`, linked resource/project.

## Interfaces
- **Inputs:** M2 retrieval/context over chosen scope; M1 resources.
- **Outputs:** lesson/notes artifacts consumable by M7 and renderable by M8 as webpages in click-next flow.
- **Generation:** queued via M0 job/worker where compute/LLM is required.

## Acceptance criteria
- **AC-M4-1:** Lessons/notes can be generated for an authorized resource/project scope and stored as typed artifacts with source provenance.
- **AC-M4-2:** Content is structured/ordered for stepwise (click-next) consumption.
- **AC-M4-3:** Outputs are traceable to source resources/sections where available.
- **AC-M4-4:** Outputs render as webpages in the click-next learning flow (M8).
- **AC-M4-5:** Not implemented (MVP exception). Generation is synchronous with deterministic fallback; M0 job status/failure handling would be needed to close it.

## Dependencies & constraints
- Depends on M1 extraction and M2 retrieval quality; compute/LLM availability per M0/worker constraints.
- Output schema/format must be stable enough for M8 rendering and M7 composition.

## Open items
- Canonical lesson/notes structure (steps, depth levels) aligned with M8 click-next UX.
- Personalization by user capability/progress (potential link to M5 profiles) — later enhancement.
