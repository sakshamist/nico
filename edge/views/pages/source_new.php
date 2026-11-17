<?php /** @var list<array<string,mixed>> $projects */ ?>
<section class="page-head">
    <div>
        <p class="eyebrow">M2 knowledge</p>
        <h1>Add a knowledge source</h1>
        <p class="muted">Paste a note, brief, or transcript. It is stored locally and split into retrieval chunks.</p>
    </div>
</section>

<section class="panel narrow">
    <form method="post" action="/app/library" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Title
            <input name="title" required maxlength="255" placeholder="Robotics safety brief">
        </label>
        <label>Kind
            <select name="kind">
                <option value="note">Note</option>
                <option value="brief">Brief</option>
                <option value="transcript">Transcript</option>
            </select>
        </label>
        <label>Project (optional)
            <select name="project_id">
                <option value="0">Personal library</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"
                        <?= (int) ($_GET['project_id'] ?? 0) === (int) $project['id'] ? 'selected' : '' ?>>
                        <?= e($project['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Body
            <textarea name="body" rows="14" required placeholder="Write or paste the source material here."></textarea>
        </label>
        <button class="primary" type="submit">Store source</button>
    </form>
</section>
