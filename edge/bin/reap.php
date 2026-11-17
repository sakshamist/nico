#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Returns expired job leases to the queue and dead-letters exhausted jobs.
 *
 * Run from cron every minute on the edge. Claiming also reaps opportunistically,
 * but a quiet queue would otherwise never collect a crashed worker's work.
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Queue\JobQueue;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Log;

Config::boot(__DIR__ . '/../.env');
Log::init(Config::get('LOG_PATH', __DIR__ . '/../storage/logs/edge.log'));

try {
    Database::pdo();
    $result = (new JobQueue())->reapExpired();
} catch (Throwable $e) {
    fwrite(STDERR, "Reap failed: {$e->getMessage()}\n");
    Log::error('jobs.reap_failed', ['error' => $e->getMessage()]);
    exit(1);
}

printf("requeued %d | dead-lettered %d\n", $result['requeued'], $result['dead']);
