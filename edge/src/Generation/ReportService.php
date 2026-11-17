<?php

declare(strict_types=1);

namespace Nico\Edge\Generation;

use Nico\Edge\Activities\ActivityRepository;
use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Projects\ProjectRepository;
use Nico\Edge\Tasks\TaskRepository;

/**
 * M7 thesis/report composition.
 *
 * A report is an artifact like any other: it cites the M5/M6 evidence that
 * produced it, so a reader can walk from a claim back to the quiz, task, source,
 * or activity that supports it.
 */
final class ReportService
{
    public function __construct(
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly TaskRepository $tasks = new TaskRepository(),
        private readonly ArtifactRepository $artifacts = new ArtifactRepository(),
        private readonly KnowledgeRepository $knowledge = new KnowledgeRepository(),
        private readonly ActivityRepository $activities = new ActivityRepository(),
    ) {
    }

    public function generateForProject(int $projectId, int $userId, string $role): int
    {
        if (!$this->memberships->canView($projectId, $userId, $role)) {
            throw new \InvalidArgumentException('You cannot read that project.');
        }

        $project = $this->projects->find($projectId);

        if ($project === null) {
            throw new \InvalidArgumentException('Unknown project.');
        }

        $tasks = $this->tasks->listByProject($projectId);
        $artifacts = $this->artifacts->listForProject($projectId);
        $sources = $this->knowledge->listSources($userId, $role, $projectId);
        $activities = $this->activities->listByProject($projectId);

        // Source-scoped quizzes/lessons generated from project sources are part
        // of the project evidence trail even though their artifact row is scoped
        // to the resource.
        $byId = [];
        foreach ($artifacts as $artifact) {
            $byId[(int) $artifact['id']] = $artifact;
        }
        foreach ($sources as $source) {
            foreach ($this->artifacts->listForResource((int) $source['resource_id']) as $artifact) {
                $byId[(int) $artifact['id']] ??= $artifact;
            }
        }
        $artifacts = array_values($byId);

        $taskCounts = ['open' => 0, 'done' => 0, 'blocked' => 0, 'other' => 0];

        foreach ($tasks as $task) {
            match ((string) ($task['status'] ?? '')) {
                'todo', 'in_progress', 'review' => $taskCounts['open']++,
                'done' => $taskCounts['done']++,
                'blocked' => $taskCounts['blocked']++,
                default => $taskCounts['other']++,
            };
        }

        $citations = [];

        foreach ($artifacts as $artifact) {
            $citations[] = [
                'kind'  => 'artifact',
                'id'    => (int) $artifact['id'],
                'label' => $artifact['module'] . ' ' . $artifact['type'] . ' v' . $artifact['version'],
            ];
        }

        foreach ($sources as $source) {
            $citations[] = [
                'kind'  => 'source',
                'id'    => (int) $source['id'],
                'label' => 'Source: ' . $source['title'],
            ];
        }

        foreach ($activities as $activity) {
            $citations[] = [
                'kind'  => 'activity',
                'id'    => (int) $activity['id'],
                'label' => ucfirst((string) $activity['kind']) . ': ' . $activity['title'],
            ];
        }

        $artifactItems = array_map(static fn (array $a): array => [
            'artifact_id' => (int) $a['id'],
            'module'      => (string) $a['module'],
            'type'        => (string) $a['type'],
            'version'     => (int) $a['version'],
        ], $artifacts);

        $activityItems = array_map(static fn (array $a): array => [
            'activity_id'      => (int) $a['id'],
            'kind'             => (string) $a['kind'],
            'title'            => (string) $a['title'],
            'status'           => (string) $a['status'],
            'starts_at'        => $a['starts_at'],
            'team_count'       => (int) ($a['team_count'] ?? 0),
            'submission_count' => (int) ($a['submission_count'] ?? 0),
        ], $activities);

        $sourceItems = array_map(static fn (array $s): array => [
            'source_id' => (int) $s['id'],
            'title'     => (string) $s['title'],
            'kind'      => (string) $s['kind'],
        ], $sources);

        $gaps = [];

        if ($artifacts === []) {
            $gaps[] = 'No learning or delivery artifacts have been generated yet.';
        }

        if ($activities === []) {
            $gaps[] = 'No hackathons or events are recorded for this project.';
        }

        if ($sources === []) {
            $gaps[] = 'No knowledge sources are attached to this project.';
        }

        $sections = [
            [
                'heading' => 'Overview',
                'body'    => sprintf(
                    '%s is a %s project in %s status. This report was composed from %d task(s), %d artifact(s), %d source(s), and %d activity/activities.',
                    (string) $project['title'],
                    (string) ($project['kind'] ?? 'general'),
                    (string) ($project['status'] ?? 'active'),
                    count($tasks),
                    count($artifacts),
                    count($sources),
                    count($activities),
                ),
                'items'   => [],
            ],
            [
                'heading' => 'Delivery progress',
                'body'    => sprintf(
                    '%d open, %d done, %d blocked, %d other task(s).',
                    $taskCounts['open'],
                    $taskCounts['done'],
                    $taskCounts['blocked'],
                    $taskCounts['other'],
                ),
                'items'   => array_values(array_map(static fn (array $t): array => [
                    'title'  => (string) $t['title'],
                    'status' => (string) $t['status'],
                ], array_slice($tasks, 0, 10))),
            ],
            [
                'heading' => 'Learning and evidence',
                'body'    => sprintf(
                    '%d current versioned artifact(s) produced by the module pipeline.',
                    count($artifacts),
                ),
                'items'   => $artifactItems,
            ],
            [
                'heading' => 'Knowledge base',
                'body'    => sprintf('%d source(s) ground this project.', count($sources)),
                'items'   => $sourceItems,
            ],
            [
                'heading' => 'Community activity',
                'body'    => sprintf('%d activity/activities hosted in this workspace.', count($activities)),
                'items'   => $activityItems,
            ],
            [
                'heading' => 'Gaps and next steps',
                'body'    => $gaps === []
                    ? 'The core evidence trail is populated; proceed to delivery and review.'
                    : implode(' ', $gaps),
                'items'   => [],
            ],
        ];

        $content = [
            'title'        => 'Project report — ' . $project['title'],
            'summary'      => sprintf(
                '%s: %d tasks, %d artifacts, %d sources, %d activities.',
                $project['title'],
                count($tasks),
                count($artifacts),
                count($sources),
                count($activities),
            ),
            'sections'     => $sections,
            'citations'    => $citations,
            'generated_at' => gmdate('c'),
            'generator'    => 'nico-template-v1',
        ];

        return $this->artifacts->create([
            'project_id'     => $projectId,
            'module'         => 'M7',
            'type'           => 'report',
            'schema_version' => 1,
            'content'        => $content,
            'provenance'     => [
                'generated_from' => [
                    'project_id' => $projectId,
                    'inputs'     => [
                        'tasks'      => count($tasks),
                        'artifacts'  => count($artifacts),
                        'sources'    => count($sources),
                        'activities' => count($activities),
                    ],
                    'artifact_ids' => array_column($artifactItems, 'artifact_id'),
                    'source_ids'   => array_column($sourceItems, 'source_id'),
                    'activity_ids' => array_column($activityItems, 'activity_id'),
                ],
            ],
            'status'         => 'ready',
            'created_by'     => $userId,
        ]);
    }
}
