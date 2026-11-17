# M8 — Delivery (web experience & learning flow)

**Status:** implemented: server-rendered `/app` workspace, artifact renderer, click-next lessons/quizzes, dashboard, CSRF-protected forms.  
**Depends on:** M0 + producing modules (M1–M7) for content; integrates progressively.  
**Enables:** end-user consumption.

## Purpose
Deliver everything Nico produces as a coherent **web experience**: browse workspaces and resources, view progress, and consume learning/project outputs as **webpages** in a simple **click-next** flow.

## Scope
### In
- Web UI (and PWA where applicable) for authenticated users: navigation across projects/workspaces, resources/library, and module outputs.
- **Rendering of module outputs as webpages:** M3 quizzes/assessments, M4 lessons/notes, M5 project/task/people views, M6 hackathon & event pages (with countdown/timeline), M7 project reports.
- **Click-next learning flow:** stepwise/linear progression for learning content (lessons/notes, quiz sequences) with clear next/previous and progress indication.
- Status/progress surfaces (ingestion/processing, task/progress, generation state) backed by M0/M1–M7 state.
- Publishing/view access control consistent with M0 ownership/membership/roles.
- Basic operational hardening relevant to delivery: safe rendering, error/empty states, pagination/perf basics (within host constraints).

### Out (MVP)
- Native mobile apps (beyond PWA, if pursued).
- Public marketplace/discovery of others’ content (unless explicitly opened).
- Advanced authoring/CMS or rich export suite (webpage-first; other exports later).
- Built-in realtime conferencing (M6 links out).

## Interfaces
- **Consumes:** M0 APIs/entities and `Artifact`/domain records from M1–M7 (common artifact contract for generated outputs).
- **Provides:** routes/pages, navigation, and interaction flows (take quiz, step through lesson, view workspace/hackathon/event/report).
- **Cross-cutting:** respects auth/permissions; reflects async job status; renders provenance/source references where provided.

## Key UX behaviors
- **Webpages for all outputs:** each output type has a stable, linkable page/view.
- **Click-next flow:** learning sequences presented as ordered steps/pages with explicit next/previous controls and progress (resume where applicable).
- **Live/time-based views:** M6 pages show countdown and timeline/agenda; values update on view/refresh (and/or lightweight polling) as appropriate.
- **Distinct M6 views:** hackathon (teams/join/work/submissions) vs event/seminar (speaker/topic/schedule/join/materials/notes) rendered as different page types within the workspace.

## Acceptance criteria
- **AC-M8-1:** Authenticated users can navigate projects/workspaces and view resources and available outputs as webpages, scoped by permissions.
- **AC-M8-2:** M3/M4 learning content is consumable in a stepwise **click-next** flow with progress/position.
- **AC-M8-3:** M5 project/task/people and M6 hackathon/event pages render correctly, including countdown/timeline/agenda and type-specific details (teams vs speakers/sessions).
- **AC-M8-4:** M7 project reports render as webpages with accessible source/provenance references.
- **AC-M8-5:** Processing/generation/status states are visible and consistent with underlying module/M0 states; empty/error states are handled clearly.
- **AC-M8-6:** Rendering enforces authorization and safely handles untrusted/generated content.

## Dependencies & constraints
- cPanel edge constraints (no websockets assumed) — use polling/refresh for live-ish updates; keep pages light.
- Depends on stable artifact/domain contracts from producers; evolve views alongside module charters.
- PWA/offline behavior, if included, must degrade gracefully given server/compute availability.

## Open items
- Exact navigation/IA and click-next interaction details per content type.
- PWA scope/caching strategy within cPanel limits.
- How countdown/timers refresh (client-side compute from timestamps vs polling).
