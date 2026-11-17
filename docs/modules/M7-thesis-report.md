# M7 — Thesis / Report Composer (capstone)

**Status:** implemented: per-project report artifact with task/artifact/source/activity citations; regeneration versions the report.  
**Depends on:** M0, M2, M3, M4, M5, M6 (consumes their artifacts; available per project).  
**Enables:** M8.

## Purpose
Automatically generate a **thesis/project report per project** that documents the work done in that project — synthesized from its tasks, progress, and produced artifacts — with references/citations to sources.

## Scope
### In
- Per-project report generation (on demand / regeneratable) over a selected project/workspace.
- Compose from project evidence: M5 tasks/milestones/assignments/progress, M3 assessment results, M4 lessons/notes, M6 hackathon/event outputs (teams/submissions, session notes/materials), and underlying M1/M2 source references.
- Structured report webpage (sections such as overview, objectives/context, work & contributions, outcomes/results, learnings, references) appropriate to the project’s activity.
- **Traceability:** each section/claim links to supporting artifacts and source resources (provenance).
- Emit report as a typed **Artifact** (versioned, regeneratable) renderable by M8.

### Out (MVP)
- In-app manual rich-text editing/collaboration workflow (report is generated/regenerated; editing can be a later enhancement).
- Export formats beyond webpage (PDF/MD export later if needed).
- Plagiarism detection or formal academic formatting guarantees beyond structured, cited output.

## Entities & data
- **Report (Artifact):** `project_id`, structure/sections, rendered content, generation metadata (inputs/version), `provenance/source_refs` per section/item.
- **Evidence set:** resolved collection of project artifacts + source refs used for composition.

## Interfaces
- **Inputs:** M0 project/workspace; M5 project/task/progress artifacts; M3/M4/M6 artifacts; M2 retrieval/provenance and M1 source refs.
- **Outputs:** versioned report artifact + webpage for M8.
- **Generation:** queued via M0 job/worker where compute/LLM is required; deterministic/inspectable assembly preferred where possible.

## Processing flow
1. Select project and gather authorized artifacts/evidence + source refs.
2. Organize by timeline/structure and compose sections grounded in evidence.
3. Attach citations/provenance; persist versioned report artifact.
4. Render as webpage (M8); allow regeneration when project state changes.

## Acceptance criteria
- **AC-M7-1:** A report can be generated for a specific authorized project and stored as a versioned, typed artifact.
- **AC-M7-2:** Report content reflects that project’s actual work/products (tasks/progress and available M3/M4/M6 artifacts), not generic text.
- **AC-M7-3:** Sections include citations/references/provenance to supporting artifacts and source resources.
- **AC-M7-4:** Report is viewable as a webpage (M8) and can be regenerated to reflect updated project state.
- **AC-M7-5:** Generation runs with clear status/failure handling per M0; access is scoped to project membership/ownership.

## Dependencies & constraints
- Quality and completeness depend on upstream artifact/provenance quality (M1–M6).
- Must handle sparse projects gracefully (report what exists, clearly indicate gaps) without fabricating evidence.

## Open items
- Report templates/sectioning per project/activity mix (live project vs hackathon-heavy vs event-heavy).
- Regeneration triggers/policy and version history UX.
- Depth of citation granularity (artifact-level vs source-chunk level via M2).
