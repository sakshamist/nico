<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

/**
 * Optional server-side PDF text extraction.
 *
 * cPanel cannot be relied on to allow shell processes or provide pdftotext, so
 * this is a best-effort verification step. The browser already extracted the
 * text; when this succeeds its result is authoritative because it was produced
 * from the uploaded bytes on the server.
 */
class PdfTextExtractor
{
    private const MAX_OUTPUT_BYTES = 4 * 1024 * 1024;
    private const TIMEOUT_SECONDS = 25;

    /** Returns normalized text, or null when unavailable/failed/empty. */
    public function extract(string $absolutePath): ?string
    {
        $raw = $this->extractRaw($absolutePath);

        return $raw === null ? null : self::normalize($raw);
    }

    /** @return list<array{page:int,text:string}>|null */
    public function extractSegments(string $absolutePath): ?array
    {
        $raw = $this->extractRaw($absolutePath);
        if ($raw === null) {
            return null;
        }

        $segments = [];
        foreach (explode("\f", $raw) as $index => $part) {
            $text = self::normalize($part);
            if ($text !== '') {
                $segments[] = ['page' => $index + 1, 'text' => $text];
            }
        }

        return $segments === [] ? null : $segments;
    }

    private function extractRaw(string $absolutePath): ?string
    {
        if (!function_exists('proc_open') || !is_file($absolutePath)) {
            return null;
        }

        $command = ['pdftotext', '-layout', '-enc', 'UTF-8', $absolutePath, '-'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $tooLarge = false;
        $shouldTerminate = false;

        while (microtime(true) < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 200000) === false) {
                $shouldTerminate = true;
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                    if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
                        $tooLarge = true;
                        $shouldTerminate = true;
                        break 2;
                    }
                }
            }

            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if ($tooLarge || microtime(true) >= $deadline) {
            $shouldTerminate = true;
        }

        if ($shouldTerminate) {
            proc_terminate($process);
        }

        $status = proc_close($process);

        return $status === 0 && !$tooLarge ? $stdout : null;
    }

    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = str_replace("\0", '', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
