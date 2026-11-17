<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Assessments\AttemptRepository;
use Nico\Edge\Auth\Session;
use Nico\Edge\Auth\UserRepository;
use Nico\Edge\Generation\LessonService;
use Nico\Edge\Generation\QuizService;
use Nico\Edge\Generation\ReportService;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Knowledge\HybridSearchService;
use Nico\Edge\People\ProfileRepository;
use Nico\Edge\Progress\ProgressRepository;
use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Projects\ProjectRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Log;
use Nico\Edge\Tasks\AssignmentService;
use Nico\Edge\Tasks\TaskRepository;

/**
 * Session-authenticated JSON API for the M0 domain contracts.
 *
 * These endpoints make projects, membership, capability profiles, tasks, and
 * artifacts usable by the modules and by the verification suite. Authorization is
 * always resolved server-side through membership; a client-supplied role is never
 * trusted.
 */
final class ApiRoutes
{
    public static function register(App $app): void
    {
        // ------------------------------------------------------------ projects
        $app->post('/projects', self::guard(static function (App $app): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $body = Request::json();
            $repo = new ProjectRepository();
            $id = $repo->create(
                (int) $user['id'],
                (string) ($body['title'] ?? ''),
                isset($body['description']) ? (string) $body['description'] : null,
                (string) ($body['kind'] ?? 'general'),
            );

            Response::json($repo->find($id), 201);
        }));

