#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Removes users created by acceptance runs so the admin app stays clean.
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;

Config::boot(__DIR__ . '/../.env');

$pdo = Database::pdo();
$pdo->beginTransaction();

try {
    $keeper = (int) $pdo->query('SELECT id FROM users WHERE email = "admin@nico.local" LIMIT 1')->fetchColumn();

    if ($keeper > 0) {
        $pdo->prepare(
            'UPDATE intern_material_assignments
                SET assigned_by = :keeper
              WHERE assigned_by IN (SELECT id FROM users WHERE email LIKE "%@test.local")'
        )->execute(['keeper' => $keeper]);
    }

    $pdo->exec(
        'DELETE FROM artifacts
          WHERE created_by IN (SELECT id FROM users WHERE email LIKE "%@test.local")
             OR resource_id IN (
                    SELECT r.id FROM resources r
                      JOIN users u ON u.id = r.user_id
                     WHERE u.email LIKE "%@test.local"
             )'
    );

    $deleted = $pdo->exec('DELETE FROM users WHERE email LIKE "%@test.local"');
    $pdo->commit();
    fwrite(STDOUT, "cleaned {$deleted} test account(s)\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
