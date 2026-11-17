<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Queue\JobNotFoundException;
use Nico\Edge\Queue\JobQueue;
use Nico\Edge\Queue\LeaseConflictException;
use Nico\Edge\Support\Database;

/**
 * Edge-side worker API (M0 queue contract).
 *
 * All endpoints are HMAC-authenticated (WorkerAuth) and never use browser
 * sessions. The Mac polls these; it is never called by the edge.
 */
final class WorkerRoutes
{
    public static function register(App $app): void
    {
        $app->post('/worker/claim', static function (App $app): void {
            $auth = self::authorize();
            if ($auth === null) {
                return;
            }

            $queue = new JobQueue();
            $job = $queue->claim($auth);

            if ($job === null) {
                http_response_code(204);

                return;
            }

            Response::json($job);
        });

        $app->post('/worker/jobs/{id}/heartbeat', static function (App $app, array $params): void {
            $auth = self::authorize();
            if ($auth === null) {
                return;
            }

            $body = Request::json();
            $token = (string) ($body['lease_token'] ?? '');

            if ($token === '') {
                Response::json(['error' => 'lease_token_required'], 422);

                return;
            }

            $expiry = (new JobQueue())->heartbeat((int) $params['id'], $auth, $token);

            if ($expiry === null) {
                Response::json(['error' => 'lease_conflict'], 409);

                return;
            }

            Response::json(['id' => (int) $params['id'], 'lease_expires_at' => $expiry]);
        });

        $app->get('/worker/jobs/{id}/file', static function (App $app, array $params): void {
            $auth = self::authorize();
            if ($auth === null) {
                return;
            }

            $jobId = (int) $params['id'];
            $token = (string) (Request::query('lease_token') ?? '');

            if ($token === '') {
                Response::json(['error' => 'lease_token_required'], 422);

                return;
            }

            $queue = new JobQueue();

            if (!$queue->holdsLease($jobId, $auth, $token)) {
                Response::json(['error' => 'lease_conflict'], 409);

                return;
            }

            $job = $queue->find($jobId);
            $resourceId = $job['resource_id'] ?? null;

            if ($resourceId === null) {
                Response::json(['error' => 'no_source', 'detail' => 'job has no resource'], 404);

                return;
            }

            $stmt = Database::pdo()->prepare(
                'SELECT id, kind, path, mime, bytes, sha256 FROM files
                  WHERE resource_id = :resource AND kind = \'raw\'
                  ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['resource' => (int) $resourceId]);
            $file = $stmt->fetch();

            if ($file === false) {
                Response::json([
                    'error'       => 'no_source',
                    'detail'      => 'no raw file has been stored yet (M1)',
                    'resource_id' => (int) $resourceId,
                ], 404);

                return;
            }

            Response::json([
                'resource_id' => (int) $resourceId,
                'kind'        => 'file',
                'file'        => [
                    'id'     => (int) $file['id'],
                    'path'   => (string) $file['path'],
                    'mime'   => (string) $file['mime'],
                    'bytes'  => (int) $file['bytes'],
                    'sha256' => (string) $file['sha256'],
                ],
            ]);
        });

        $app->post('/worker/jobs/{id}/result', static function (App $app, array $params): void {
            $auth = self::authorize();
            if ($auth === null) {
                return;
            }

            $body = Request::json();
            $token = (string) ($body['lease_token'] ?? '');
            $outcome = (string) ($body['outcome'] ?? '');

            if ($token === '') {
                Response::json(['error' => 'lease_token_required'], 422);

                return;
            }

            $artifact = isset($body['artifact']) && is_array($body['artifact'])
                ? $body['artifact']
                : null;
            $error = isset($body['error']) ? (string) $body['error'] : null;

            try {
                $job = (new JobQueue())->complete(
                    (int) $params['id'],
                    $auth,
                    $token,
                    $outcome,
                    $artifact,
                    $error,
                );
            } catch (JobNotFoundException) {
                Response::json(['error' => 'job_not_found'], 404);

                return;
            } catch (LeaseConflictException) {
                Response::json(['error' => 'lease_conflict'], 409);

                return;
            } catch (\InvalidArgumentException $e) {
                Response::json(['error' => 'invalid_request', 'message' => $e->getMessage()], 422);

                return;
            }

            Response::json($job);
        });
    }

    /** Returns the authenticated worker id, or writes the error response. */
    private static function authorize(): ?string
    {
        Response::noStore();

        $auth = WorkerAuth::check();

        if ($auth['ok']) {
            return $auth['worker_id'];
        }

        $status = $auth['error'] === 'worker_secret_unconfigured' ? 500 : 401;
        Response::json(['error' => $auth['error']], $status);

        return null;
    }
}
