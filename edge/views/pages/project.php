<?php
/** @var array<string,mixed> $project */
/** @var list<array<string,mixed>> $tasks */
/** @var list<array<string,mixed>> $artifacts */
/** @var list<array<string,mixed>> $sources */
/** @var list<array<string,mixed>> $activities */
/** @var list<array<string,mixed>> $members */
/** @var string|null $member_role */
/** @var bool $can_manage */
/** @var array<string,mixed> $user */
$artifactTitles = [];
foreach ($artifacts as $artifact) {
    $artifactTitles[(int) $artifact['id']] = (string) ($artifact['content']['title'] ?? $artifact['type']);
}
$taskTitles = [];
foreach ($tasks as $task) {
    $taskTitles[(int) $task['id']] = (string) $task['title'];
}
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Project workspace</p>
        <h1><?= e($project['title']) ?></h1>
        <p class="muted"><?= e($project['description'] ?: 'No description yet.') ?></p>
        <div class="row">
            <span class="pill"><?= e($project['kind']) ?></span>
            <span class="pill"><?= e($project['status']) ?></span>
            <?php if ($member_role !== null): ?><span class="pill"><?= e($member_role) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="head-actions">
        <a class="button" href="/app/library/new?project_id=<?= (int) $project['id'] ?>">Add source</a>
        <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/generate" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="type" value="lesson">
            <button class="button" type="submit">Generate lesson</button>
        </form>
        <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/generate" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="type" value="quiz">
            <button class="button" type="submit">Generate quiz</button>
        </form>
        <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/generate" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="type" value="report">
            <button class="button primary" type="submit">Generate report</button>
        </form>
        <?php if ($can_manage): ?>
            <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/status" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <select name="status">
                    <?php foreach (\Nico\Edge\Projects\ProjectRepository::STATUSES as $projectStatus): ?>
                        <option value="<?= e($projectStatus) ?>" <?= $project['status'] === $projectStatus ? 'selected' : '' ?>><?= e(ucfirst($projectStatus)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">Set status</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<div class="grid two">
    <section class="panel">
        <div class="panel-head">
            <h2>Tasks</h2>
            <span class="muted"><?= count($tasks) ?> total</span>
        </div>
        <?php if ($tasks === []): ?>
            <p class="empty">No tasks yet.</p>
        <?php else: ?>
            <ul class="list compact">
                <?php foreach ($tasks as $task): ?>
                    <?php
                    $assignee = null;
                    foreach ($members as $member) {
                        if ((int) $member['user_id'] === (int) ($task['assignee_id'] ?? 0)) {
                            $assignee = $member;
                            break;
                        }
                    }
                    ?>
                    <li>
                        <span>
                            <?= e($task['title']) ?>
                            <?php if ($assignee !== null): ?>
                                <span class="muted">· <?= e($assignee['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($task['artifact_id']) && isset($artifactTitles[(int) $task['artifact_id']])): ?>
                                <a class="muted" href="/app/artifacts/<?= (int) $task['artifact_id'] ?>">· <?= e($artifactTitles[(int) $task['artifact_id']]) ?></a>
                            <?php endif; ?>
                            <?php if (!empty($task['milestone'])): ?>
                                <span class="muted">· <?= e($task['milestone']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($task['depends_on_task_id']) && isset($taskTitles[(int) $task['depends_on_task_id']])): ?>
                                <span class="muted">· after <?= e($taskTitles[(int) $task['depends_on_task_id']]) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($task['due_at'])): ?>
                                <span class="muted">· due <?= e($task['due_at']) ?> UTC</span>
                            <?php endif; ?>
                        </span>
                        <span class="pill"><?= e($task['status']) ?></span>
                        <span class="muted"><?= e($task['assignment_state']) ?></span>
                    </li>
                    <?php
                    $isAssignee = (int) ($task['assignee_id'] ?? 0) === (int) $user['id'];
                    $canUpdateStatus = $can_manage || $isAssignee;
                    ?>
                    <?php if ($can_manage || $isAssignee): ?>
                        <li class="task-actions">
                            <div class="row">
                                <?php if ($can_manage && ($task['assignee_id'] === null || $task['assignment_state'] !== 'assigned')): ?>
                                    <form method="post" action="/app/tasks/<?= (int) $task['id'] ?>/assign" class="inline">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <button class="link" type="submit">Auto-assign best fit</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($can_manage && $members !== []): ?>
                                    <form method="post" action="/app/tasks/<?= (int) $task['id'] ?>/assign-manual" class="inline">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <select name="user_id" required>
                                            <option value="">Assign to…</option>
                                            <?php foreach ($members as $member): ?>
                                                <option value="<?= (int) $member['user_id'] ?>"><?= e($member['email']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="link" type="submit">Assign</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (($can_manage || $isAssignee) && $task['assignee_id'] !== null): ?>
                                    <form method="post" action="/app/tasks/<?= (int) $task['id'] ?>/release" class="inline">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <button class="link" type="submit">Release</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <?php if ($canUpdateStatus): ?>
                                <form method="post" action="/app/tasks/<?= (int) $task['id'] ?>/status" class="inline">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <select name="status">
                                        <?php foreach (\Nico\Edge\Tasks\TaskRepository::STATUSES as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $task['status'] === $status ? 'selected' : '' ?>>
                                                <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="link" type="submit">Update status</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <details class="disclosure">
                <summary>Add a task</summary>
                <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/tasks" class="stack">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label>Title <input name="title" required maxlength="255"></label>
                    <label>Description <textarea name="description" rows="2"></textarea></label>
                    <label>Required tags <input name="requirements" placeholder="php, design, robotics"></label>
                    <label>Milestone / phase <input name="milestone" maxlength="120" placeholder="e.g. Field test"></label>
                    <label>Depends on task
                        <select name="depends_on_task_id">
                            <option value="0">None</option>
                            <?php foreach ($tasks as $candidate): ?>
                                <option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Due at <input type="datetime-local" name="due_at"></label>
                    <label>Linked learning artifact
                        <select name="artifact_id">
                            <option value="0">None</option>
                            <?php foreach ($artifacts as $artifact): ?>
                                <option value="<?= (int) $artifact['id'] ?>">
                                    <?= e($artifact['module']) ?> · <?= e($artifactTitles[(int) $artifact['id']]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Priority
                        <select name="priority">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <button class="primary" type="submit">Add task</button>
                </form>
            </details>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Artifacts</h2>
            <span class="muted"><?= count($artifacts) ?> current</span>
        </div>
        <?php if ($artifacts === []): ?>
            <p class="empty">Generate a lesson, quiz, or report.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($artifacts as $artifact): ?>
                    <li>
                        <a href="/app/artifacts/<?= (int) $artifact['id'] ?>">
                            <?= e($artifact['content']['title'] ?? $artifact['type']) ?>
                        </a>
                        <span class="muted"><?= e($artifact['module']) ?> · <?= e($artifact['type']) ?> v<?= (int) $artifact['version'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Knowledge sources</h2>
            <a href="/app/library/new?project_id=<?= (int) $project['id'] ?>">Add</a>
        </div>
        <?php if ($sources === []): ?>
            <p class="empty">Add notes or a brief to ground generation.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($sources as $source): ?>
                    <li>
                        <a href="/app/sources/<?= (int) $source['id'] ?>"><?= e($source['title']) ?></a>
                        <span class="muted"><?= e($source['kind']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Activities</h2>
            <span class="muted"><?= count($activities) ?></span>
        </div>
        <?php if ($activities === []): ?>
            <p class="empty">No hackathons or events yet.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($activities as $activity): ?>
                    <li>
                        <a href="/app/activities/<?= (int) $activity['id'] ?>"><?= e($activity['title']) ?></a>
                        <span class="muted"><?= e($activity['kind']) ?> · <?= e($activity['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <details class="disclosure">
                <summary>Create an activity</summary>
                <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/activities" class="stack">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label>Kind
                        <select name="kind">
                            <option value="event">Event / seminar</option>
                            <option value="hackathon">Hackathon</option>
                        </select>
                    </label>
                    <label>Title <input name="title" required maxlength="255"></label>
                    <label>Description <textarea name="description" rows="2"></textarea></label>
                    <div class="grid two tight">
                        <label>Starts <input type="datetime-local" name="starts_at"></label>
                        <label>Ends <input type="datetime-local" name="ends_at"></label>
                    </div>
                    <div class="grid two tight">
                        <label>Timezone <input name="timezone" value="UTC" maxlength="64"></label>
                        <label>Status
                            <select name="status">
                                <?php foreach (\Nico\Edge\Activities\ActivityRepository::STATUSES as $activityStatus): ?>
                                    <option value="<?= e($activityStatus) ?>" <?= $activityStatus === 'scheduled' ? 'selected' : '' ?>><?= e(ucfirst($activityStatus)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label>Location <input name="location"></label>
                    <label>Join URL <input type="url" name="join_url"></label>
                    <label>Rules URL (hackathon) <input type="url" name="rules_url"></label>
                    <label>Capacity <input type="number" min="1" name="capacity"></label>
                    <label>Visibility
                        <select name="visibility">
                            <option value="workspace">Project members</option>
                            <option value="public">Any signed-in user with the link</option>
                        </select>
                    </label>
                    <label>Speaker name (event) <input name="speaker_name"></label>
                    <label>Speaker bio (event) <textarea name="speaker_bio" rows="2"></textarea></label>
                    <label>Team size max (hackathon) <input type="number" min="1" max="50" name="team_size_max" value="4"></label>
                    <label>Tracks (hackathon) <input name="tracks"></label>
                    <label>Prizes (hackathon) <input name="prizes"></label>
                    <label>Agenda / timeline <textarea name="agenda" rows="4" placeholder="One item per line"></textarea></label>
                    <label>Resources / links <textarea name="resources" rows="3" placeholder="One link or resource per line"></textarea></label>
                    <button class="primary" type="submit">Create activity</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <h2>People</h2>
    <?php if ($members === []): ?>
        <p class="empty">No members yet.</p>
    <?php else: ?>
        <ul class="list compact">
            <?php foreach ($members as $member): ?>
                <li>
                    <span><?= e($member['email']) ?></span>
                    <span class="pill"><?= e($member['role']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($can_manage): ?>
        <details class="disclosure">
            <summary>Add a member</summary>
            <form method="post" action="/app/projects/<?= (int) $project['id'] ?>/members" class="stack">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label>Email <input type="email" name="email" required></label>
                <label>Role
                    <select name="role">
                        <option value="member">Member</option>
                        <option value="organizer">Organizer</option>
                        <option value="owner">Owner</option>
                    </select>
                </label>
                <button class="primary" type="submit">Add member</button>
            </form>
        </details>
    <?php endif; ?>
</section>
