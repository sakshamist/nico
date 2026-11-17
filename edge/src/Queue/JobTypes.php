<?php

declare(strict_types=1);

namespace Nico\Edge\Queue;

/**
 * Application-side mirror of the `jobs.type` ENUM.
 *
 * The database constraint is the authority (FR-10); this list exists so bad job
 * types fail before SQL is attempted and so callers have one place to look.
 * Adding a type requires an additive migration plus an entry here.
 */
final class JobTypes
{
    public const ALL = [
        'ocr',
        'parse',
        'transcribe',
        'embed',
        'generate_quiz',
        'generate_lesson',
        'generate_material',
        'fetch',
        'chunk',
        'generate_report',
    ];

    public static function assert(string $type): void
    {
        if (!in_array($type, self::ALL, true)) {
            throw new \InvalidArgumentException("Unknown job type: {$type}");
        }
    }
}
