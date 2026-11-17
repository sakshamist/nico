# M3 — Quizzes & Assessments (leg)

**Status:** implemented: grounded MCQ generation (template + Ollama), private click-next attempts, server-side scoring/feedback; synchronous generation is an MVP exception.
**Depends on:** M0, M1, M2.
**Enables:** M7, M8.

## Purpose
Turn ingested knowledge into assessments that check understanding — quizzes, checks, rubrics, and feedback — grounded in the learner’s sources.

## Scope
### In
- Generate quizzes/assessments from selected resources or project knowledge (via M2 retrieval), with configurable intent/level where applicable.
- Question types (MVP set to be defined: e.g. MCQ, short answer, true/false) with correct answers/explanations and source references.
- Rubrics/feedback for responses where applicable.
- Capture attempts/results per user (and project context when relevant) for progress and for M7 reporting.
- Emit typed **Artifacts** (common contract) with provenance.

### Out
- Content extraction/indexing (M1/M2).
- Full grading/anti-cheat/proctoring systems (MVP).
- Certification/credentialing.

## Entities & data
- **Assessment/Quiz (Artifact):** items/questions, answers, explanations, difficulty/metadata, `source_refs`.
- **Attempt/Result:** user responses, scoring/feedback, timestamps, linked project/resource.

## Interfaces
- **Inputs:** M2 retrieval/context over chosen scope; M1 resources.
- **Outputs:** assessment artifacts + results consumable by M7 (evidence/progress) and renderable by M8 as webpages (click-next).
- **Generation:** queued via M0 job/worker where compute/LLM is required.

## Acceptance criteria
- **AC-M3-1:** A quiz/assessment can be generated for an authorized resource/project scope and stored as a typed artifact with source provenance.
- **AC-M3-2:** Questions include answer/explanation (and rubric/feedback where defined) traceable to sources.
- **AC-M3-3:** A user can take an assessment and receive stored results/feedback; results are scoped to authorized users/projects.
- **AC-M3-4:** Outputs are renderable as webpages in a click-next flow (M8).
- **AC-M3-5:** Not implemented (MVP exception). Generation is synchronous; failures fall back to deterministic templates. Closing it requires M0 job enqueueing plus worker result/failure handling.

## Dependencies & constraints
- Quality/grounding depends on M1 extraction and M2 retrieval; compute/LLM availability per M0/worker constraints.
- Generation must be schema/format-validated enough for reliable rendering and M7 composition.

## Open items
- MVP question types, difficulty controls, and scoring model.
- Whether assessments attach primarily to resources, projects, or both (support both via artifact refs recommended).
