<?php

declare(strict_types=1);

namespace Nico\Edge\Generation;

use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Projects\MembershipRepository;

/**
 * M4 structured lesson/notes generation.
 *
 * Produces ordered steps from M2 chunks so M8 can render a click-next lesson.
 * Each step keeps provenance back to the exact source chunk.
 */
final class LessonService
{
    public function __construct(
        private readonly KnowledgeRepository $knowledge = new KnowledgeRepository(),
        private readonly ArtifactRepository $artifacts = new ArtifactRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly ?AiContentService $ai = null,
    ) {
    }

    public function generateForSource(int $sourceId, int $userId, string $role): int
    {
        if (!$this->knowledge->canViewSource($sourceId, $userId, $role)) {
            throw new \InvalidArgumentException('You cannot read that source.');
        }

        $source = $this->knowledge->findSource($sourceId);

        if ($source === null) {
            throw new \InvalidArgumentException('Unknown source.');
        }

        $chunks = $this->knowledge->chunksForSource($sourceId);

        if ($chunks === []) {
            throw new \InvalidArgumentException('That source has no chunks to teach.');
        }

        return $this->generate(
            $chunks,
            (string) $source['title'],
            null,
            (int) $source['resource_id'],
            $userId,
        );
    }

    public function generateForProject(int $projectId, int $userId, string $role): int
    {
        if (!$this->memberships->canView($projectId, $userId, $role)) {
            throw new \InvalidArgumentException('You cannot read that project.');
        }

        $chunks = $this->knowledge->chunksForProject($projectId);

        if ($chunks === []) {
            throw new \InvalidArgumentException('Add a knowledge source to this project first.');
        }

        return $this->generate($chunks, 'project knowledge', $projectId, null, $userId);
    }

    /** @param list<array<string,mixed>> $chunks */
    private function generate(
        array $chunks,
        string $scopeLabel,
        ?int $projectId,
        ?int $resourceId,
        int $userId,
    ): int {
        $ai = $this->ai ?? new AiContentService();
        $aiSteps = $ai->steps($chunks);
        $steps = [];
        $allKeywords = [];
        $sourceIds = [];

        if ($aiSteps !== null) {
            $steps = $aiSteps;
            foreach ($steps as $step) {
                $allKeywords = array_merge($allKeywords, $step['key_points'] ?? []);
                foreach ($step['source_refs'] ?? [] as $ref) {
                    $sourceIds[] = (int) ($ref['source_id'] ?? 0);
                }
            }
            $allKeywords = array_values(array_unique($allKeywords));
        } else {
            foreach (array_slice($chunks, 0, 6) as $chunk) {
                $text = trim((string) $chunk['text']);
                $keywords = KnowledgeRepository::keywords($text, 5);
                $allKeywords = array_unique(array_merge($allKeywords, $keywords));
                $sourceIds[] = (int) $chunk['source_id'];

                $steps[] = [
                    'title'       => 'Step ' . (count($steps) + 1) . ' — ' . $chunk['source_title'],
                    'body'        => self::limit($text, 1200),
                    'key_points'  => $keywords,
                    'source_refs' => [[
                        'kind'         => 'source',
                        'source_id'    => (int) $chunk['source_id'],
                        'chunk_id'     => (int) $chunk['id'],
                        'source_title' => (string) $chunk['source_title'],
                        'ordinal'      => (int) $chunk['ordinal'],
                        'page'         => $chunk['page'] ?? null,
                    ]],
                ];
            }
        }

        $first = trim((string) $chunks[0]['text']);
        $summary = self::limit($first, 260);

        if ($summary === '') {
            $summary = 'A ' . count($steps) . '-step lesson built from the selected sources.';
        }

        $content = [
            'title'        => 'Lesson: ' . $scopeLabel,
            'summary'      => $summary,
            'key_points'   => array_slice(array_values($allKeywords), 0, 8),
            'steps'        => $steps,
            'generated_at' => gmdate('c'),
            'generator'    => $ai->available() ? $ai->label() : 'nico-template-v1',
        ];

        return $this->artifacts->create([
            'project_id'     => $projectId,
            'resource_id'    => $resourceId,
            'module'         => 'M4',
            'type'           => 'lesson',
            'schema_version' => 1,
            'content'        => $content,
            'provenance'     => [
                'generated_from' => [
                    'scope'      => $projectId !== null ? 'project' : 'source',
                    'project_id' => $projectId,
                    'source_ids' => array_values(array_unique($sourceIds)),
                ],
            ],
            'status'         => 'ready',
            'created_by'     => $userId,
        ]);
    }

    private static function limit(string $text, int $chars): string
    {
        if (strlen($text) <= $chars) {
            return $text;
        }

        $cut = substr($text, 0, $chars);
        $space = strrpos($cut, ' ');

        return rtrim($space === false ? $cut : substr($cut, 0, $space)) . '…';
    }
}
