<section class="page-head">
    <div>
        <p class="eyebrow"><?= e(ucfirst($artifact['type'])) ?></p>
        <h1><?= e($artifact['content']['title'] ?? ucfirst($artifact['type'])) ?></h1>
        <p class="muted">Added <?= e($artifact['created_at']) ?></p>
    </div>
</section>

<?php if (!empty($artifact['content']['steps'])): ?>
    <section class="panel">
        <?php foreach ($artifact['content']['steps'] as $index => $step): ?>
            <article class="report-section">
                <h2><?= ($index + 1) . '. ' . e($step['title'] ?? 'Step') ?></h2>
                <div class="prose"><?= nl2br(e($step['body'] ?? '')) ?></div>
                <?php if (!empty($step['key_points'])): ?>
                    <div class="row">
                        <?php foreach ($step['key_points'] as $point): ?>
                            <span class="pill"><?= e($point) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php elseif (!empty($artifact['content']['questions'])): ?>
    <section class="panel">
        <?php foreach ($artifact['content']['questions'] as $index => $question): ?>
            <article class="report-section">
                <h2><?= ($index + 1) . '. ' . e($question['prompt'] ?? 'Question') ?></h2>
                <ul class="list">
                    <?php foreach ($question['options'] ?? [] as $optionIndex => $option): ?>
                        <li>
                            <?= e($option) ?>
                            <?= (int) ($question['answer_index'] ?? -1) === (int) $optionIndex ? '· correct' : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="muted"><?= e($question['explanation'] ?? '') ?></p>
            </article>
        <?php endforeach; ?>
    </section>
<?php elseif (!empty($artifact['content']['sections'])): ?>
    <section class="panel report">
        <?php foreach ($artifact['content']['sections'] as $section): ?>
            <article class="report-section">
                <h2><?= e($section['heading'] ?? '') ?></h2>
                <p><?= e($section['body'] ?? '') ?></p>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <pre class="code"><?= e(json_encode($artifact['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </section>
<?php endif; ?>
