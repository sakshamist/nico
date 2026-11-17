<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Auth\Session;

/**
 * Tiny server-rendered view layer. No templating dependency, no client build.
 */
final class View
{
    public static function render(
        string $template,
        array $data = [],
        ?array $user = null,
        string $title = 'Nico',
    ): void {
        $session = new Session();
        $session->start();

        require_once __DIR__ . '/helpers.php';

        $data['user'] = $user;
        $data['csrf'] = self::csrf($session);
        $data['flashes'] = self::pullFlashes($session);
        $data['title'] = $title;
        $data['path'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        extract($data, EXTR_SKIP);

        $file = __DIR__ . '/../../views/pages/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Missing view: {$template}");
        }

        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        require __DIR__ . '/../../views/layout.php';
    }

    public static function csrf(?Session $session = null): string
    {
        $session ??= new Session();
        $session->start();
        $token = (string) $session->get('csrf_token', '');

        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $session->put('csrf_token', $token);
        }

        return $token;
    }

    public static function verifyCsrf(): bool
    {
        $session = new Session();
        $session->start();
        $expected = (string) $session->get('csrf_token', '');
        $given = (string) ($_POST['_csrf'] ?? '');

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    public static function flash(string $type, string $message): void
    {
        $session = new Session();
        $session->start();
        $flashes = $session->get('flashes', []);

        if (!is_array($flashes)) {
            $flashes = [];
        }

        $flashes[] = ['type' => $type, 'message' => $message];
        $session->put('flashes', $flashes);
    }

    /** @return list<array{type:string,message:string}> */
    private static function pullFlashes(Session $session): array
    {
        $flashes = $session->get('flashes', []);
        $session->forget('flashes');

        return is_array($flashes) ? $flashes : [];
    }

    public static function redirect(string $path, int $status = 302): never
    {
        header('Location: ' . $path, true, $status);
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        self::render('404', [], null, 'Not found');
        exit;
    }

    public static function forbidden(): never
    {
        http_response_code(403);
        self::render('403', [], null, 'Forbidden');
        exit;
    }
}

