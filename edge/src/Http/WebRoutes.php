<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Activities\ActivityRepository;
use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Assessments\AttemptRepository;
use Nico\Edge\Auth\Session;
use Nico\Edge\Auth\UserRepository;
use Nico\Edge\Generation\LessonService;
use Nico\Edge\Generation\QuizService;
use Nico\Edge\Generation\ReportService;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Knowledge\HybridSearchService;
use Nico\Edge\Knowledge\PdfIngestionService;
use Nico\Edge\Knowledge\Storage;
use Nico\Edge\People\ProfileRepository;
use Nico\Edge\Progress\ProgressRepository;
use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Projects\ProjectRepository;
use Nico\Edge\Tasks\AssignmentService;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Tasks\TaskRepository;
use Throwable;

/**
 * M8 delivery surface: the server-rendered workspace under /app.
 *
 * Business rules stay in the M2–M7 services; these handlers resolve the session,
 * enforce membership, validate CSRF, and hand plain arrays to views.
 */
final class WebRoutes
{
    public static function register(App $app): void
    {
        $app->get('/', static function (): void {
            if (self::currentUser() === null) {
                View::redirect('/login');
            }

            View::redirect('/app');
        });

        // ---------------------------------------------------------------- auth
        $app->get('/login', static function (): void {
            if (self::currentUser() !== null) {
                View::redirect('/app');
            }

            View::render('login', ['error' => null], null, 'Sign in');
        });

        $app->post('/login', static function (): void {
            if (!View::verifyCsrf()) {
                self::csrfFailure();
            }

            $email = (string) ($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $user = (new UserRepository())->verifyCredentials($email, $password);

            if ($user === null) {
                View::render('login', ['error' => 'Invalid email or password.'], null, 'Sign in');
                return;
            }

            $session = new Session(
                (string) Config::get('SESSION_NAME', 'nico_session'),
                Config::int('SESSION_LIFETIME', 7200),
            );
            $session->destroy();
            $session->start();
            $session->regenerate();
            $session->markStarted();
            $session->put('user_id', (int) $user['id']);
            $session->put('role', (string) $user['role']);

            View::redirect('/app');
        });

        $app->get('/register', static function (): void {
            if (self::currentUser() !== null) {
                View::redirect('/app');
            }

            View::render('register', ['error' => null], null, 'Create account');
        });

        $app->post('/register', static function (): void {
            if (!View::verifyCsrf()) {
                self::csrfFailure();
            }

            $email = (string) ($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? 'student');

            if (strlen($password) < 8) {
                View::render('register', ['error' => 'Password must be at least 8 characters.'], null, 'Create account');
                return;
            }

            try {
                $users = new UserRepository();
                $role = in_array($role, UserRepository::ROLES, true) ? $role : 'student';

                if ($role === 'admin' && $users->count() > 0) {
                    View::render('register', ['error' => 'Admin accounts cannot be self-created.'], null, 'Create account');
                    return;
                }

                $id = $users->create($email, $password, $role);
            } catch (\PDOException $e) {
                View::render(
                    'register',
                    ['error' => str_contains($e->getMessage(), 'uq_users_email')
                        ? 'That email is already registered.'
                        : 'Could not create the account.'],
                    null,
                    'Create account',
                );
                return;
            } catch (\InvalidArgumentException $e) {
                View::render('register', ['error' => $e->getMessage()], null, 'Create account');
                return;
            }

            $session = new Session();
            $session->start();
            $session->regenerate();
            $session->markStarted();
            $session->put('user_id', $id);
            $session->put('role', $role);

            View::redirect('/app');
        });

        $app->post('/logout', static function (): void {
            if (!View::verifyCsrf()) {
                self::csrfFailure();
            }

            (new Session())->destroy();
            View::redirect('/login');
        });

        // ------------------------------------------------------------ dashboard
        $app->get('/app', static function (): void {
            $user = self::requireUser();
            $userId = (int) $user['id'];
            $role = (string) $user['role'];

            View::render('dashboard', [
                'projects'  => (new ProjectRepository())->listForUser($userId),
                'artifacts' => (new ArtifactRepository())->listForUser($userId, $role),
                'progress'  => (new ProgressRepository())->listForUser($userId),
            ], $user, 'Dashboard');
        });

        // --------------------------------------------------------------- profile
        $app->get('/app/profile', static function (): void {
            $user = self::requireUser();
            $profiles = new ProfileRepository();

            View::render('profile', [
                'profile'      => $profiles->get((int) $user['id']),
                'capabilities' => $profiles->capabilities((int) $user['id']),
            ], $user, 'Your profile');
        });

        $app->post('/app/profile', self::post(static function (): void {
            $user = self::requireUser();
            (new ProfileRepository())->update(
                (int) $user['id'],
                (int) ($_POST['max_open_tasks'] ?? ProfileRepository::DEFAULT_MAX_OPEN_TASKS),
                (string) ($_POST['availability'] ?? 'available'),
            );

            View::flash('success', 'Profile updated.');
            View::redirect('/app/profile');
        }, '/app/profile'));

        $app->post('/app/profile/capabilities', self::post(static function (): void {
            $user = self::requireUser();
            (new ProfileRepository())->setCapability(
                (int) $user['id'],
                (string) ($_POST['tag'] ?? ''),
                (int) ($_POST['level'] ?? 1),
            );

            View::flash('success', 'Capability saved.');
            View::redirect('/app/profile');
        }, '/app/profile'));

        $app->post('/app/profile/capabilities/delete', self::post(static function (): void {
            $user = self::requireUser();
            (new ProfileRepository())->removeCapability((int) $user['id'], (string) ($_POST['tag'] ?? ''));
            View::flash('success', 'Capability removed.');
            View::redirect('/app/profile');
        }, '/app/profile'));

        // ------------------------------------------------------------- projects
        $app->get('/app/projects', static function (): void {
            $user = self::requireUser();

            View::render('projects', [
                'projects' => (new ProjectRepository())->listForUser((int) $user['id']),
            ], $user, 'Projects');
        });

        $app->post('/app/projects', self::post(static function (): void {
            $user = self::requireUser();
            $repo = new ProjectRepository();
            $id = $repo->create(
                (int) $user['id'],
                (string) ($_POST['title'] ?? ''),
                ($_POST['description'] ?? '') !== '' ? (string) $_POST['description'] : null,
                (string) ($_POST['kind'] ?? 'general'),
            );

            View::flash('success', 'Project created.');
            View::redirect('/app/projects/' . $id);
        }, '/app/projects'));

        $app->post('/app/projects/{id}/status', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $projectId = (int) $params['id'];

            if (!(new MembershipRepository())->canManage($projectId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            (new ProjectRepository())->updateStatus($projectId, (string) ($_POST['status'] ?? ''));
            View::flash('success', 'Project status updated.');
            View::redirect('/app/projects/' . $projectId);
        }, '/app/projects'));

        $app->post('/app/projects/{id}/members', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!$memberships->canManage($projectId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $email = trim((string) ($_POST['email'] ?? ''));
            $role = (string) ($_POST['role'] ?? 'member');
            $target = (new UserRepository())->findByEmail($email);

            if ($target === null) {
                throw new \InvalidArgumentException('No user with that email exists.');
            }

            $memberships->add($projectId, (int) $target['id'], $role);
            View::flash('success', 'Member added.');
            View::redirect('/app/projects/' . $projectId);
        }, '/app'));

        $app->get('/app/projects/{id}', static function (App $app, array $params): void {
            $user = self::requireUser();
            $projectId = (int) $params['id'];

            if (!self::canViewProject($projectId, $user)) {
                View::forbidden();
            }

            $project = (new ProjectRepository())->find($projectId);

            if ($project === null) {
                View::notFound();
            }

            $memberships = new MembershipRepository();

            View::render('project', [
                'project'     => $project,
                'tasks'       => (new TaskRepository())->listByProject($projectId),
                'artifacts'   => (new ArtifactRepository())->listForProject($projectId),
                'sources'     => (new KnowledgeRepository())->listSources(
                    (int) $user['id'],
                    (string) $user['role'],
                    $projectId,
                ),
                'activities'  => (new ActivityRepository())->listByProject($projectId),
                'members'     => $memberships->members($projectId),
                'member_role' => $memberships->roleOf($projectId, (int) $user['id']),
                'can_manage'  => $memberships->canManage(
                    $projectId,
                    (int) $user['id'],
                    (string) $user['role'],
                ),
            ], $user, (string) $project['title']);
        });

        $app->post('/app/projects/{id}/tasks', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!$memberships->canManage($projectId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $requirements = trim((string) ($_POST['requirements'] ?? ''));
            $requirementsArray = null;

            if ($requirements !== '') {
                $requirementsArray = array_map(
                    static fn (string $tag): array => ['tag' => strtolower(trim($tag)), 'level' => 1],
                    array_filter(array_map('trim', explode(',', $requirements))),
                );
            }

            (new TaskRepository())->create(
                $projectId,
                (string) ($_POST['title'] ?? ''),
                ($_POST['description'] ?? '') !== '' ? (string) $_POST['description'] : null,
                $requirementsArray,
                (int) ($_POST['priority'] ?? 3),
                ($_POST['due_at'] ?? '') !== '' ? (string) $_POST['due_at'] : null,
                (int) $user['id'],
                (int) ($_POST['artifact_id'] ?? 0) > 0 ? (int) $_POST['artifact_id'] : null,
                ($_POST['milestone'] ?? '') !== '' ? (string) $_POST['milestone'] : null,
                (int) ($_POST['depends_on_task_id'] ?? 0) > 0 ? (int) $_POST['depends_on_task_id'] : null,
            );

            View::flash('success', 'Task added.');
            View::redirect('/app/projects/' . $projectId);
        }, '/app'));

