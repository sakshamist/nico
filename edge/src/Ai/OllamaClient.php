<?php

declare(strict_types=1);

namespace Nico\Edge\Ai;

use Nico\Edge\Support\Config;
use Nico\Edge\Support\Log;

final class OllamaClient implements AiClientInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $embedModel;
    private string $chatModel;
    private int $timeout;
    private bool $enabled;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $embedModel,
        string $chatModel,
        int $timeout = 8,
        bool $enabled = true,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->embedModel = $embedModel;
        $this->chatModel = $chatModel;
        $this->timeout = max(1, $timeout);
        $this->enabled = $enabled;
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) Config::get('OLLAMA_BASE_URL', ''),
            (string) Config::get('OLLAMA_API_KEY', ''),
            (string) Config::get('OLLAMA_EMBED_MODEL', 'nomic-embed-text:latest'),
            (string) Config::get('OLLAMA_CHAT_MODEL', 'qwen141:latest'),
            (int) Config::get('OLLAMA_TIMEOUT_SECONDS', 8),
            Config::bool('OLLAMA_ENABLED', false),
        );
    }

    public function available(): bool
    {
        return $this->enabled && $this->baseUrl !== '';
    }

    public function embed(string $text): ?array
    {
        if (!$this->available() || trim($text) === '') {
            return null;
        }

        $response = $this->request('/embeddings', [
            'model' => $this->embedModel,
            'input' => $text,
        ]);

        $vector = $response['data'][0]['embedding'] ?? null;
        if (!is_array($vector) || $vector === []) {
            return null;
        }

        $values = [];
        foreach ($vector as $value) {
            if (!is_numeric($value)) {
                return null;
            }
            $values[] = (float) $value;
        }

        return $values;
    }

    public function chat(array $messages, array $options = []): ?string
    {
        if (!$this->available()) {
            return null;
        }

        $payload = [
            'model'           => $this->chatModel,
            'messages'        => $messages,
            'temperature'     => (float) ($options['temperature'] ?? 0.2),
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->request('/chat/completions', $payload);
        $content = $response['choices'][0]['message']['content'] ?? null;

        return is_string($content) && $content !== '' ? $content : null;
    }

    private function request(string $path, array $payload): ?array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            Log::error('ollama.request_failed', [
                'path'   => $path,
                'status' => $status,
                'error'  => $error,
            ]);

            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
