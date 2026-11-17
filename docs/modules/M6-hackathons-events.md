# M6 — Hackathons & Events (leg, native to workspace)

**Status:** implemented: project-hosted hackathons/events, countdown, agenda/timeline/resources, RSVP + waitlist, enforced team sizes, submissions, event notes/Q&A captured as versioned artifacts, and manager status updates.
**Depends on:** M0, M5 (native workspace), M1 (briefs/materials intake).  
**Enables:** M7, M8.

## Purpose
Run time-bound activities **inside a project’s native workspace**. Hackathons and Events are **distinct** activity types: hackathons are collaborative team builds; events are seminars/talks (e.g. voice seminars) with industry speakers for Nico users.

## Scope
### In
- Activities live within an M5 project workspace (workspace provides members, tasks, context).
- **Hackathon (team event):**
  - Team formation/joining: multiple people join/form teams and work together within the workspace.
  - Hackathon page with brief/theme, **live countdown**, timeline/milestones, checklist, resources/links, team roster, and submission/work artifacts.
  - Collaboration via team-scoped tasks/work items linked to M5.
- **Event (seminar/talk):**
  - Speaker/session page: topic, speaker(s)/industry guests, schedule, **live countdown**, agenda/timeline, join/access info, materials/resources, and notes/Q&A artifacts.
  - Designed for sessions such as voice seminars where speakers engage Nico users (Nico hosts coordination/page; live delivery may use an external link/tool).
- Intake of briefs/links/materials via M1; emit typed **Artifacts** for M7/M8.
- Enriched, informative pages (reference: Devpost/Devfolio-style event/hackathon pages) adapted to workspace context.

### Out (MVP)
- External event discovery/marketplaces, ticketing/payments, or platform submission integrations.
- Built-in video/voice conferencing infrastructure (provide join links/details; integrate delivery later if needed).
- Complex bracket/judging platforms beyond basic submission/artifact capture (evolvable).

## Entities & data
- **Hackathon:** workspace_id, brief/theme, start/end, countdown, timeline/milestones, teams, resources, submissions/artifacts, status.
- **Team:** members, roles, linked tasks/work, submission refs (hackathon-scoped).
- **Event (Seminar):** workspace_id, topic/description, speaker(s), schedule/agenda, countdown, join info, materials, notes/Q&A artifacts, status.
- **Artifacts:** agendas, checklists, team/submission records, session notes/materials with provenance.

## Interfaces
- **To M5/M0:** hosted in project workspace; uses membership/permissions and tasks/assignments (team tasks).
- **From M1:** ingest briefs, links, speaker/event materials as resources.
- **To M7:** provides participation/work artifacts (submissions, notes, outcomes) for per-project reports.
- **To M8:** renders distinct hackathon and event webpages with countdown/timeline and click-next/stepwise views where applicable.

## Acceptance criteria
- **AC-M6-1:** Hackathons and Events are distinct types creatable within a project workspace with enforced membership/permissions.
- **AC-M6-2 (Hackathon):** Supports team formation/joining and team-based work (team membership, team-linked tasks/work, submission/artifacts) visible on a hackathon webpage.
- **AC-M6-3 (Event/Seminar):** Supports speaker-led sessions with speaker/topic, schedule/agenda, join/access info, materials, and notes/Q&A captured as artifacts on an event webpage.
- **AC-M6-4:** Hackathon and event pages display enriched details including an accurate **live countdown** and timeline/agenda/checklist/resources as applicable.
- **AC-M6-5:** Briefs/links/materials are ingested via M1 and referenced with provenance.
- **AC-M6-6:** Activity artifacts/state are available to M7 and rendered as webpages in M8.

## Dependencies & constraints
- Depends on M5 workspace/membership and M0 shared contracts; uses M1 for content intake.
- Real-time delivery (voice/video) is external in MVP; Nico must represent join details and capture outputs/artifacts.

## Confirmed fields (Phase 1 decisions)
- **Both:** timezone, lifecycle status, visibility, organizer roles, reminders.
- **Hackathon:** team size, deadlines, judging, prizes/tracks, rules link.
- **Event:** speaker bio, RSVP/capacity, Q&A, recording (recordings feed M1).

## Open items
- Join approvals and submission format details.
- RSVP/attendance tracking depth.
- Live in-page updates vs periodic refresh for countdown/status.
