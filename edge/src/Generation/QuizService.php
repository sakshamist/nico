<?php

declare(strict_types=1);

namespace Nico\Edge\Generation;

use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Projects\MembershipRepository;

/**
 * M3 deterministic quiz generation.
 *
 * Grounded in M2 chunks: each item blanks a keyword in a real source sentence
 * and offers distractors drawn from the same corpus, with the source passage as
 * the explanation/provenance. The schema matches what a worker/LLM generator
 * will emit, so the renderer does not change when generation moves compute-side.
 */
final class QuizService
{
    public function __construct(
        private readonly KnowledgeRepository $knowledge = new KnowledgeRepository(),
        private readonly ArtifactRepository $artifacts = new ArtifactRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
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
            throw new \InvalidArgumentException('That source has no chunks to assess.');
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
        $questions = self::buildQuestions($chunks);

        if ($questions === []) {
            throw new \InvalidArgumentException('The sources are too short to build a quiz.');
        }

        $content = [
            'title'        => 'Quiz: ' . $scopeLabel,
            'instructions' => 'Choose the best answer for each question. Feedback appears at the end.',
            'questions'    => $questions,
            'generated_at' => gmdate('c'),
            'generator'    => 'nico-template-v1',
        ];

        $sourceIds = array_values(array_unique(array_map(
            static fn (array $c): int => (int) $c['source_id'],
            $chunks,
        )));

        return $this->artifacts->create([
            'project_id'     => $projectId,
            'resource_id'    => $resourceId,
            'module'         => 'M3',
            'type'           => 'quiz',
            'schema_version' => 1,
            'content'        => $content,
            'provenance'     => [
                'generated_from' => [
                    'scope'       => $projectId !== null ? 'project' : 'source',
                    'project_id'  => $projectId,
                    'source_ids'  => $sourceIds,
                ],
            ],
            'status'         => 'ready',
            'created_by'     => $userId,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $chunks
     * @return list<array<string,mixed>>
     */
    private static function buildQuestions(array $chunks): array
    {
        $pool = [];

        foreach ($chunks as $chunk) {
            foreach (KnowledgeRepository::keywords((string) $chunk['text'], 8) as $word) {
                $pool[$word] = true;
            }
        }

        $pool = array_keys($pool);
        $fallbacks = ['context', 'process', 'example', 'concept', 'outcome', 'method'];
        $questions = [];
        $used = [];

        foreach ($chunks as $chunk) {
            $sentences = preg_split('/(?<=[.!?])\s+/u', (string) $chunk['text']) ?: [];

            foreach ($sentences as $sentence) {
                if (count($questions) >= 5) {
                    break 2;
                }

                $sentence = trim($sentence);

                if (str_word_count($sentence) < 8) {
                    continue;
                }

                $candidates = KnowledgeRepository::keywords($sentence, 8);
                $answer = null;

                foreach ($candidates as $candidate) {
                    if (!isset($used[$candidate])) {
                        $answer = $candidate;
                        break;
                    }
                }

                if ($answer === null) {
                    continue;
                }

                $blanked = preg_replace(
                    '/\b' . preg_quote($answer, '/') . '\b/i',
                    '_____',
                    $sentence,
                    1,
                );

                if ($blanked === null || $blanked === $sentence) {
                    continue;
                }

                $distractors = [];

                foreach ($pool as $word) {
                    if ($word === $answer || isset($used[$word])) {
                        continue;
                    }

                    $distractors[$word] = true;

                    if (count($distractors) >= 3) {
                        break;
                    }
                }

                foreach ($fallbacks as $word) {
                    if (count($distractors) >= 3) {
                        break;
                    }

                    if ($word !== $answer) {
                        $distractors[$word] = true;
                    }
                }

                $options = array_merge([$answer], array_keys($distractors));
                $options = array_values(array_unique($options));
                shuffle($options);
                $answerIndex = array_search($answer, $options, true);
                $used[$answer] = true;

                $questions[] = [
                    'id'           => 'q' . (count($questions) + 1),
                    'type'         => 'mcq',
                    'prompt'       => 'Fill in the blank: ' . $blanked,
                    'options'      => $options,
                    'answer_index' => $answerIndex === false ? 0 : $answerIndex,
                    'explanation'  => 'From "' . $chunk['source_title'] . '": ' . $sentence,
                    'source_refs'  => [[
                        'kind'         => 'source',
                        'source_id'    => (int) $chunk['source_id'],
                        'chunk_id'     => (int) $chunk['id'],
                        'source_title' => (string) $chunk['source_title'],
                        'ordinal'      => (int) $chunk['ordinal'],
                    ]],
                    'feedback'     => 'Review the cited passage for the full context.',
                ];
            }
        }

        return $questions;
    }
}
