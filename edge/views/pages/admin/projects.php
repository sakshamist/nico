<section class="page-head">
    <div>
        <p class="eyebrow">Projects</p>
        <h1>Projects</h1>
        <p class="muted">Create a private journey for one intern or a shared live project.</p>
    </div>
</section>

<section class="panel narrow">
    <h2>New project</h2>
    <form method="post" action="/admin/projects" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Title <input name="title" required maxlength="255"></label>
        <label>Description <textarea name="description" rows="3"></textarea></label>
        <label>Kind
            <select name="kind">
                <option value="live">Live project</option>
                <option value="general">General</option>
            </select>
        </label>
        <label>Mode
            <select name="mode">
                <option value="personal">Private — one intern</option>
                <option value="shared">Shared — many interns</option>
            </select>
        </label>
        <fieldset>
            <legend>Interns</legend>
            <?php foreach ($interns as $intern): ?>
                <label class="option">
                    <input type="checkbox" name="intern_ids[]" value="<?= (int) $intern['id'] ?>">
                    <span><?= e($intern['email']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button class="primary" type="submit">Create project</button>
    </form>
</section>

<section class="panel">
    <h2>All projects</h2>
    <?php if ($projects === []): ?>
        <p class="empty">No projects yet.</p>
    <?php else: ?>
        <ul class="cards">
            <?php foreach ($projects as $project): ?>
                <li class="card">
                    <a href="/admin/projects/<?= (int) $project['id'] ?>"><strong><?= e($project['title']) ?></strong></a>
                    <p class="muted"><?= e($project['mode']) ?> · <?= e($project['status']) ?></p>
                    <p class="muted"><?= (int) $project['member_count'] ?> member(s) · <?= (int) $project['task_count'] ?> task(s)</p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
