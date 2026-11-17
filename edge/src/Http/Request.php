<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

/**
 * Accessors for the raw request.
 *
 * Worker request signatures are computed over the raw body bytes, so handlers
 * cannot rely on a decoded copy: re-encoding JSON can change whitespace and key
 * order. Everything here is static because the front controller owns the request
 * lifecycle and there is no framework container to thread it through.
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function rawBody(): string
    {
        static $body = null;

        if ($body === null) {
            $body = file_get_contents('php://input') ?: '';
        }

        return $body;
    }

    /** @return array<string,mixed> */
    public static function json(): array
    {
        $decoded = json_decode(self::rawBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;

        if ($value === null && strcasecmp($name, 'Content-Type') === 0) {
            $value = $_SERVER['CONTENT_TYPE'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The path and query exactly as the client sent them. This is what the client
     * signs, so a subdirectory mount or an allowlisted IP cannot shift the target.
     */
    public static function pathAndQuery(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $hash = strpos($uri, '#');

        return $hash === false ? $uri : substr($uri, 0, $hash);
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
