# M2 — Knowledge (retrieval & grounding)

**Status:** implemented for authored text sources (M1 ingestion excluded); lexical retrieval with provenance, async/vector indexing pending.  
**Depends on:** M0, M1.  
**Enables:** M3, M4, M5, M6, M7.

## Purpose
Turn extracted content into retrievable knowledge so downstream modules generate outputs **grounded in sources**. M2 is the shared retrieval/context service; domain modules (M3–M6) consume it rather than re-implementing indexing.

## Scope
### In
- Chunking/segmentation of `ExtractedContent` with stable references back to source/resource (and offsets/pages/timestamps where available).
- Embedding generation and indexing for semantic retrieval (compute-side), with edge-side references/pointers as needed.
- Retrieval API: fetch relevant chunks/context for a query within a scope (resource, set of resources, or project/workspace).
- Re-index/update when extracted content changes or is reprocessed; basic lifecycle aligned with resource/project state.
- Provenance metadata sufficient for citations in generated outputs (M7 traceability).

### Out
- Content extraction (M1) and domain generation logic (M3–M7).
- Long-term knowledge-graph reasoning beyond retrieval needs (unless later required).
- Cross-tenant/global search beyond authorized scope.

## Entities & data
- **Chunk:** slice of extracted text with `resource_id` (and project scope), position/segment metadata, content hash/version.
- **Embedding/Index entry:** vector + model/id metadata linked to chunk (vectors stored compute-side; `knowledge_ref`/pointer persisted per M0 constraints).
- **Retrieval result:** ranked chunks + scores + provenance refs.

## Interfaces
- **From M1:** read extracted content/artifacts per resource.
- **To M3–M7:** `retrieve(scope, query|intent, k, filters) -> [chunks with provenance]`; `get_context(resource|project)` helpers as needed.
- **Jobs/worker:** embedding/index jobs enqueued and processed via M0 contract (serialized on compute).

## Processing flow
1. On extracted content ready (or updated), enqueue chunk+embed job.
2. Worker chunks, generates embeddings, writes index/vectors and edge refs.
3. Retrieval serves authorized, scoped context to generators.

## Acceptance criteria
- **AC-M2-1:** Extracted content is chunked with stable provenance back to source/resource (and segment/location where available).
- **AC-M2-2:** Embeddings/index are produced and queryable for a resource/project scope via a defined retrieval interface.
- **AC-M2-3:** Retrieval returns relevant, scoped results only to authorized members/owners and includes provenance for citation.
- **AC-M2-4:** Reprocessing/updates result in a consistent index (no stale/mixed versions served as current).
- **AC-M2-5:** Indexing runs through the async job pipeline with correct status/failure handling per M0.

## Dependencies & constraints
- Embedding stack aligned with compute preflight/resources (validated path: `nomic-embed-text` via Ollama, 768 dims) — vectors on compute, refs on edge.
- Must respect edge storage/compute limits; no heavy vector DB on cPanel.

## Decisions
- M2 is shared infrastructure; M3–M6 emit domain artifacts but rely on M2 for retrieval (avoids duplicated indexing).

## Open items
- Chunking strategy/size/overlap and retrieval ranking defaults per content type.
- Exact vector store/index implementation on compute and sync of edge refs.
- Scope rules for multi-resource/project retrieval.
