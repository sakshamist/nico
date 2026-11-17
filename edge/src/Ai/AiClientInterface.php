<?php

declare(strict_types=1);

namespace Nico\Edge\Ai;

interface AiClientInterface
{
    public function available(): bool;

    /** @return list<float>|null */
    public function embed(string $text): ?array;

    /** @param list<array<string,string>> $messages */
    public function chat(array $messages, array $options = []): ?string;
}
