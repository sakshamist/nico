<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Support\Config;

/**
 * HTTP response helpers.
 */
final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Health must never be cached: it reflects live state. */
    public static function noStore(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    public static function redirect(string $location): void
    {
        header('Location: ' . $location, true, 302);
    }

    /** Streams a private file as an authenticated download. */
    public static function download(string $absolutePath, string $mime, string $filename): never
    {
        if (!is_file($absolutePath)) {
            http_response_code(404);
            exit;
        }

        $safeName = str_replace(["\r", "\n", '"', '\\'], '', $filename);
        $safeName = $safeName === '' ? 'document.pdf' : mb_substr($safeName, 0, 255);
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $safeName) ?: 'document.pdf';

        self::noStore();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
        header('Content-Length: ' . (string) filesize($absolutePath));

        readfile($absolutePath);
        exit;
    }
}