        $app->get('/projects', self::guard(static function (App $app): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            Response::json(['projects' => (new ProjectRepository())->listForUser((int) $user['id'])]);
        }));

        $app->get('/projects/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!self::canViewProject($projectId, $user, $memberships)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            Response::json([
                'project'         => (new ProjectRepository())->find($projectId),
                'membership_role' => $memberships->roleOf($projectId, (int) $user['id']),
            ]);
        }));

        $app->post('/projects/{id}/members', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!self::canManageProject($projectId, $user, $memberships)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $body = Request::json();
            $targetId = (int) ($body['user_id'] ?? 0);
            $role = (string) ($body['role'] ?? 'member');

            if ($targetId <= 0 || (new UserRepository())->findById($targetId) === null) {
                Response::json(['error' => 'unknown_user'], 422);
                return;
            }

            $memberships->add($projectId, $targetId, $role);

            Response::json(['members' => $memberships->members($projectId)], 201);
        }));

        $app->get('/projects/{id}/members', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!self::canViewProject($projectId, $user, $memberships)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            Response::json(['members' => $memberships->members($projectId)]);
        }));

        // ------------------------------------------------------------ profiles
        $app->get('/profiles/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $target = (int) $params['id'];

            if (!self::canEditProfile($target, $user)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $profiles = new ProfileRepository();

            Response::json([
                'profile'      => $profiles->get($target),
                'capabilities' => $profiles->capabilities($target),
            ]);
        }));

        $app->put('/profiles/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $target = (int) $params['id'];

            if (!self::canEditProfile($target, $user)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $body = Request::json();
            $profiles = new ProfileRepository();
            $profiles->update(
                $target,
                isset($body['max_open_tasks']) ? (int) $body['max_open_tasks'] : null,
                isset($body['availability']) ? (string) $body['availability'] : null,
            );

            Response::json(['profile' => $profiles->get($target)]);
        }));

        $app->post('/profiles/{id}/capabilities', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $target = (int) $params['id'];

            if (!self::canEditProfile($target, $user)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $body = Request::json();
            $profiles = new ProfileRepository();
            $profiles->setCapability($target, (string) ($body['tag'] ?? ''), (int) ($body['level'] ?? 1));

            Response::json(['capabilities' => $profiles->capabilities($target)], 201);
        }));

        $app->delete('/profiles/{id}/capabilities/{tag}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $target = (int) $params['id'];

            if (!self::canEditProfile($target, $user)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $profiles = new ProfileRepository();
            $profiles->removeCapability($target, rawurldecode((string) $params['tag']));

            Response::json(['capabilities' => $profiles->capabilities($target)]);
        }));

        // ------------------------------------------------------------ tasks
        $app->post('/projects/{id}/tasks', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!self::canManageProject($projectId, $user, $memberships)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $body = Request::json();
            $tasks = new TaskRepository();
            $id = $tasks->create(
                $projectId,
                (string) ($body['title'] ?? ''),
                isset($body['description']) ? (string) $body['description'] : null,
                isset($body['requirements']) && is_array($body['requirements']) ? $body['requirements'] : null,
                (int) ($body['priority'] ?? 3),
                isset($body['due_at']) ? (string) $body['due_at'] : null,
                (int) $user['id'],
                isset($body['artifact_id']) ? (int) $body['artifact_id'] : null,
                isset($body['milestone']) ? (string) $body['milestone'] : null,
                isset($body['depends_on_task_id']) ? (int) $body['depends_on_task_id'] : null,
            );

            Response::json($tasks->find($id), 201);
        }));

        $app->get('/projects/{id}/tasks', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!self::canViewProject($projectId, $user, $memberships)) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $status = Request::query('status');

            Response::json(['tasks' => (new TaskRepository())->listByProject($projectId, $status)]);
        }));

        $app->get('/tasks/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $task = self::taskFor($params, $user, false);

            if ($task === null) {
                return;
            }

            Response::json($task);
        }));

        $app->patch('/tasks/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $task = self::taskFor($params, $user, true);

            if ($task === null) {
                return;
            }

            $body = Request::json();
            $tasks = new TaskRepository();
            $tasks->updateStatus((int) $task['id'], (string) ($body['status'] ?? ''));

            Response::json($tasks->find((int) $task['id']));
        }));

        $app->post('/tasks/{id}/assign', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $task = self::taskFor($params, $user, true);

            if ($task === null) {
                return;
            }

            $body = Request::json();
            $service = new AssignmentService();
            $actor = (int) $user['id'];

            if (isset($body['user_id'])) {
                $result = $service->override(
                    (int) $task['id'],
                    (int) $body['user_id'],
                    $actor,
                    isset($body['reason']) ? (string) $body['reason'] : null,
                );
            } else {
                $result = $service->assignBestFit(
                    (int) $task['id'],
                    $actor,
                    (bool) ($body['force'] ?? false),
                );
            }

            Response::json($result + ['task' => (new TaskRepository())->find((int) $task['id'])]);
        }));

        $app->post('/tasks/{id}/release', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $task = self::taskFor($params, $user, true);

            if ($task === null) {
                return;
            }

            Response::json((new AssignmentService())->release((int) $task['id'], (int) $user['id']));
        }));

        $app->get('/tasks/{id}/assignments', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $task = self::taskFor($params, $user, false);

            if ($task === null) {
                return;
            }

            Response::json([
                'history' => (new TaskRepository())->assignmentHistory((int) $task['id']),
            ]);
        }));

        // ------------------------------------------------------------ artifacts
        $app->post('/projects/{id}/artifacts', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];

            if (!self::canViewProject($projectId, $user, new MembershipRepository())) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $body = Request::json();
            $resourceId = isset($body['resource_id']) ? (int) $body['resource_id'] : null;

            if ($resourceId !== null) {
                $users = new UserRepository();
                if (!$users->canAccessResource((int) $user['id'], (string) $user['role'], $resourceId)) {
                    Response::json(['error' => 'forbidden_resource'], 403);
                    return;
                }
            }

            $artifacts = new ArtifactRepository();
            $id = $artifacts->create([
                'project_id'     => $projectId,
                'resource_id'    => $resourceId,
                'module'         => (string) ($body['module'] ?? ''),
                'type'           => (string) ($body['type'] ?? ''),
                'schema_version' => (int) ($body['schema_version'] ?? 1),
                'content'        => $body['content'] ?? null,
                'provenance'     => $body['provenance'] ?? null,
                'status'         => (string) ($body['status'] ?? 'ready'),
                'created_by'     => (int) $user['id'],
            ]);

            Response::json($artifacts->find($id), 201);
        }));

        $app->get('/projects/{id}/artifacts', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];

            if (!self::canViewProject($projectId, $user, new MembershipRepository())) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            Response::json([
                'artifacts' => (new ArtifactRepository())->listForProject(
                    $projectId,
                    Request::query('module'),
                ),
            ]);
        }));

        $app->get('/artifacts/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $artifact = (new ArtifactRepository())->find((int) $params['id']);

            if ($artifact === null) {
                Response::json(['error' => 'not_found'], 404);
                return;
            }

            $allowed = false;

            if ($artifact['project_id'] !== null) {
                $allowed = self::canViewProject(
                    (int) $artifact['project_id'],
                    $user,
                    new MembershipRepository(),
                );
            } elseif ($artifact['resource_id'] !== null) {
                $allowed = (new UserRepository())->canAccessResource(
                    (int) $user['id'],
                    (string) $user['role'],
                    (int) $artifact['resource_id'],
                );
            }

            if (!$allowed) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            Response::json($artifact);
        }));

        // ------------------------------------------------------------ knowledge (M2)
        $app->post('/knowledge/sources', self::guard(static function (App $app): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $body = Request::json();
            $repo = new KnowledgeRepository();
            $projectId = isset($body['project_id']) && (int) $body['project_id'] > 0
                ? (int) $body['project_id']
                : null;

            $created = $repo->createSource(
                (int) $user['id'],
                (string) $user['role'],
                (string) ($body['title'] ?? ''),
                (string) ($body['body'] ?? ''),
                (string) ($body['kind'] ?? 'note'),
                $projectId,
            );

            Response::json([
                'source' => $repo->findSource($created['source_id']),
                'chunks' => $repo->chunks($created['source_id']),
            ], 201);
        }));

        $app->get('/knowledge/sources', self::guard(static function (App $app): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = Request::query('project_id');
            $repo = new KnowledgeRepository();

            Response::json([
                'sources' => $repo->listSources(
                    (int) $user['id'],
                    (string) $user['role'],
                    $projectId === null ? null : (int) $projectId,
                ),
            ]);
        }));

        $app->get('/knowledge/sources/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $repo = new KnowledgeRepository();
            $sourceId = (int) $params['id'];

            if (!$repo->canViewSource($sourceId, (int) $user['id'], (string) $user['role'])) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $source = $repo->findSource($sourceId);

            if ($source === null) {
                Response::json(['error' => 'not_found'], 404);
                return;
            }

            Response::json(['source' => $source, 'chunks' => $repo->chunks($sourceId)]);
        }));

        $app->post('/knowledge/search', self::guard(static function (App $app): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $body = Request::json();
            $repo = new KnowledgeRepository();
            $query = (string) ($body['query'] ?? '');

            $sourceIds = null;
            if (array_key_exists('source_ids', $body)) {
                if (!is_array($body['source_ids'])) {
                    throw new \InvalidArgumentException('source_ids must be an array.');
                }
                $sourceIds = array_values(array_map('intval', $body['source_ids']));
            }

            $results = $repo->search(
                $query,
                (int) $user['id'],
                (string) $user['role'],
                isset($body['project_id']) && (int) $body['project_id'] > 0
                    ? (int) $body['project_id']
                    : null,
                isset($body['source_id']) && (int) $body['source_id'] > 0
                    ? (int) $body['source_id']
                    : null,
                (int) ($body['limit'] ?? 5),
                $sourceIds,
            );

            $ranked = (new HybridSearchService())->rank($results, $query);

            Response::json([
                'query'        => $query,
                'query_terms'  => KnowledgeRepository::queryTerms($query),
                'mode'         => $ranked['mode'],
                'results'      => $ranked['results'],
            ]);
        }));

        // ------------------------------------------------------------ generation (M3/M4/M7)
        $app->post('/knowledge/sources/{id}/generate', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $sourceId = (int) $params['id'];
            $type = (string) (Request::json()['type'] ?? 'quiz');
            $userId = (int) $user['id'];
            $role = (string) $user['role'];

            $artifactId = match ($type) {
                'quiz'   => (new QuizService())->generateForSource($sourceId, $userId, $role),
                'lesson' => (new LessonService())->generateForSource($sourceId, $userId, $role),
                default  => throw new \InvalidArgumentException('Unknown generation type.'),
            };

            Response::json((new ArtifactRepository())->find($artifactId), 201);
        }));

        $app->post('/projects/{id}/generate', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $projectId = (int) $params['id'];

            if (!self::canViewProject($projectId, $user, new MembershipRepository())) {
                Response::json(['error' => 'forbidden'], 403);
                return;
            }

            $body = Request::json();
            $type = (string) ($body['type'] ?? 'quiz');
            $userId = (int) $user['id'];
            $role = (string) $user['role'];

            $artifactId = match ($type) {
                'quiz'   => (new QuizService())->generateForProject($projectId, $userId, $role),
                'lesson' => (new LessonService())->generateForProject($projectId, $userId, $role),
                'report' => (new ReportService())->generateForProject($projectId, $userId, $role),
                default  => throw new \InvalidArgumentException('Unknown generation type.'),
            };

            Response::json((new ArtifactRepository())->find($artifactId), 201);
        }));

        // ------------------------------------------------------------ attempts/progress (M3/M8)
        $app->post('/artifacts/{id}/attempts', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null) {
                return;
            }

            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));
            $attempt = $attempts->start((int) $artifact['id'], (int) $user['id']);

            Response::json(['attempt' => $attempt, 'artifact' => $artifact], 201);
        }));

        $app->get('/artifacts/{id}/attempts/latest', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null) {
                return;
            }

            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));
            $attempt = $attempts->latestFor((int) $artifact['id'], (int) $user['id']);

            if ($attempt === null) {
                Response::json(['error' => 'not_found'], 404);
                return;
            }

            Response::json(['attempt' => $attempt, 'artifact' => $artifact]);
        }));

        $app->get('/attempts/{id}', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));
            $attempt = $attempts->find((int) $params['id']);

            if ($attempt === null || (int) $attempt['user_id'] !== (int) $user['id']) {
                Response::json(['error' => 'not_found'], 404);
                return;
            }

            $artifact = (new ArtifactRepository($pdo))->find((int) $attempt['artifact_id']);

            Response::json(['attempt' => $attempt, 'artifact' => $artifact]);
        }));

        $app->post('/attempts/{id}/answers', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $body = Request::json();
            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));

            Response::json($attempts->answer(
                (int) $params['id'],
                (int) $user['id'],
                (int) ($body['question_index'] ?? 0),
                (int) ($body['option_index'] ?? 0),
            ));
        }));

        $app->post('/artifacts/{id}/progress', self::guard(static function (App $app, array $params): void {
            $user = self::user();
            if ($user === null) {
                return;
            }

            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null) {
                return;
            }

            $body = Request::json();
            $progress = new ProgressRepository();
            $position = max(0, (int) ($body['position'] ?? 0));
            $completed = (bool) ($body['completed'] ?? false);
            $progress->save((int) $user['id'], (int) $artifact['id'], $position, $completed);

            Response::json([
                'progress' => $progress->get((int) $user['id'], (int) $artifact['id']),
            ]);
        }));
    }

    /** @param callable $handler */
    private static function guard(callable $handler): callable
    {
        return static function (App $app, array $params = []) use ($handler): void {
            Response::noStore();

            try {
                $handler($app, $params);
            } catch (\InvalidArgumentException $e) {
                Response::json(['error' => 'invalid_request', 'message' => $e->getMessage()], 422);
            } catch (\JsonException $e) {
                Response::json(['error' => 'invalid_json', 'message' => $e->getMessage()], 400);
            } catch (\PDOException $e) {
                Log::error('api.database_error', [
                    'type' => $e::class,
                    'msg'  => $e->getMessage(),
                ]);
                Response::json(['error' => 'database_error'], 422);
            }
        };
    }

    /** @return array<string,mixed>|null */
    private static function user(): ?array
    {
        $session = new Session(
            (string) Config::get('SESSION_NAME', 'nico_session'),
            Config::int('SESSION_LIFETIME', 7200),
        );

        $userId = (int) $session->get('user_id', 0);

        if ($userId === 0 || $session->isExpired()) {
            Response::json(['error' => 'unauthenticated'], 401);
            return null;
        }

        $user = (new UserRepository())->findById($userId);

        if ($user === null) {
            $session->destroy();
            Response::json(['error' => 'unauthenticated'], 401);
            return null;
        }

        unset($user['password_hash']);

        return $user;
    }

    /** @param array<string,mixed> $user */
    private static function canViewProject(int $projectId, array $user, MembershipRepository $memberships): bool
    {
        return $memberships->canView($projectId, (int) $user['id'], (string) $user['role']);
    }

    /** @param array<string,mixed> $user */
    private static function canManageProject(int $projectId, array $user, MembershipRepository $memberships): bool
    {
        return $memberships->canManage($projectId, (int) $user['id'], (string) $user['role']);
    }

    /** @param array<string,mixed> $user */
    private static function canEditProfile(int $targetId, array $user): bool
    {
        return (int) $user['id'] === $targetId || (string) $user['role'] === 'admin';
    }

    /**
     * Loads a task and enforces project visibility. When $manage is true the
     * caller must be able to manage the project or already be the assignee.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $user
     * @return array<string,mixed>|null
     */
    private static function taskFor(array $params, array $user, bool $manage): ?array
    {
        $tasks = new TaskRepository();
        $task = $tasks->find((int) ($params['id'] ?? 0));

        if ($task === null) {
            Response::json(['error' => 'not_found'], 404);
            return null;
        }

        $memberships = new MembershipRepository();
        $projectId = (int) $task['project_id'];

        if (!self::canViewProject($projectId, $user, $memberships)) {
            Response::json(['error' => 'forbidden'], 403);
            return null;
        }

        if ($manage) {
            $isAssignee = $task['assignee_id'] !== null
                && (int) $task['assignee_id'] === (int) $user['id'];

            if (!self::canManageProject($projectId, $user, $memberships) && !$isAssignee) {
                Response::json(['error' => 'forbidden'], 403);
                return null;
            }
        }

        return $task;
    }

    /**
     * Loads an artifact and enforces project/resource visibility.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>|null
     */
    private static function artifactForUser(int $artifactId, array $user): ?array
    {
        $artifact = (new ArtifactRepository())->find($artifactId);

        if ($artifact === null) {
            Response::json(['error' => 'not_found'], 404);
            return null;
        }

        $allowed = false;

        if ($artifact['project_id'] !== null) {
            $allowed = self::canViewProject(
                (int) $artifact['project_id'],
                $user,
                new MembershipRepository(),
            );
        } elseif ($artifact['resource_id'] !== null) {
            $allowed = (new UserRepository())->canAccessResource(
                (int) $user['id'],
                (string) $user['role'],
                (int) $artifact['resource_id'],
            );
        }

        if (!$allowed) {
            Response::json(['error' => 'forbidden'], 403);
            return null;
        }

        return $artifact;
    }
}
