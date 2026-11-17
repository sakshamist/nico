# Sprint 5 — Knowledge Modelling & Generation

**Status:** not-started

## Goal

Turn extracted text into retrievable knowledge on the Mac, then generate a quiz, a
lesson, and study material per resource — keeping vectors off the edge entirely.

## Depends on / Enables

- **Depends on:** Sprint 4 (extracted text exists), Sprint 3 (job fan-out).
- **Enables:** Sprint 6 (displays outputs).

## Scope

### In

- Chunking extracted text into retrievable units.
- Computing embeddings per chunk.
- A vector store on the Mac; the edge keeps only pointers.
- `knowledge_refs` rows on the edge.
- Job fan-out: one completed ingestion enqueues embed, and embed enqueues N generation jobs.
- Generation of a quiz, a lesson, and study material from retrieved chunks.
- Structured output that validates against a schema.
- Storing outputs on the edge.
- Handling sources too short to chunk, and sources with no extractable text.

### Out

- Delivering outputs to any UI (Sprint 6).
- Blog formatting and PWA concerns (Sprint 6).
- Semantic search exposed to end users.
- Fine-tuning or any model training.
- Image or diagram understanding beyond OCR text.
- Content safety filtering of generated material (flagged in Sprint 6).

## User stories

- As a student, I get a quiz built from my lecture notes.
- As a student, I get a lesson that explains the material.
- As a student, I get study material to revise from.
- As a student, generated questions are actually about *my* material, not generic trivia.
- As the system, a long PDF generates a quiz covering the whole document, not just page one.
- As the system, a file with no readable text fails clearly rather than producing an empty quiz.

## Functional requirements

- **FR-1** Extracted text is split into chunks sized for retrieval, with enough
  overlap that a fact spanning a boundary is still findable.
- **FR-2** Chunk boundaries prefer semantic seams (paragraph, section, slide) over
  blind fixed-size cuts, where the source format exposes them.
- **FR-3** Each chunk gets an embedding vector of a fixed, recorded dimension.
- **FR-4** Vectors are stored on the Mac in a local vector store. The edge stores
  only a pointer plus the chunk text.
- **FR-5** Generation retrieves the most relevant chunks for the requested output
  type, so content is grounded in the actual source material.
- **FR-6** Generation produces **structured output conforming to a defined schema**
  — a quiz has questions, each with options and a correct answer; a lesson has
  ordered sections. Output that fails validation is rejected, not stored.
- **FR-7** Fan-out: when ingestion completes, an `embed` job is enqueued; when
  embedding completes, one generation job per output type is enqueued. Fan-out is
  part of the job graph, not a side effect of the worker.
- **FR-8** A resource with too little text to generate from is marked with a clear
  reason — not an empty output and not a crash.
- **FR-9** Generation is reproducible: the same resource processed twice with the
  same inputs yields the same output, or the variance is recorded.
- **FR-10** Generation never blocks the worker past the lease: it heartbeats, and if
  it cannot finish within the lease it fails retryably rather than being lost.
- **FR-11** Prompt content sent to the model is bounded — oversized resources are
  handled by retrieving and iterating, never by sending the whole document at once.
- **FR-12** The LLM and embedding steps run under the same serialisation rule as
  Sprint 4 — one heavy model resident at a time.

## Behavioural contract

### Job graph

```
ingestion job ──done──► embed job ──done──► generate_quiz
                                          ├► generate_lesson
                                          └► generate_material
```

Fan-out points are recorded in the payload so the graph is inspectable and
re-runnable. Re-running a failed generation does not re-run ingestion.

### Chunking

| Property | Behaviour |
|----------|-----------|
| Target size | Tunable, recorded in Decisions; sized to fit a prompt with several chunks. |
| Overlap | Enough to avoid losing facts at boundaries. |
| Boundaries | Prefer paragraph/section/slide seams. |
| Provenance | Each chunk retains its source location (page, section, timestamp). |

### Embeddings

- Backend: **Ollama `nomic-embed-text`, 768 dimensions** (verified working).
- Rationale: already installed and functional; avoids a `torch` dependency and
  roughly 2.3 GB of additional disk — the binding constraint on this machine.
- Same **dimension for every chunk of every resource**, so vectors are comparable.

### Output schemas

**Quiz** — a list of questions; each has a prompt, options, one correct answer,
an explanation, and a source reference to the chunk it came from.

**Lesson** — ordered sections; each has a heading and body content, and a source
reference.

**Material** — a study aid; structure TBD (see open questions), but likewise
schema-validated and source-referenced.

Source references are how a user can tell generated content came from their own
material rather than the model's memory.

## Acceptance criteria

- **AC-1** A real PDF produces chunks, embeddings, and a stored `knowledge_refs` row
  per chunk.
- **AC-2** Every vector has the same recorded dimension, and the edge holds **no
  vector data** — only pointers and text.
- **AC-3** A chunk boundary test confirms a fact spanning a boundary is still
  retrievable from at least one chunk.
- **AC-4** End-to-end: a real uploaded PDF yields a stored quiz that **validates
  against the schema**.
- **AC-5** Quiz questions are grounded — each carries a source reference that
  resolves to a real chunk of the user's material.
- **AC-6** A lesson and a material output are generated and schema-validated.
- **AC-7** Generation covers the **whole** document: a quiz from a long PDF contains
  questions referencing content from both the beginning and the end.
- **AC-8** Output that fails schema validation is rejected and retried or failed —
  never stored as a broken output.
- **AC-9** Fan-out is verified from the job graph: exactly one embed job per
  ingestion, and exactly one generation job per output type per resource.
- **AC-10** A resource with no extractable text ends in a clear, explained state —
  no empty quiz, no crash.
- **AC-11** No heavy step runs concurrently with another; verified as in Sprint 4.
- **AC-12** A very long resource is generated without exceeding prompt limits, and
  completes within the lease.

## Open questions

- **Quiz schema:** multiple choice only, or also short answer / true-false? MC-only is
  a much simpler validation target.
- How many questions per quiz, and how many per lesson section?
- **Material structure:** summary? flashcards? revision checklist? Undecided — the
  schema depends on it.
- Chunk size and overlap — tune empirically once real PDFs are in hand.
- Retrieval strategy: vector only, or hybrid with keyword/BM25? Hybrid usually helps
  on short factual quizzes.
- How many retrieved chunks per generation call, and does prompt length bind us
  before context does?
- Grounding: should generation be constrained to retrieved chunks only, or allowed to
  add general knowledge? Constrained is safer for study material.
- Which local model, and what context length? Verify the worker's actual model
  inventory before assuming.
- Content safety filtering — where does it go, and does it belong in this sprint
  rather than Sprint 6?
- Do we store prompts and model parameters alongside outputs for reproducibility and
  debugging?

## Decisions

_Filled in during the sprint._

## Status

`not-started`
