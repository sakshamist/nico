<?php

declare(strict_types=1);

namespace Nico\Edge\Queue;

use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use PDO;
use Throwable;

/**
 * Durable job queue and lease protocol (M0).
 *
 * The edge enqueues work; a compute node behind NAT claims one job at a time,
 * keeps its lease alive, and posts a result. Claims are atomic so two workers can
 * never receive the same job, and leases expire so a crashed worker's work is
 * reclaimed instead of lost.
 *
 * The fast claim path uses SELECT ... FOR UPDATE SKIP LOCKED when the runtime
 * probe says it exists; otherwise a compare-and-set update loop provides the same
 * guarantee with more contention. Both paths are exercised by verify_m0.php.
 */
final class JobQueue
{
    private PDO $pdo;

    private bool $useSkipLocked;

    public function __construct(?PDO $pdo = null, ?bool $useSkipLocked = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
        $this->useSkipLocked = $useSkipLocked
            ?? (bool) (Database::capabilities()['skip_locked'] ?? false);
    }

    /**
     * @param array{resource_id?:?int,project_id?:?int} $refs
     * @param array{payload?:mixed,available_at?:?string} $options available_at is UTC DATETIME text
     */
    public function enqueue(string $type, array $refs = [], array $options = []): int
    {
        JobTypes::assert($type);

        $resourceId = isset($refs['resource_id']) ? (int) $refs['resource_id'] : null;
        $projectId = isset($refs['project_id']) ? (int) $refs['project_id'] : null;

        if ($resourceId === null && $projectId === null) {
            throw new \InvalidArgumentException('A job needs a resource or a project.');
        }

        $payload = array_key_exists('payload', $options)
            ? json_encode($options['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;

        $now = gmdate('c');

        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs
                (resource_id, project_id, type, status, payload, attempts,
                 available_at, created_at, updated_at)
             VALUES
                (:resource_id, :project_id, :type, \'queued\', :payload, 0,
                 :available_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'resource_id'  => $resourceId,
            'project_id'   => $projectId,
            'type'         => $type,
            'payload'      => $payload,
            'available_at' => $options['available_at'] ?? null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Claims the next due job, or returns null when the queue is empty. */
    public function claim(string $workerId, ?int $leaseSeconds = null): ?array
    {
        $this->reapExpired();

        $lease = $leaseSeconds ?? Config::int('JOB_LEASE_SECONDS', 300);
        $token = self::uuid();
        $now = gmdate('c');

        $candidate = 'SELECT id FROM jobs
            WHERE status = \'queued\'
              AND (available_at IS NULL OR available_at <= UTC_TIMESTAMP())
            ORDER BY (available_at IS NULL) DESC, available_at ASC, created_at ASC
            LIMIT 1';

        if ($this->useSkipLocked) {
            $this->pdo->beginTransaction();

            try {
                $id = $this->pdo->query($candidate . ' FOR UPDATE SKIP LOCKED')
                    ->fetchColumn();

                if ($id === false) {
                    $this->pdo->commit();

                    return null;
                }

                $this->markClaimed((int) $id, $workerId, $token, $lease, $now);
                $job = $this->find((int) $id);
                $this->pdo->commit();

                return $job === null ? null : $this->present($job, $lease);
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        // Fallback: read a candidate, then claim it with a guarded UPDATE. Another
        // worker may win the race, in which case the row count is zero and we retry.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = $this->pdo->query($candidate)->fetchColumn();

            if ($id === false) {
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE jobs
                    SET status = \'claimed\', worker_id = :worker, lease_token = :token,
                        lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lease . ' SECOND),
                        attempts = attempts + 1, updated_at = :now
                  WHERE id = :id AND status = \'queued\''
            );
            $update->execute([
                'worker' => $workerId,
                'token'  => $token,
                'now'    => $now,
                'id'     => (int) $id,
            ]);

            if ($update->rowCount() === 1) {
                $job = $this->find((int) $id);

                return $job === null ? null : $this->present($job, $lease);
            }
        }

        return null;
    }

    /** Extends a lease held by this worker; null when the lease is no longer valid. */
    public function heartbeat(
        int $jobId,
        string $workerId,
        string $leaseToken,
        ?int $leaseSeconds = null,
    ): ?string {
        $lease = $leaseSeconds ?? Config::int('JOB_LEASE_SECONDS', 300);
        $now = gmdate('c');

        $this->pdo->beginTransaction();

        try {
            $job = $this->lockJobForHolder($jobId, $workerId, $leaseToken);

            if ($job === null) {
                $this->pdo->rollBack();

                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE jobs
                    SET lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lease . ' SECOND),
                        updated_at = :now
                  WHERE id = :id'
            );
            $update->execute(['now' => $now, 'id' => $jobId]);

            $expiry = (string) $this->pdo->query(
                "SELECT lease_expires_at FROM jobs WHERE id = {$jobId}"
            )->fetchColumn();

            $this->pdo->commit();

            return $expiry;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Submits a result for a claimed job.
     *
     * @param array<string,mixed>|null $artifact artifact payload for a successful job
     * @return array<string,mixed> the updated job row
     */
    public function complete(
        int $jobId,
        string $workerId,
        string $leaseToken,
        string $outcome,
        ?array $artifact = null,
        ?string $error = null,
    ): array {
        if (!in_array($outcome, ['ok', 'retryable', 'permanent'], true)) {
            throw new \InvalidArgumentException("Unknown outcome: {$outcome}");
        }

        $maxAttempts = Config::int('JOB_MAX_ATTEMPTS', 3);
        $now = gmdate('c');

        $this->pdo->beginTransaction();

        try {
            $job = $this->lockJobForHolder($jobId, $workerId, $leaseToken);

            if ($job === null) {
                $this->pdo->rollBack();

                throw new LeaseConflictException('Lease is no longer valid for this job.');
            }

            $resultArtifactId = null;

            if ($outcome === 'ok') {
                if ($artifact !== null) {
                    $artifact['project_id'] ??= isset($job['project_id']) ? (int) $job['project_id'] : null;
                    $artifact['resource_id'] ??= isset($job['resource_id']) ? (int) $job['resource_id'] : null;

                    $resultArtifactId = (new ArtifactRepository($this->pdo))->create($artifact);
                }

                $this->updateJob(
                    $jobId,
                    "status = 'done', result_artifact_id = :artifact, finished_at = UTC_TIMESTAMP(),
                     last_error = NULL, lease_expires_at = NULL, lease_token = NULL,
                     updated_at = :now",
                    ['artifact' => $resultArtifactId, 'now' => $now],
                );
            } elseif ($outcome === 'permanent' || (int) $job['attempts'] >= $maxAttempts) {
                $finalStatus = $outcome === 'permanent' ? 'failed' : 'dead';

                $this->updateJob(
                    $jobId,
                    "status = :status, last_error = :error, finished_at = UTC_TIMESTAMP(),
                     lease_expires_at = NULL, lease_token = NULL, updated_at = :now",
                    ['status' => $finalStatus, 'error' => $error ?? 'job failed', 'now' => $now],
                );

                if ($finalStatus === 'dead' && $job['project_id'] !== null) {
                    $this->pdo->prepare('UPDATE projects SET needs_attention = 1 WHERE id = :id')
                        ->execute(['id' => (int) $job['project_id']]);
                }
            } else {
                $backoff = min(
                    Config::int('JOB_RETRY_BACKOFF_SECONDS', 30) * max(1, (int) $job['attempts']),
                    900,
                );

                $this->updateJob(
                    $jobId,
                    "status = 'queued', available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$backoff} SECOND),
                     last_error = :error, finished_at = NULL, lease_expires_at = NULL,
                     lease_token = NULL, worker_id = NULL, updated_at = :now",
                    ['error' => $error ?? 'retryable failure', 'now' => $now],
                );
            }

            $updated = $this->find($jobId);
            $this->pdo->commit();

            if ($updated === null) {
                throw new JobNotFoundException("Job {$jobId} disappeared during completion.");
            }

            return $this->present($updated);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Returns expired leases to the queue and dead-letters exhausted ones.
     *
     * @return array{requeued:int,dead:int}
     */
    public function reapExpired(): array
    {
        $maxAttempts = Config::int('JOB_MAX_ATTEMPTS', 3);
        $now = gmdate('c');

        $this->pdo->beginTransaction();

        try {
            $dead = $this->pdo->prepare(
                "UPDATE jobs
                    SET status = 'dead',
                        last_error = COALESCE(last_error, 'lease expired before completion'),
                        finished_at = UTC_TIMESTAMP(), lease_expires_at = NULL,
                        lease_token = NULL, worker_id = NULL, updated_at = :now
                  WHERE status IN ('claimed', 'running')
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at < UTC_TIMESTAMP()
                    AND attempts >= :max"
            );
            $dead->execute(['now' => $now, 'max' => $maxAttempts]);
            $deadCount = $dead->rowCount();

            $requeued = $this->pdo->prepare(
                "UPDATE jobs
                    SET status = 'queued', available_at = UTC_TIMESTAMP(),
                        lease_expires_at = NULL, lease_token = NULL, worker_id = NULL,
                        updated_at = :now
                  WHERE status IN ('claimed', 'running')
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at < UTC_TIMESTAMP()"
            );
            $requeued->execute(['now' => $now]);
            $requeuedCount = $requeued->rowCount();

            $this->pdo->exec(
                'UPDATE projects p JOIN jobs j ON j.project_id = p.id
                    SET p.needs_attention = 1
                  WHERE j.status = \'dead\' AND j.project_id IS NOT NULL'
            );

            $this->pdo->commit();

            return ['requeued' => $requeuedCount, 'dead' => $deadCount];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** True when this worker still owns an unexpired lease on the job. */
    public function holdsLease(int $jobId, string $workerId, string $leaseToken): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM jobs
              WHERE id = :id AND worker_id = :worker AND lease_token = :token
                AND status IN (\'claimed\', \'running\')
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at >= UTC_TIMESTAMP()
              LIMIT 1'
        );
        $stmt->execute(['id' => $jobId, 'worker' => $workerId, 'token' => $leaseToken]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Locks a job and confirms the caller still holds a live lease.
     *
     * @return array<string,mixed>|null
     */
    private function lockJobForHolder(int $jobId, string $workerId, string $leaseToken): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *, (lease_expires_at IS NOT NULL AND lease_expires_at < UTC_TIMESTAMP()) AS lease_expired
               FROM jobs WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch();

        if ($job === false) {
            return null;
        }

        if (!in_array($job['status'], ['claimed', 'running'], true)) {
            return null;
        }

        if ((string) $job['worker_id'] !== $workerId) {
            return null;
        }

        if ((string) $job['lease_token'] !== $leaseToken) {
            return null;
        }

        if ((int) $job['lease_expired'] === 1) {
            return null;
        }

        return $job;
    }

    private function markClaimed(int $id, string $workerId, string $token, int $lease, string $now): void
    {
        $this->pdo->prepare(
            'UPDATE jobs
                SET status = \'claimed\', worker_id = :worker, lease_token = :token,
                    lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lease . ' SECOND),
                    attempts = attempts + 1, updated_at = :now
              WHERE id = :id AND status = \'queued\''
        )->execute([
            'worker' => $workerId,
            'token'  => $token,
            'now'    => $now,
            'id'     => $id,
        ]);
    }

    /** @param array<string,mixed> $params */
    private function updateJob(int $jobId, string $setSql, array $params): void
    {
        $params['id'] = $jobId;
        $this->pdo->prepare("UPDATE jobs SET {$setSql} WHERE id = :id")->execute($params);
    }

    /** @param array<string,mixed> $row */
    private function present(array $row, ?int $leaseSeconds = null): array
    {
        $row['id'] = (int) $row['id'];
        $row['attempts'] = (int) $row['attempts'];
        $row['resource_id'] = $row['resource_id'] === null ? null : (int) $row['resource_id'];
        $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];
        $row['payload'] = is_string($row['payload'] ?? null) && $row['payload'] !== ''
            ? json_decode((string) $row['payload'], true)
            : null;

        if ($leaseSeconds !== null) {
            $row['lease_seconds'] = $leaseSeconds;
        }

        return $row;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
