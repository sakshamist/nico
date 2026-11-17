<?php

declare(strict_types=1);

namespace Nico\Edge\Generation;

use Nico\Edge\Ai\AiClientInterface;
use Nico\Edge\Ai\OllamaClient;
use Nico\Edge\Support\Config;

final class AiContentService
{
    private ?AiClientInterface $ai;
    private string $model;

    public function __construct(?AiClientInterface $ai = null)
    {
        $this->ai = $ai ?? (Config::bool('OLLAMA_ENABLED', false) ? OllamaClient::fromConfig() : null);
        $this->model = (string) Config::get('OLLAMA_CHAT_MODEL', 'qwen141:latest');
    }

    public function available(): bool
    {
        return $this->ai !== null && $this->ai->available();
    }

    public function label(): string
    {
        return 'ollama:' . $this->model;
    }

    /** @param list<array<string,mixed>> $chunks */
    public function questions(array $chunks): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $prompt = "Create 3 to 5 multiple-choice questions. Return JSON only in this shape: "
            . '{"questions":[{"prompt":"...","options":["a","b","c"],"answer_index":0,'
            . '"explanation":"...","source_refs":[{"chunk_id":123}]}]}. '
            . 'Use only the supplied chunks. Each source_refs entry must use a chunk_id from the list.';

        $decoded = $this->request($prompt, $chunks);
        $questions = $decoded['questions'] ?? null;
        if (!is_array($questions) || count($questions) < 1 || count($questions) > 5) {
            return null;
        }

        $result = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                return null;
            }

            $promptText = trim((string) ($question['prompt'] ?? ''));
            $options = $question['options'] ?? null;
            $answerIndex = $question['answer_index'] ?? null;
            $explanation = trim((string) ($question['explanation'] ?? ''));
            $refs = self::normalizeRefs($question['source_refs'] ?? null, $chunks);

            if ($promptText === '' || !is_array($options) || count($options) < 2 || count($options) > 5
                || !is_int($answerIndex) || $answerIndex < 0 || $answerIndex >= count($options)
                || $explanation === '' || $refs === []) {
                return null;
            }

            $cleanOptions = [];
            foreach ($options as $option) {
                if (!is_string($option) || trim($option) === '') {
                    return null;
                }
                $cleanOptions[] = trim($option);
            }

            $result[] = [
                'id'           => 'q' . (count($result) + 1),
                'type'         => 'mcq',
                'prompt'       => $promptText,
                'options'      => $cleanOptions,
                'answer_index' => $answerIndex,
                'explanation'  => $explanation,
                'source_refs'  => $refs,
                'feedback'     => 'Review the cited passage for the full context.',
            ];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $chunks */
    public function steps(array $chunks): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $prompt = "Create 3 to 6 ordered lesson steps. Return JSON only in this shape: "
            . '{"steps":[{"title":"...","body":"...","key_points":["..."],"source_refs":[{"chunk_id":123}]}]}. '
            . 'Use only the supplied chunks. Each source_refs entry must use a chunk_id from the list.';

        $decoded = $this->request($prompt, $chunks);
        $steps = $decoded['steps'] ?? null;
        if (!is_array($steps) || count($steps) < 1 || count($steps) > 6) {
            return null;
        }

        $result = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                return null;
            }

            $title = trim((string) ($step['title'] ?? ''));
            $body = trim((string) ($step['body'] ?? ''));
            $points = $step['key_points'] ?? null;
            $refs = self::normalizeRefs($step['source_refs'] ?? null, $chunks);

            if ($title === '' || $body === '' || !is_array($points) || $refs === []) {
                return null;
            }

            $cleanPoints = [];
            foreach ($points as $point) {
                if (!is_string($point) || trim($point) === '') {
                    return null;
                }
                $cleanPoints[] = trim($point);
            }

            $result[] = [
                'title'       => $title,
                'body'        => $body,
                'key_points'  => $cleanPoints,
                'source_refs' => $refs,
            ];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $chunks */
    private function request(string $instruction, array $chunks): ?array
    {
        $lines = [];
        foreach (array_slice($chunks, 0, 8) as $chunk) {
            $lines[] = sprintf(
                'chunk_id=%d source=%s page=%s text=%s',
                (int) ($chunk['id'] ?? 0),
                (string) ($chunk['source_title'] ?? ''),
                $chunk['page'] === null ? 'unknown' : (string) $chunk['page'],
                mb_substr(trim((string) ($chunk['text'] ?? '')), 0, 1200),
            );
        }

        $raw = $this->ai->chat([
            ['role' => 'system', 'content' => 'You generate grounded learning content. Output strict JSON only, with no markdown.'],
            ['role' => 'user', 'content' => $instruction . "\n\nChunks:\n" . implode("\n\n", $lines)],
        ]);

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $refs
     * @param list<array<string,mixed>> $chunks
     * @return list<array<string,mixed>>
     */
    private static function normalizeRefs($refs, array $chunks): array
    {
        if (!is_array($refs) || $refs === []) {
            return [];
        }

        $byId = [];
        foreach ($chunks as $chunk) {
            $byId[(int) $chunk['id']] = $chunk;
        }

        $result = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                return [];
            }
            $id = (int) ($ref['chunk_id'] ?? 0);
            $chunk = $byId[$id] ?? null;
            if ($chunk === null) {
                return [];
            }

            $result[] = [
                'kind'         => 'source',
                'source_id'    => (int) $chunk['source_id'],
                'chunk_id'     => $id,
                'source_title' => (string) $chunk['source_title'],
                'ordinal'      => (int) $chunk['ordinal'],
                'page'         => $chunk['page'] === null ? null : (int) $chunk['page'],
            ];
        }

        return $result;
    }
}
