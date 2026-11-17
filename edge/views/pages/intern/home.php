<section class="page-head">
    <div>
        <p class="eyebrow">Home</p>
        <h1>Welcome back</h1>
        <p class="muted">Pick up where you left off.</p>
    </div>
</section>

<section class="stats">
    <div class="stat"><strong><?= count($sources) ?></strong><span>material items</span></div>
    <div class="stat"><strong><?= count($artifacts) ?></strong><span>lessons and quizzes</span></div>
    <div class="stat"><strong><?= count($attempts) ?></strong><span>quizzes completed</span></div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Continue learning</h2>
        <a href="/intern/course">Open course</a>
    </div>
    <?php if ($artifacts === []): ?>
        <p class="empty">Your admin has not generated a lesson or quiz yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach (array_slice($artifacts, 0, 6) as $artifact): ?>
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
