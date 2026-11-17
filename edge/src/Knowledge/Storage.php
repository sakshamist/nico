<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

use Nico\Edge\Support\Config;
use RuntimeException;

/**
 * Local file storage placeholder.
 *
 * Uploads stay outside the webroot. The small interface is deliberately the
 * seam a remote/cPanel storage driver can replace later without changing the
 * ingestion or delivery code.
 */
final class Storage
{
    private string $root;

    public function __construct(?string $root = null)
    {
        if ($root === null) {
            $root = (string) Config::get('STORAGE_PATH', 'storage/files');
        }

        $base = dirname(__DIR__, 2);
        if (!str_starts_with($root, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $root)) {
            $root = $base . DIRECTORY_SEPARATOR . $root;
        }

        $this->root = rtrim($root, DIRECTORY_SEPARATOR . '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function putUploadedFile(array $upload, string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the upload directory.');
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp) || !move_uploaded_file($tmp, $absolute)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }

        chmod($absolute, 0640);
    }

    public function put(string $relativePath, string $contents): void
    {
        $absolute = $this->absolutePath($relativePath);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the storage directory.');
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new RuntimeException('Could not write the storage file.');
        }

        chmod($absolute, 0640);
    }

    public function delete(string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);

        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    public function isFile(string $relativePath): bool
    {
        return is_file($this->absolutePath($relativePath));
    }

    public function read(string $relativePath): ?string
    {
        $absolute = $this->absolutePath($relativePath);

        if (!is_file($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return $contents === false ? null : $contents;
    }

    public function absolutePath(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            throw new RuntimeException('Invalid storage path.');
        }

        if (str_starts_with($relativePath, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $relativePath) === 1) {
            throw new RuntimeException('Invalid storage path.');
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
