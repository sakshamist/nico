<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

use Nico\Edge\Support\Config;

final class VectorStore
{
    private Storage $storage;

    public function __construct(?Storage $storage = null)
    {
        $this->storage = $storage ?? new Storage((string) Config::get('VECTOR_PATH', 'storage/vectors'));
    }

    /** @param list<float> $vector */
    public function put(int $sourceId, int $chunkId, array $vector, string $model): string
    {
        $relative = $sourceId . '/' . $chunkId . '.json';
        $this->storage->put($relative, json_encode([
            'model'      => $model,
            'dimensions' => count($vector),
            'vector'     => $vector,
        ], JSON_THROW_ON_ERROR));

        return $relative;
    }

    /** @return array{model:string,dimensions:int,vector:list<float>}|null */
    public function get(int $sourceId, int $chunkId): ?array
    {
        $raw = $this->storage->read($sourceId . '/' . $chunkId . '.json');
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['vector'] ?? null)) {
            return null;
        }

        $vector = [];
        foreach ($decoded['vector'] as $value) {
            if (!is_numeric($value)) {
                return null;
            }
            $vector[] = (float) $value;
        }

        return [
            'model'      => (string) ($decoded['model'] ?? ''),
            'dimensions' => (int) ($decoded['dimensions'] ?? count($vector)),
            'vector'     => $vector,
        ];
    }
}
