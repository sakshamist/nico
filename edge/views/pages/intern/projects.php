<section class="page-head">
    <div>
        <p class="eyebrow">Projects</p>
        <h1>Your projects</h1>
        <p class="muted">Live work you are part of, with your tasks and activity.</p>
    </div>
</section>

<?php if ($projects === []): ?>
    <section class="panel"><p class="empty">No projects yet.</p></section>
<?php else: ?>
    <?php foreach ($projects as $project): ?>
        <section class="panel">
            <div class="panel-head">
                <h2><?= e($project['title']) ?></h2>
                <span class="muted"><?= e($project['mode']) ?> · <?= e($project['status']) ?></span>
            </div>
            <?php if ($project['description'] !== null): ?>
                <p class="muted"><?= e($project['description']) ?></p>
            <?php endif; ?>

            <h3>Your tasks</h3>
            <?php $projectTasks = $tasksByProject[(int) $project['id']] ?? []; ?>
            <?php if ($projectTasks === []): ?>
                <p class="empty">No tasks assigned to you in this project.</p>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($projectTasks as $task): ?>
                        <li>
                            <span>
                                <strong><?= e($task['title']) ?></strong>
                                <?php if ($task['milestone'] !== null): ?><span class="muted">· <?= e($task['milestone']) ?></span><?php endif; ?>
                            </span>
                            <span class="muted"><?= e($task['status']) ?></span>
                            <?php if ($task['status'] !== 'done'): ?>
                                <form method="post" action="/intern/tasks/<?= (int) $task['id'] ?>/status" class="inline">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="status" value="done">
                                    <button class="link" type="submit">Mark done</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Activity</h3>
            <?php $projectActivities = $activitiesByProject[(int) $project['id']] ?? []; ?>
            <?php if ($projectActivities === []): ?>
                <p class="empty">No activity yet.</p>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($projectActivities as $activity): ?>
                        <li>
                            <a href="/app/activities/<?= (int) $activity['id'] ?>"><?= e($activity['title']) ?></a>
                            <span class="muted">· <?= e($activity['kind']) ?> · <?= e($activity['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
