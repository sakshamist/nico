<section class="page-head">
    <div>
        <p class="eyebrow">Project</p>
        <h1><?= e($project['title']) ?></h1>
        <p class="muted"><?= e($project['description'] ?: 'No description.') ?></p>
        <div class="row">
            <span class="pill"><?= e($project['mode']) ?></span>
            <span class="pill"><?= e($project['status']) ?></span>
        </div>
    </div>
</section>

<section class="panel narrow">
    <h2>Add a task</h2>
    <form method="post" action="/admin/projects/<?= (int) $project['id'] ?>/tasks" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Title <input name="title" required maxlength="255"></label>
        <label>Milestone <input name="milestone" maxlength="120"></label>
        <label>Due (UTC) <input name="due_at" placeholder="2026-12-31 17:00:00"></label>
        <label>Assign to
            <select name="assignee_id">
                <option value="0">Unassigned</option>
                <?php foreach ($members as $member): ?>
                    <?php if ((string) $member['role'] === 'owner') continue; ?>
                    <option value="<?= (int) $member['id'] ?>"><?= e($member['email']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="primary" type="submit">Add task</button>
    </form>
</section>

<section class="panel">
    <h2>Tasks</h2>
    <?php if ($tasks === []): ?>
        <p class="empty">No tasks yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($tasks as $task): ?>
                <li>
                    <span>
                        <strong><?= e($task['title']) ?></strong>
                        <?php if ($task['assignee_email'] !== null): ?>
                            <span class="muted">· <?= e($task['assignee_email']) ?></span>
                        <?php endif; ?>
                        <?php if ($task['milestone'] !== null): ?>
                            <span class="muted">· <?= e($task['milestone']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="muted"><?= e($task['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Interns</h2>
    <ul class="list">
        <?php foreach ($members as $member): ?>
            <li><?= e($member['email']) ?> <span class="muted">· <?= e($member['role']) ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="panel">
    <h2>Activity</h2>
    <?php if ($activities === []): ?>
        <p class="empty">No activity yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($activities as $activity): ?>
                <li>
                    <a href="/app/activities/<?= (int) $activity['id'] ?>"><?= e($activity['title']) ?></a>
                    <span class="muted">· <?= e($activity['kind']) ?> · <?= e($activity['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
