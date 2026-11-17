<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

use InvalidArgumentException;
use finfo;
use Nico\Edge\Support\Config;
use Throwable;

/**
 * Minimal PDF intake for the MVP.
 *
 * The browser extracts text so the edge host needs no binary. When pdftotext is
 * available on the edge, its output wins; otherwise the browser text is used.
 * In both cases the original PDF is stored and linked to the resulting source.
 */
final class PdfIngestionService
{
    private Storage $storage;
    private PdfTextExtractor $extractor;
    private KnowledgeRepository $knowledge;

    public function __construct(
        ?Storage $storage = null,
        ?PdfTextExtractor $extractor = null,
        ?KnowledgeRepository $knowledge = null,
    ) {
        $this->storage = $storage ?? new Storage();
        $this->extractor = $extractor ?? new PdfTextExtractor();
        $this->knowledge = $knowledge ?? new KnowledgeRepository();
    }

    public function maxBytes(): int
    {
        $configured = (int) Config::get('PDF_MAX_BYTES', 20 * 1024 * 1024);

        return max(1, min(
            $configured,
            $this->iniBytes((string) ini_get('upload_max_filesize')),
            $this->iniBytes((string) ini_get('post_max_size')),
        ));
    }

    public function maxTextBytes(): int
    {
        return max(1024, (int) Config::get('PDF_TEXT_MAX_BYTES', 2 * 1024 * 1024));
    }

    /**
     * @param array<string,mixed> $upload
     * @return array{source_id:int,resource_id:int,chunks:int,extractor:string}
     */
    public function ingest(
        array $upload,
        string $browserText,
        int $userId,
        string $role,
        string $title,
        ?int $projectId,
        string $pageSegmentsJson = '[]',
    ): array {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('That PDF is larger than this server allows.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Choose a PDF to upload.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The upload failed. Please try again.');
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        $bytes = (int) ($upload['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('The upload could not be read.');
        }
        if ($bytes <= 0 || $bytes > $this->maxBytes()) {
            throw new InvalidArgumentException('That PDF is larger than this server allows.');
        }

        $handle = @fopen($tmp, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The upload could not be read.');
        }
        $magic = (string) fread($handle, 5);
        fclose($handle);

        if ($magic !== '%PDF-') {
            throw new InvalidArgumentException('That file is not a PDF.');
        }

        $detector = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) ($detector->file($tmp) ?: '');

        $sha256 = (string) hash_file('sha256', $tmp);
        $originalName = self::sanitizeFileName((string) ($upload['name'] ?? 'document.pdf'));
        $relativePath = 'pdf/' . $userId . '/' . bin2hex(random_bytes(16)) . '.pdf';

        $browserSegments = self::parseSegments($pageSegmentsJson, $this->maxTextBytes());
        $text = $this->resolveText($browserText, $tmp, $browserSegments);
        $body = $text['body'];

        if (trim($title) === '') {
            $title = self::titleFromFileName($originalName);
        }

        $this->storage->putUploadedFile($upload, $relativePath);

        try {
            $created = $this->knowledge->createIngestedSource(
                $userId,
                $role,
                $title,
                $body,
                $projectId,
                [
                    'original_name' => $originalName,
                    'path'          => $relativePath,
                    'mime'          => $detectedMime !== '' ? $detectedMime : 'application/pdf',
                    'bytes'         => $bytes,
                    'sha256'        => $sha256,
                ],
                $text['segments'],
            );
        } catch (Throwable $e) {
            $this->storage->delete($relativePath);
            throw $e;
        }

        return $created + ['extractor' => $text['extractor']];
    }

    /**
     * @param list<array{page:int,text:string}>|null $browserSegments
     * @return array{body:string,extractor:string,segments:list<array{page:int,text:string}>}
     */
    public function resolveText(string $browserText, string $absolutePath, ?array $browserSegments = null): array
    {
        $serverSegments = $this->extractor->extractSegments($absolutePath);
        if ($serverSegments !== null && self::segmentsValid($serverSegments, $this->maxTextBytes())) {
            return [
                'body'     => self::joinSegments($serverSegments),
                'extractor' => 'server',
                'segments' => $serverSegments,
            ];
        }

        if ($browserSegments !== null && $browserSegments !== [] && self::segmentsValid($browserSegments, $this->maxTextBytes())) {
            return [
                'body'      => self::joinSegments($browserSegments),
                'extractor' => 'browser',
                'segments'  => $browserSegments,
            ];
        }

        $browserText = PdfTextExtractor::normalize($browserText);
        if (!mb_check_encoding($browserText, 'UTF-8')) {
            throw new InvalidArgumentException('The extracted text is not valid UTF-8.');
        }
        if (strlen($browserText) > $this->maxTextBytes()) {
            throw new InvalidArgumentException('The extracted text is too large.');
        }
        if ($browserText === '') {
            throw new InvalidArgumentException(
                'No selectable text was found. Enable JavaScript to read the PDF in your browser; scanned PDFs need OCR.'
            );
        }

        return [
            'body'      => $browserText,
            'extractor' => 'browser',
            'segments'  => [['page' => 1, 'text' => $browserText]],
        ];
    }

    /** @return list<array{page:int,text:string}>|null */
    public static function parseSegments(string $json, int $maxBytes): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || count($decoded) > 1000) {
            return null;
        }

        $segments = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                return null;
            }
            $page = (int) ($item['page'] ?? 0);
            $text = PdfTextExtractor::normalize((string) ($item['text'] ?? ''));
            if ($page <= 0 || $text === '') {
                continue;
            }
            $segments[] = ['page' => $page, 'text' => $text];
        }

        if ($segments === [] || !self::segmentsValid($segments, $maxBytes)) {
            return null;
        }

        return $segments;
    }

    /** @param list<array{page:int,text:string}> $segments */
    private static function segmentsValid(array $segments, int $maxBytes): bool
    {
        $bytes = 0;
        foreach ($segments as $segment) {
            $text = (string) ($segment['text'] ?? '');
            if ((int) ($segment['page'] ?? 0) <= 0 || $text === '') {
                return false;
            }
            $bytes += strlen($text);
        }

        return $bytes <= $maxBytes;
    }

    /** @param list<array{page:int,text:string}> $segments */
    private static function joinSegments(array $segments): string
    {
        return implode("\n\n", array_column($segments, 'text'));
    }

    public static function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? 'document.pdf';
        $name = trim($name);

        return $name === '' ? 'document.pdf' : mb_substr($name, 0, 255);
    }

    private static function titleFromFileName(string $name): string
    {
        $title = pathinfo($name, PATHINFO_FILENAME);
        $title = trim(str_replace(['_', '-'], ' ', $title));

        return $title === '' ? 'PDF source' : mb_substr($title, 0, 255);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
