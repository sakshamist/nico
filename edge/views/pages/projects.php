<?php /** @var list<array<string,mixed>> $projects */ ?>
<section class="page-head">
    <div>
        <p class="eyebrow">Projects</p>
        <h1>Projects</h1>
        <p class="muted">A project hosts tasks, knowledge, artifacts, hackathons, and events.</p>
    </div>
</section>

<div class="grid two">
    <section class="panel">
        <h2>Create a project</h2>
        <form method="post" action="/app/projects" class="stack">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Title
                <input name="title" required maxlength="255" placeholder="Campus robotics sprint">
            </label>
            <label>Description
                <textarea name="description" rows="3" placeholder="What is this project for?"></textarea>
            </label>
            <label>Kind
                <select name="kind">
                    <option value="general">General</option>
                    <option value="live">Live project</option>
                </select>
            </label>
            <button class="primary" type="submit">Create project</button>
        </form>
    </section>

    <section class="panel">
        <h2>Your projects</h2>
        <?php if ($projects === []): ?>
            <p class="empty">Nothing here yet.</p>
        <?php else: ?>
            <ul class="cards">
                <?php foreach ($projects as $project): ?>
                    <li class="card">
                        <a href="/app/projects/<?= (int) $project['id'] ?>"><strong><?= e($project['title']) ?></strong></a>
                        <p class="muted"><?= e($project['description'] ?: 'No description') ?></p>
                        <div class="row">
                            <span class="pill"><?= e($project['kind']) ?></span>
                            <span class="pill"><?= e($project['status']) ?></span>
                            <span class="pill"><?= e($project['membership_role']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
