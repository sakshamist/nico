<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

use Nico\Edge\Ai\AiClientInterface;
use Nico\Edge\Ai\OllamaClient;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Log;
use PDO;
use Throwable;

final class EmbeddingIndexer
{
    private PDO $pdo;
    private AiClientInterface $client;
    private VectorStore $vectors;
    private string $model;

    public function __construct(
        ?PDO $pdo = null,
        ?AiClientInterface $client = null,
        ?VectorStore $vectors = null,
    ) {
        $this->pdo = $pdo ?? Database::pdo();
        $this->client = $client ?? OllamaClient::fromConfig();
        $this->vectors = $vectors ?? new VectorStore();
        $this->model = (string) Config::get('OLLAMA_EMBED_MODEL', 'nomic-embed-text:latest');
    }

    /** @param list<array<string,mixed>> $chunks */
    public function index(int $sourceId, int $resourceId, array $chunks): int
    {
        if (!$this->client->available()) {
            return 0;
        }

        $indexed = 0;
        $now = gmdate('c');

        foreach ($chunks as $chunk) {
            $text = trim((string) ($chunk['text'] ?? ''));
            $chunkId = (int) ($chunk['id'] ?? 0);
            if ($text === '' || $chunkId <= 0) {
                continue;
            }

            try {
                $vector = $this->client->embed($text);
                if ($vector === null || $vector === []) {
                    continue;
                }

                $relative = $this->vectors->put($sourceId, $chunkId, $vector, $this->model);
                $this->pdo->prepare('DELETE FROM knowledge_refs WHERE resource_id = :resource AND chunk_id = :chunk')
                    ->execute(['resource' => $resourceId, 'chunk' => (string) $chunkId]);

                $this->pdo->prepare(
                    'INSERT INTO knowledge_refs
                        (resource_id, chunk_id, vector_ref, text, model, dimensions, created_at)
                     VALUES (:resource, :chunk, :ref, :text, :model, :dimensions, :now)'
                )->execute([
                    'resource'   => $resourceId,
                    'chunk'      => (string) $chunkId,
                    'ref'        => $relative,
                    'text'       => $text,
                    'model'      => $this->model,
                    'dimensions' => count($vector),
                    'now'        => $now,
                ]);

                $indexed++;
            } catch (Throwable $e) {
                Log::error('embedding.index_chunk_failed', [
                    'source_id' => $sourceId,
                    'chunk_id'  => $chunkId,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        return $indexed;
    }
}
