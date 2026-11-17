<?php
/** @var list<array<string,mixed>> $projects */
/** @var list<array<string,mixed>> $artifacts */
/** @var list<array<string,mixed>> $progress */
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Home</p>
        <h1>Your learning edge</h1>
        <p class="muted">Notes become knowledge, knowledge becomes lessons and quizzes, work becomes evidence.</p>
    </div>
    <div class="head-actions">
        <a class="button" href="/app/library/new">Add source</a>
        <a class="button primary" href="/app/projects">New project</a>
    </div>
</section>

<section class="stats">
    <div class="stat"><strong><?= count($projects) ?></strong><span>projects</span></div>
    <div class="stat"><strong><?= count($artifacts) ?></strong><span>current artifacts</span></div>
    <div class="stat"><strong><?= count($progress) ?></strong><span>lessons with progress</span></div>
</section>

<div class="grid two">
    <section class="panel">
        <div class="panel-head">
            <h2>Projects</h2>
            <a href="/app/projects">All projects</a>
        </div>
        <?php if ($projects === []): ?>
            <p class="empty">No projects yet. Create one to host tasks, sources, and activities.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach (array_slice($projects, 0, 6) as $project): ?>
                    <li>
                        <a href="/app/projects/<?= (int) $project['id'] ?>"><?= e($project['title']) ?></a>
                        <span class="muted"><?= e($project['kind']) ?> · <?= e($project['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Recent artifacts</h2>
            <a href="/app/library">Library</a>
        </div>
        <?php if ($artifacts === []): ?>
            <p class="empty">Generate a lesson or quiz from a knowledge source to see it here.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach (array_slice($artifacts, 0, 8) as $artifact): ?>
                    <li>
                        <a href="/app/artifacts/<?= (int) $artifact['id'] ?>">
                            <?= e($artifact['content']['title'] ?? $artifact['type']) ?>
                        </a>
                        <span class="muted">
                            <?= e($artifact['module']) ?> · <?= e($artifact['type']) ?> v<?= (int) $artifact['version'] ?>
                            <?php if ($artifact['project_title'] !== null): ?>
                                · <?= e($artifact['project_title']) ?>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <div class="panel-head">
        <h2>Continue learning</h2>
    </div>
    <?php if ($progress === []): ?>
        <p class="empty">Progress through a lesson and it will appear here.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($progress as $row): ?>
                <li>
                    <a href="/app/artifacts/<?= (int) $row['artifact_id'] ?>"><?= e($row['title']) ?></a>
                    <span class="muted">
                        step <?= (int) $row['position'] + 1 ?><?= $row['completed'] ? ' · completed' : '' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
