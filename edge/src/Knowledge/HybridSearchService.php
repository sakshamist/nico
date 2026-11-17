<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

use Nico\Edge\Ai\AiClientInterface;
use Nico\Edge\Ai\OllamaClient;

final class HybridSearchService
{
    private AiClientInterface $client;
    private VectorStore $vectors;

    public function __construct(?AiClientInterface $client = null, ?VectorStore $vectors = null)
    {
        $this->client = $client ?? OllamaClient::fromConfig();
        $this->vectors = $vectors ?? new VectorStore();
    }

    /** @param list<array<string,mixed>> $results */
    public function rank(array $results, string $query): array
    {
        if ($results === [] || !$this->client->available() || trim($query) === '') {
            return ['mode' => 'lexical', 'results' => $results];
        }

        $queryVector = $this->client->embed($query);
        if ($queryVector === null || $queryVector === []) {
            return ['mode' => 'lexical', 'results' => $results];
        }

        $maxLexical = 0;
        foreach ($results as $row) {
            $maxLexical = max($maxLexical, (int) ($row['score'] ?? 0));
        }

        foreach ($results as &$row) {
            $entry = $this->vectors->get((int) ($row['source_id'] ?? 0), (int) ($row['id'] ?? 0));
            $similarity = null;

            if ($entry !== null && count($entry['vector']) === count($queryVector)) {
                $similarity = self::cosine($queryVector, $entry['vector']);
            }

            $lexical = $maxLexical > 0 ? (int) ($row['score'] ?? 0) / $maxLexical : 0.0;
            $semantic = $similarity === null ? 0.0 : max(0.0, $similarity);
            $row['vector_score'] = $similarity;
            $row['lexical_score'] = (int) ($row['score'] ?? 0);
            $row['score'] = (int) round(100 * (0.55 * $semantic + 0.45 * $lexical));
        }
        unset($row);

        usort($results, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });

        return ['mode' => 'hybrid', 'results' => $results];
    }

    /** @param list<float> $a @param list<float> $b */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i];
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