        $app->post('/app/tasks/{id}/assign', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $taskId = (int) $params['id'];
            $task = (new TaskRepository())->find($taskId);

            if ($task === null) {
                View::notFound();
            }

            $projectId = (int) $task['project_id'];

            if (!(new MembershipRepository())->canManage($projectId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $result = (new AssignmentService())->assignBestFit($taskId, (int) $user['id']);
            View::flash(
                $result['state'] === 'assigned' ? 'success' : 'error',
                $result['state'] === 'assigned' ? 'Task assigned by best fit.' : 'No eligible member; flagged for a human.',
            );
            View::redirect('/app/projects/' . $projectId);
        }, '/app'));

        $app->post('/app/tasks/{id}/assign-manual', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $taskId = (int) $params['id'];
            $task = (new TaskRepository())->find($taskId);

            if ($task === null) {
                View::notFound();
            }

            $projectId = (int) $task['project_id'];

            if (!(new MembershipRepository())->canManage($projectId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            (new AssignmentService())->override(
                $taskId,
                (int) ($_POST['user_id'] ?? 0),
                (int) $user['id'],
                'Web manual assignment',
            );
            View::flash('success', 'Task assigned manually.');
            View::redirect('/app/projects/' . $projectId);
        }, '/app'));

        $app->post('/app/tasks/{id}/status', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $taskId = (int) $params['id'];
            $task = (new TaskRepository())->find($taskId);

            if ($task === null) {
                View::notFound();
            }

            $projectId = (int) $task['project_id'];
            $isAssignee = $task['assignee_id'] !== null && (int) $task['assignee_id'] === (int) $user['id'];
            $canManage = (new MembershipRepository())->canManage($projectId, (int) $user['id'], (string) $user['role']);

            if (!$isAssignee && !$canManage) {
                View::forbidden();
            }

            (new TaskRepository())->updateStatus($taskId, (string) ($_POST['status'] ?? ''));
            View::flash('success', 'Task status updated.');
            View::redirect('/app/projects/' . $projectId);
        }, '/app'));

        $app->post('/app/tasks/{id}/release', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $taskId = (int) $params['id'];
            $task = (new TaskRepository())->find($taskId);

            if ($task === null) {
                View::notFound();
            }

            $projectId = (int) $task['project_id'];
            $isAssignee = $task['assignee_id'] !== null && (int) $task['assignee_id'] === (int) $user['id'];
            $canManage = (new MembershipRepository())->canManage($projectId, (int) $user['id'], (string) $user['role']);

            if (!$isAssignee && !$canManage) {
                View::forbidden();
            }

            (new AssignmentService())->release($taskId, (int) $user['id']);
            View::flash('success', 'Task released.');
            View::redirect('/app/projects/' . $projectId);
        }, '/app'));

        $app->post('/app/projects/{id}/generate', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $projectId = (int) $params['id'];

            if (!self::canViewProject($projectId, $user)) {
                View::forbidden();
            }

            $type = (string) ($_POST['type'] ?? 'quiz');
            $userId = (int) $user['id'];
            $role = (string) $user['role'];

            $artifactId = match ($type) {
                'quiz'   => (new QuizService())->generateForProject($projectId, $userId, $role),
                'lesson' => (new LessonService())->generateForProject($projectId, $userId, $role),
                'report' => (new ReportService())->generateForProject($projectId, $userId, $role),
                default  => throw new \InvalidArgumentException('Unknown generation type.'),
            };

            View::flash('success', ucfirst($type) . ' generated.');
            View::redirect('/app/artifacts/' . $artifactId);
        }, '/app'));

        $app->post('/app/projects/{id}/activities', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $projectId = (int) $params['id'];
            $memberships = new MembershipRepository();

            if (!$memberships->canManage($projectId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $kind = (string) ($_POST['kind'] ?? 'event');

            $activityId = (new ActivityRepository())->create($projectId, (int) $user['id'], [
                'kind'        => $kind,
                'title'       => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'starts_at'   => (string) ($_POST['starts_at'] ?? ''),
                'ends_at'     => (string) ($_POST['ends_at'] ?? ''),
                'timezone'    => (string) ($_POST['timezone'] ?? 'UTC'),
                'status'      => (string) ($_POST['status'] ?? 'scheduled'),
                'location'    => (string) ($_POST['location'] ?? ''),
                'join_url'    => (string) ($_POST['join_url'] ?? ''),
                'rules_url'   => (string) ($_POST['rules_url'] ?? ''),
                'capacity'    => (string) ($_POST['capacity'] ?? ''),
                'visibility'  => (string) ($_POST['visibility'] ?? 'workspace'),
                'meta'        => self::activityMetaFromPost($kind),
            ]);

            View::flash('success', 'Activity created.');
            View::redirect('/app/activities/' . $activityId);
        }, '/app'));

        // -------------------------------------------------------------- library
        $app->get('/app/library', static function (): void {
            $user = self::requireUser();
            $projectId = Request::query('project_id');
            $query = trim((string) Request::query('q', ''));
            $sourceIdsRaw = trim((string) Request::query('source_ids', ''));
            $sourceIds = $sourceIdsRaw === ''
                ? null
                : array_values(array_filter(array_map('intval', explode(',', $sourceIdsRaw))));
            $repo = new KnowledgeRepository();
            $results = [];
            $mode = 'lexical';

            if ($query !== '') {
                try {
                    $results = $repo->search(
                        $query,
                        (int) $user['id'],
                        (string) $user['role'],
                        $projectId === null ? null : (int) $projectId,
                        null,
                        20,
                        $sourceIds,
                    );
                } catch (\InvalidArgumentException $e) {
                    View::flash('error', $e->getMessage());
                    View::redirect('/app/library');
                }

                $ranked = (new HybridSearchService())->rank($results, $query);
                $results = $ranked['results'];
                $mode = $ranked['mode'];
            }

            View::render('library', [
                'sources' => $repo->listSources(
                    (int) $user['id'],
                    (string) $user['role'],
                    $projectId === null ? null : (int) $projectId,
                ),
                'query'   => $query,
                'results' => $results,
                'mode'    => $mode,
            ], $user, 'Knowledge library');
        });

        $app->get('/app/library/new', static function (): void {
            $user = self::requireUser();
            $pdf = new PdfIngestionService();

            View::render('source_new', [
                'projects'      => (new ProjectRepository())->listForUser((int) $user['id']),
                'maxPdfBytes'   => $pdf->maxBytes(),
                'maxTextBytes'  => $pdf->maxTextBytes(),
            ], $user, 'Add knowledge source');
        });

        $app->post('/app/library', self::post(static function (): void {
            $user = self::requireUser();
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $repo = new KnowledgeRepository();
            $created = $repo->createSource(
                (int) $user['id'],
                (string) $user['role'],
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['body'] ?? ''),
                (string) ($_POST['kind'] ?? 'note'),
                $projectId > 0 ? $projectId : null,
            );

            View::flash('success', 'Source stored and chunked.');
            View::redirect('/app/sources/' . $created['source_id']);
        }, '/app/library/new'));

        $app->post('/app/library/pdf', self::post(static function (): void {
            $user = self::requireUser();
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $service = new PdfIngestionService();
            $created = $service->ingest(
                $_FILES['pdf'] ?? [],
                (string) ($_POST['extracted_text'] ?? ''),
                (int) $user['id'],
                (string) $user['role'],
                (string) ($_POST['title'] ?? ''),
                $projectId > 0 ? $projectId : null,
                (string) ($_POST['page_segments'] ?? '[]'),
            );

            View::flash('success', 'PDF ingested and chunked from its extracted text.');
            View::redirect('/app/sources/' . $created['source_id']);
        }, '/app/library/new'));

        $app->get('/app/sources/{id}', static function (App $app, array $params): void {
            $user = self::requireUser();
            $sourceId = (int) $params['id'];
            $repo = new KnowledgeRepository();

            if (!$repo->canViewSource($sourceId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $source = $repo->findSource($sourceId);

            if ($source === null) {
                View::notFound();
            }

            View::render('source', [
                'source'    => $source,
                'chunks'    => $repo->chunks($sourceId),
                'artifacts' => (new ArtifactRepository())->listForResource((int) $source['resource_id']),
                'files'     => $repo->filesForResource((int) $source['resource_id']),
            ], $user, (string) $source['title']);
        });

        $app->get('/app/sources/{id}/download', static function (App $app, array $params): void {
            $user = self::requireUser();
            $sourceId = (int) $params['id'];
            $repo = new KnowledgeRepository();

            if (!$repo->canViewSource($sourceId, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $source = $repo->findSource($sourceId);
            if ($source === null) {
                View::notFound();
            }

            $file = $repo->rawFileForResource((int) $source['resource_id']);
            if ($file === null) {
                View::notFound();
            }

            $storage = new Storage();
            $relativePath = (string) $file['path'];
            if (!$storage->isFile($relativePath)) {
                View::notFound();
            }

            Response::download(
                $storage->absolutePath($relativePath),
                (string) $file['mime'],
                (string) ($file['original_name'] ?: ($source['title'] . '.pdf')),
            );
        });

        $app->post('/app/sources/{id}/generate', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $sourceId = (int) $params['id'];
            $type = (string) ($_POST['type'] ?? 'quiz');
            $userId = (int) $user['id'];
            $role = (string) $user['role'];

            $artifactId = match ($type) {
                'quiz'   => (new QuizService())->generateForSource($sourceId, $userId, $role),
                'lesson' => (new LessonService())->generateForSource($sourceId, $userId, $role),
                default  => throw new \InvalidArgumentException('Unknown generation type.'),
            };

            View::flash('success', ucfirst($type) . ' generated.');
            View::redirect('/app/artifacts/' . $artifactId);
        }, '/app/library'));

        // ------------------------------------------------------------- artifacts
        $app->get('/app/artifacts/{id}', static function (App $app, array $params): void {
            $user = self::requireUser();
            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null) {
                View::notFound();
            }

            if (in_array($artifact['module'], ['M3', 'M4'], true)) {
                View::redirect('/app/learn/' . $artifact['id']);
            }

            View::render('artifact', [
                'artifact' => $artifact,
                'project'  => $artifact['project_id'] === null
                    ? null
                    : (new ProjectRepository())->find((int) $artifact['project_id']),
            ], $user, (string) ($artifact['content']['title'] ?? 'Artifact'));
        });

        // ------------------------------------------------------------ learn/quiz
        $app->get('/app/learn/{id}', static function (App $app, array $params): void {
            $user = self::requireUser();
            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null) {
                View::notFound();
            }

            if ($artifact['module'] === 'M3') {
                $pdo = Database::pdo();
                $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));
                $attempt = $attempts->latestFor((int) $artifact['id'], (int) $user['id']);

                if ($attempt !== null) {
                    View::redirect('/app/attempts/' . $attempt['id']);
                }

                View::render('quiz_intro', ['artifact' => $artifact], $user, 'Quiz');
                return;
            }

            if ($artifact['module'] !== 'M4') {
                View::notFound();
            }

            $progress = new ProgressRepository();
            $state = $progress->get((int) $user['id'], (int) $artifact['id']);
            $steps = $artifact['content']['steps'] ?? [];
            $position = min((int) ($state['position'] ?? 0), max(0, count($steps) - 1));

            View::render('lesson', [
                'artifact' => $artifact,
                'step'     => $steps[$position] ?? null,
                'position' => $position,
                'total'    => count($steps),
                'progress' => $state,
            ], $user, (string) ($artifact['content']['title'] ?? 'Lesson'));
        });

        $app->post('/app/artifacts/{id}/start', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null) {
                View::notFound();
            }

            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));
            $attempt = $attempts->start((int) $artifact['id'], (int) $user['id']);

            View::redirect('/app/attempts/' . $attempt['id']);
        }, '/app'));

        $app->post('/app/learn/{id}/progress', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $artifact = self::artifactForUser((int) $params['id'], $user);

            if ($artifact === null || $artifact['module'] !== 'M4') {
                View::notFound();
            }

            $steps = $artifact['content']['steps'] ?? [];
            $position = max(0, min(count($steps) - 1, (int) ($_POST['position'] ?? 0)));
            $progressRepo = new ProgressRepository();
            $current = $progressRepo->get((int) $user['id'], (int) $artifact['id']);
            $completed = isset($_POST['completed'])
                ? (bool) $_POST['completed']
                : (bool) ($current['completed'] ?? false);
            $progressRepo->save((int) $user['id'], (int) $artifact['id'], $position, $completed);

            View::redirect('/app/learn/' . $artifact['id']);
        }, '/app'));

        $app->get('/app/attempts/{id}', static function (App $app, array $params): void {
            $user = self::requireUser();
            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));
            $attempt = $attempts->find((int) $params['id']);

            if ($attempt === null || (int) $attempt['user_id'] !== (int) $user['id']) {
                View::notFound();
            }

            $artifact = (new ArtifactRepository($pdo))->find((int) $attempt['artifact_id']);

            if ($artifact === null) {
                View::notFound();
            }

            $questions = $artifact['content']['questions'] ?? [];
            $current = null;

            foreach ($questions as $index => $_question) {
                if (($attempt['answers'][$index] ?? null) === null) {
                    $current = $index;
                    break;
                }
            }

            View::render('quiz', [
                'artifact' => $artifact,
                'attempt'  => $attempt,
                'current'  => $current,
                'question' => $current === null ? null : $questions[$current],
            ], $user, (string) ($artifact['content']['title'] ?? 'Quiz'));
        });

        $app->post('/app/attempts/{id}/answer', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $pdo = Database::pdo();
            $attempts = new AttemptRepository($pdo, new ArtifactRepository($pdo));

            $attempts->answer(
                (int) $params['id'],
                (int) $user['id'],
                (int) ($_POST['question_index'] ?? 0),
                (int) ($_POST['option_index'] ?? 0),
            );

            View::redirect('/app/attempts/' . (int) $params['id']);
        }, '/app'));

        // ------------------------------------------------------------ activities
        $app->get('/app/activities/{id}', static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!$repo->canView($activity, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $teams = $repo->teams((int) $activity['id']);

            foreach ($teams as &$team) {
                $team['members'] = $repo->teamMembers((int) $team['id']);
            }
            unset($team);

            $memberships = new MembershipRepository();

            View::render('activity', [
                'activity'        => $activity,
                'teams'           => $teams,
                'my_team'         => $repo->myTeam((int) $activity['id'], (int) $user['id']),
                'rsvp'            => $repo->rsvpCounts((int) $activity['id'], (int) $user['id']),
                'submissions'     => $repo->submissions((int) $activity['id']),
                'can_manage'      => $memberships->canManage(
                    (int) $activity['project_id'],
                    (int) $user['id'],
                    (string) $user['role'],
                ),
                'can_read_project' => $memberships->canView(
                    (int) $activity['project_id'],
                    (int) $user['id'],
                    (string) $user['role'],
                ),
            ], $user, (string) $activity['title']);
        });

        $app->post('/app/activities/{id}/status', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!(new MembershipRepository())->canManage(
                (int) $activity['project_id'],
                (int) $user['id'],
                (string) $user['role'],
            )) {
                View::forbidden();
            }

            $repo->updateStatus((int) $activity['id'], (string) ($_POST['status'] ?? ''));
            View::flash('success', 'Activity status updated.');
            View::redirect('/app/activities/' . $activity['id']);
        }, '/app'));

        $app->post('/app/activities/{id}/edit', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!(new MembershipRepository())->canManage(
                (int) $activity['project_id'],
                (int) $user['id'],
                (string) $user['role'],
            )) {
                View::forbidden();
            }

            $repo->update((int) $activity['id'], [
                'title'       => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'starts_at'   => (string) ($_POST['starts_at'] ?? ''),
                'ends_at'     => (string) ($_POST['ends_at'] ?? ''),
                'timezone'    => (string) ($_POST['timezone'] ?? 'UTC'),
                'status'      => (string) ($_POST['status'] ?? 'scheduled'),
                'location'    => (string) ($_POST['location'] ?? ''),
                'join_url'    => (string) ($_POST['join_url'] ?? ''),
                'rules_url'   => (string) ($_POST['rules_url'] ?? ''),
                'capacity'    => (string) ($_POST['capacity'] ?? ''),
                'visibility'  => (string) ($_POST['visibility'] ?? 'workspace'),
                'meta'        => self::activityMetaFromPost((string) $activity['kind']),
            ]);

            View::flash('success', 'Activity updated.');
            View::redirect('/app/activities/' . $activity['id']);
        }, '/app'));

        $app->post('/app/activities/{id}/rsvp', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!$repo->canView($activity, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $status = $repo->rsvp((int) $activity['id'], (int) $user['id']);
            View::flash('success', $status === 'going' ? 'You are going.' : 'You joined the waitlist.');
            View::redirect('/app/activities/' . $activity['id']);
        }, '/app'));

        $app->post('/app/activities/{id}/teams', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!$repo->canView($activity, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $repo->createTeam((int) $activity['id'], (string) ($_POST['name'] ?? ''), (int) $user['id']);
            View::flash('success', 'Team created.');
            View::redirect('/app/activities/' . $activity['id']);
        }, '/app'));

        $app->post('/app/activities/{id}/join', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!$repo->canView($activity, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $teamId = (int) ($_POST['team_id'] ?? 0);
            $valid = false;

            foreach ($repo->teams((int) $activity['id']) as $team) {
                if ((int) $team['id'] === $teamId) {
                    $valid = true;
                    break;
                }
            }

            if (!$valid) {
                throw new \InvalidArgumentException('That team does not belong to this activity.');
            }

            $repo->joinTeam($teamId, (int) $user['id']);
            View::flash('success', 'Joined the team.');
            View::redirect('/app/activities/' . $activity['id']);
        }, '/app'));

        $app->post('/app/activities/{id}/submissions', self::post(static function (App $app, array $params): void {
            $user = self::requireUser();
            $repo = new ActivityRepository();
            $activity = $repo->find((int) $params['id']);

            if ($activity === null) {
                View::notFound();
            }

            if (!$repo->canView($activity, (int) $user['id'], (string) $user['role'])) {
                View::forbidden();
            }

            $teamId = (int) ($_POST['team_id'] ?? 0);
            $repo->addSubmission(
                (int) $activity['id'],
                (int) $user['id'],
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['content'] ?? ''),
                $teamId > 0 ? $teamId : null,
            );

            View::flash('success', 'Submission recorded.');
            View::redirect('/app/activities/' . $activity['id']);
        }, '/app'));
    }

    /** @return array<string,mixed>|null */
    private static function currentUser(): ?array
    {
        $session = new Session(
            (string) Config::get('SESSION_NAME', 'nico_session'),
            Config::int('SESSION_LIFETIME', 7200),
        );
        $userId = (int) $session->get('user_id', 0);

        if ($userId === 0 || $session->isExpired()) {
            return null;
        }

        $user = (new UserRepository())->findById($userId);

        if ($user === null) {
            $session->destroy();
            return null;
        }

        unset($user['password_hash']);

        return $user;
    }

    /** @return array<string,mixed> */
    private static function requireUser(): array
    {
        $user = self::currentUser();

        if ($user === null) {
            View::redirect('/login');
        }

        return $user;
    }

    /** @param array<string,mixed> $user */
    private static function canViewProject(int $projectId, array $user): bool
    {
        return (new MembershipRepository())->canView(
            $projectId,
            (int) $user['id'],
            (string) $user['role'],
        );
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>|null
     */
    private static function artifactForUser(int $artifactId, array $user): ?array
    {
        $artifact = (new ArtifactRepository())->find($artifactId);

        if ($artifact === null) {
            return null;
        }

        if ($artifact['project_id'] !== null) {
            return self::canViewProject((int) $artifact['project_id'], $user) ? $artifact : null;
        }

        if ($artifact['resource_id'] !== null) {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id FROM knowledge_sources WHERE resource_id = :resource LIMIT 1'
            );
            $stmt->execute(['resource' => (int) $artifact['resource_id']]);
            $sourceId = $stmt->fetchColumn();

            if ($sourceId !== false) {
                return (new KnowledgeRepository($pdo))->canViewSource(
                    (int) $sourceId,
                    (int) $user['id'],
                    (string) $user['role'],
                ) ? $artifact : null;
            }

            return (new UserRepository())->canAccessResource(
                (int) $user['id'],
                (string) $user['role'],
                (int) $artifact['resource_id'],
            ) ? $artifact : null;
        }

        return null;
    }

    /** Kind-specific activity details (M6) shared by the create and edit routes.
     *  @return array<string,mixed> */
    private static function activityMetaFromPost(string $kind): array
    {
        return $kind === 'hackathon'
            ? [
                'team_size_max' => (int) ($_POST['team_size_max'] ?? 4),
                'tracks'        => (string) ($_POST['tracks'] ?? ''),
                'prizes'        => (string) ($_POST['prizes'] ?? ''),
                'agenda'        => (string) ($_POST['agenda'] ?? ''),
                'resources'     => (string) ($_POST['resources'] ?? ''),
            ]
            : [
                'speaker_name' => (string) ($_POST['speaker_name'] ?? ''),
                'speaker_bio'  => (string) ($_POST['speaker_bio'] ?? ''),
                'agenda'       => (string) ($_POST['agenda'] ?? ''),
                'resources'    => (string) ($_POST['resources'] ?? ''),
            ];
    }

    /** @param callable $handler */
    private static function post(callable $handler, string $fallback): callable
    {
        return static function (App $app, array $params = []) use ($handler, $fallback): void {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postLimit = iniBytes((string) ini_get('post_max_size'));
            if ($contentLength > $postLimit && $_POST === [] && $_FILES === []) {
                View::flash('error', 'That upload is larger than this server allows.');
                View::redirect($fallback);
            }

            if (!View::verifyCsrf()) {
                self::csrfFailure();
            }

            try {
                $handler($app, $params);
            } catch (\InvalidArgumentException $e) {
                View::flash('error', $e->getMessage());
                $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
                $path = parse_url($referer, PHP_URL_PATH) ?: '';
                View::redirect(str_starts_with($path, '/app') ? $path : $fallback);
            } catch (Throwable $e) {
                \Nico\Edge\Support\Log::error('web.handler_error', [
                    'type' => $e::class,
                    'msg'  => $e->getMessage(),
                ]);
                View::flash('error', 'Something went wrong. Please try again.');
                View::redirect($fallback);
            }
        };
    }

    private static function csrfFailure(): never
    {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF token missing or invalid. Reload the page and try again.';
        exit;
    }
}
