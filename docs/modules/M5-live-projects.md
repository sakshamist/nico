# M5 — Live Projects (leg)

**Status:** implemented: project/task web workspace (incl. project status lifecycle), member invites, capability profiles, best-fit assign/release, manual override, task status updates, milestones/phases, dependencies, due dates, and artifact-linked task context.
**Depends on:** M0 (workspace, users/capabilities, tasks, artifacts), M1/M2 (learning context, recommended).  
**Enables:** M6, M7, M8.

## Purpose
Support many ongoing **live projects** where participants learn by doing. Each project is a workspace with many tasks/milestones carried out over time by multiple people, with Nico **auto-suggesting and auto-assigning work based on capabilities**.

## Scope
### In
- **Project workspaces (live):** create/manage many ongoing projects; membership and roles/permissions via M0.
- **People & capabilities:** capture/maintain member capability profiles (skills/tags/levels/interests, availability signals as available) used for matching.
- **Tasks & milestones:** define tasks (requirements/capability tags, effort/priority, dependencies/context), group into milestones/phases, track status/progress over time.
- **Auto-assignment (capability-based):** Nico recommends and/or auto-assigns tasks to people over time based on capabilities, task requirements, and workload/progress (MVP: transparent rule-based matching; evolvable to learned signals). Manual override/adjustment supported.
- Link tasks/work to relevant learning resources and outputs (M1–M4) to connect learning → doing.
- Emit typed **Artifacts** (plans, task/milestone states, progress snapshots/evidence) for M7 and M8.

### Out
- External source-control/project-management integrations (GitHub/Jira/etc.) — not MVP.
- Payroll/HR or formal performance management.
- Hackathon/event-specific flows (M6) beyond hosting them in the workspace.

## Entities & data
- **Live Project (Workspace):** title, goals/context, status, members; hosts resources, tasks, events/hackathons, artifacts.
- **CapabilityProfile:** per-user capabilities/tags (and proficiency/availability as defined) scoped globally and/or per project.
- **Task/Milestone:** requirements, assignee(s), due/priority, dependencies, status, progress/evidence links.
- **Assignment decision:** who/why/when (auto vs manual) for traceability.
- **Artifacts:** project plan, task board/state, progress summaries linked to work and sources.

## Interfaces
- **To M0:** uses workspace/membership/permissions, task/assignment, and artifact contracts.
- **From M1/M2:** attach/reference learning resources and retrieved context to tasks/projects.
- **To M6:** provides the native workspace that hosts hackathons/events.
- **To M7:** supplies project structure, tasks, assignments, and progress/evidence artifacts.
- **To M8:** renders project workspace, task/people, and progress webpages.

## Auto-assignment (MVP behavior — implemented in M0)
- **Hard filter:** every required tag present at `level >= required_level`; availability != `away`; open tasks `< max_open_tasks`.
- **Score:** `Σ(level − required_level) − open_task_count` (higher wins).
- **Tie-break:** least recently assigned first (never-assigned wins), then `user_id ASC`.
- **No candidates:** task state becomes `needs_human` with the exclusion reasons recorded.
- Every auto/manual/release decision is appended to `task_assignment_log` with rationale and actor; manual override bypasses capability filtering for project members.
- Re-evaluation cadence remains open (currently caller-triggered via the assign endpoint).

## Acceptance criteria
- **AC-M5-1:** Multiple live project workspaces can be created with members and enforced permissions.
- **AC-M5-2:** Projects support many tasks/milestones with status/progress tracked over time.
- **AC-M5-3:** Members have capability profiles usable for matching; tasks declare requirements/tags.
- **AC-M5-4:** Nico auto-suggests/auto-assigns tasks to people based on capabilities and workload/progress, records the assignment rationale/source, and supports manual override.
- **AC-M5-5:** Tasks/work can be linked to learning resources/artifacts for context and traceability.
- **AC-M5-6:** Project/task/assignment state is emitted/available as artifacts for M7 and renderable as webpages (M8).

## Dependencies & constraints
- Requires M0 identity/permission and shared data contracts; benefits from M1/M2 for grounded task context.
- Auto-assignment is MVP rule-based and must remain transparent and correctable.

## Open items
- Capability taxonomy/granularity and authoring UI (API exists: `/profiles/{id}/capabilities`).
- Assignment triggers (on task create, on progress, periodic) — API is caller-triggered today.
- Conflict/consent rules for reassignment; multi-assignee/team tasks arrive with M6.
