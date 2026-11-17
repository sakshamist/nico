<?php
/** @var array<string,mixed> $artifact */
/** @var array<string,mixed>|null $step */
/** @var int $position */
/** @var int $total */
/** @var array<string,mixed>|null $progress */
$content = $artifact['content'] ?? [];
?>
<section class="page-head">
    <div>
        <p class="eyebrow">M4 lesson · step <?= $position + 1 ?> of <?= max(1, $total) ?></p>
        <h1><?= e($content['title'] ?? 'Lesson') ?></h1>
        <?php if (!empty($content['summary'])): ?>
            <p class="muted"><?= e($content['summary']) ?></p>
        <?php endif; ?>
    </div>
</section>

<div class="progressbar"><span style="width: <?= $total > 0 ? (int) round((($position + 1) / $total) * 100) : 0 ?>%"></span></div>

<?php if ($step === null): ?>
    <section class="panel"><p class="empty">This lesson has no steps.</p></section>
<?php else: ?>
    <section class="panel lesson-step">
        <h2><?= e($step['title'] ?? 'Step') ?></h2>
        <div class="prose"><?= nl2br(e($step['body'] ?? '')) ?></div>

        <?php if (!empty($step['key_points'])): ?>
            <h3>Key points</h3>
            <div class="row">
                <?php foreach ($step['key_points'] as $point): ?>
                    <span class="pill"><?= e($point) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($step['source_refs'])): ?>
            <p class="muted">
                Source:
                <?php foreach ($step['source_refs'] as $ref): ?>
                    <a href="/app/sources/<?= (int) $ref['source_id'] ?>"><?= e($ref['source_title']) ?></a>
                    (chunk <?= (int) $ref['ordinal'] + 1 ?>)
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </section>

    <div class="actions">
        <?php if ($position > 0): ?>
            <form method="post" action="/app/learn/<?= (int) $artifact['id'] ?>/progress">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="position" value="<?= $position - 1 ?>">
                <button class="button" type="submit">Previous</button>
            </form>
        <?php endif; ?>

        <?php if ($position + 1 < $total): ?>
            <form method="post" action="/app/learn/<?= (int) $artifact['id'] ?>/progress">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="position" value="<?= $position + 1 ?>">
                <button class="button primary" type="submit">Next step</button>
            </form>
        <?php else: ?>
            <form method="post" action="/app/learn/<?= (int) $artifact['id'] ?>/progress">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="position" value="<?= $position ?>">
                <input type="hidden" name="completed" value="1">
                <button class="button primary" type="submit"><?= !empty($progress['completed']) ? 'Completed' : 'Mark complete' ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
