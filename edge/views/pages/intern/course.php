<section class="page-head">
    <div>
        <p class="eyebrow">Course</p>
        <h1>Your course</h1>
        <p class="muted">Everything your admin has added for you.</p>
    </div>
</section>

<section class="panel">
    <h2>Material</h2>
    <?php if ($sources === []): ?>
        <p class="empty">No material assigned yet.</p>
    <?php else: ?>
        <ul class="cards">
            <?php foreach ($sources as $source): ?>
                <li class="card">
                    <strong><?= e($source['title']) ?></strong>
                    <p class="muted"><?= e(mb_substr($source['body'], 0, 180)) ?><?= mb_strlen($source['body']) > 180 ? '…' : '' ?></p>
                    <span class="pill"><?= e($source['kind']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel narrow">
    <h2>My notes</h2>
    <form method="post" action="/intern/notes" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Title <input name="title" required maxlength="255"></label>
        <label>Note <textarea name="body" rows="4" required></textarea></label>
        <button class="primary" type="submit">Save note</button>
    </form>
    <?php if ($notes !== []): ?>
        <ul class="list">
            <?php foreach ($notes as $note): ?>
                <li>
                    <strong><?= e($note['title']) ?></strong>
                    <span class="muted">· <?= e($note['updated_at']) ?></span>
                    <p><?= nl2br(e($note['body'])) ?></p>
                    <form method="post" action="/intern/notes/<?= (int) $note['id'] ?>/delete" class="inline">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="link" type="submit">Delete</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="empty">No notes yet.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Lessons and quizzes</h2>
    <?php if ($artifacts === []): ?>
        <p class="empty">Nothing generated yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($artifacts as $artifact): ?>
                <li>
                    <a href="/intern/learn/<?= (int) $artifact['id'] ?>">
                        <?= e($artifact['content']['title'] ?? ucfirst($artifact['type'])) ?>
                    </a>
                    <span class="muted"><?= e($artifact['type']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
