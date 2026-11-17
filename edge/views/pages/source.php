<?php
/** @var array<string,mixed> $source */
/** @var list<array<string,mixed>> $chunks */
/** @var list<array<string,mixed>> $artifacts */
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Knowledge source · <?= e($source['kind']) ?></p>
        <h1><?= e($source['title']) ?></h1>
        <div class="row">
            <?php if ($source['project_title'] !== null): ?>
                <a class="pill" href="/app/projects/<?= (int) $source['project_id'] ?>"><?= e($source['project_title']) ?></a>
            <?php endif; ?>
            <span class="pill"><?= count($chunks) ?> chunks</span>
        </div>
    </div>
    <div class="head-actions">
        <form method="post" action="/app/sources/<?= (int) $source['id'] ?>/generate" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="type" value="lesson">
            <button class="button" type="submit">Generate lesson</button>
        </form>
        <form method="post" action="/app/sources/<?= (int) $source['id'] ?>/generate" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="type" value="quiz">
            <button class="button primary" type="submit">Generate quiz</button>
        </form>
    </div>
</section>

<div class="grid two">
    <section class="panel">
        <h2>Source text</h2>
        <div class="prose"><?= nl2br(e($source['body'])) ?></div>
    </section>

    <section class="panel">
        <h2>Generations</h2>
        <?php if ($artifacts === []): ?>
            <p class="empty">No artifacts generated from this source yet.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($artifacts as $artifact): ?>
                    <li>
                        <a href="/app/artifacts/<?= (int) $artifact['id'] ?>"><?= e($artifact['content']['title'] ?? $artifact['type']) ?></a>
                        <span class="muted"><?= e($artifact['module']) ?> v<?= (int) $artifact['version'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <h2>Chunks</h2>
    <ol class="chunks">
        <?php foreach ($chunks as $chunk): ?>
            <li>
                <span class="muted">#<?= (int) $chunk['ordinal'] + 1 ?> · <?= (int) $chunk['token_count'] ?> words</span>
                <p><?= e($chunk['text']) ?></p>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
