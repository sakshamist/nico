<?php

declare(strict_types=1);

/**
 * Migration CLI.
 *
 * Usage:
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php status     show applied/pending
 *   php bin/migrate.php reset      drop everything (dev/test only)
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Log;
use Nico\Edge\Support\Migrator;

Config::boot(__DIR__ . '/../.env');
Log::init(Config::get('LOG_PATH', __DIR__ . '/../storage/logs/edge.log'));

$command = $argv[1] ?? 'migrate';

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reach the database: {$e->getMessage()}\n");
    exit(1);
}

$caps = Database::capabilities();
printf("Engine: %s %s | skip_locked: %s | json: %s\n",
    $caps['engine'],
    $caps['version'],
    $caps['skip_locked'] ? 'yes' : 'NO',
    $caps['json_type']
);

$migrator = new Migrator($pdo, __DIR__ . '/../migrations');

switch ($command) {
    case 'migrate':
        $ran = $migrator->migrate();
        if ($ran === []) {
            echo "Nothing to do; schema is up to date.\n";
        } else {
            foreach ($ran as $name) {
                echo "applied {$name}\n";
            }
        }
        break;

    case 'status':
        $applied = $pdo->query('SELECT name, applied_at FROM _migrations ORDER BY id')
            ->fetchAll();
        $all = array_map('basename', glob(__DIR__ . '/../migrations/*.sql') ?: []);
        sort($all, SORT_STRING);
        $done = array_column($applied, 'name');

        foreach ($all as $name) {
            $mark = in_array($name, $done, true) ? 'applied' : 'pending';
            printf("%-40s %s\n", $name, $mark);
        }
        break;

    case 'reset':
        $migrator->reset();
        echo "Schema dropped.\n";
        break;

    default:
        fwrite(STDERR, "Unknown command: {$command}\n");
        exit(1);
}
