<?php
/** @var list<array<string,mixed>> $sources */
/** @var string $query */
/** @var list<array<string,mixed>> $results */
/** @var string $mode */
?>
<section class="page-head">
    <div>
        <p class="eyebrow">M2 knowledge</p>
        <h1>Knowledge library</h1>
        <p class="muted">Notes, briefs, and uploaded PDFs, chunked for grounded retrieval and generation.</p>
    </div>
    <div class="head-actions">
        <a class="button primary" href="/app/library/new">Add source</a>
    </div>
</section>

<section class="panel">
    <form method="get" action="/app/library" class="row search-row">
        <input name="q" value="<?= e($query) ?>" placeholder="Search chunks across your sources…">
        <button class="primary" type="submit">Search</button>
    </form>
</section>

<?php if ($query !== ''): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Search results</h2>
            <span class="muted"><?= count($results) ?> match(es) for “<?= e($query) ?>” · <?= e($mode ?? 'lexical') ?> search</span>
        </div>
        <?php if ($results === []): ?>
            <p class="empty">No chunks matched those terms.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($results as $result): ?>
                    <li>
                        <span>
                            <a href="/app/sources/<?= (int) $result['source_id'] ?>"><?= e($result['source_title']) ?></a>
                            <span class="muted">chunk <?= (int) $result['ordinal'] + 1 ?> · score <?= (int) $result['score'] ?></span>
                            <p><?= e(mb_substr((string) $result['text'], 0, 240)) ?><?= mb_strlen((string) $result['text']) > 240 ? '…' : '' ?></p>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($sources === []): ?>
    <section class="panel"><p class="empty">No sources yet. Add a note to get started.</p></section>
<?php else: ?>
    <section class="panel">
        <ul class="cards">
            <?php foreach ($sources as $source): ?>
                <li class="card">
                    <a href="/app/sources/<?= (int) $source['id'] ?>"><strong><?= e($source['title']) ?></strong></a>
                    <p class="muted"><?= e(mb_substr((string) $source['body'], 0, 180)) ?><?= mb_strlen((string) $source['body']) > 180 ? '…' : '' ?></p>
                    <div class="row">
                        <span class="pill"><?= e($source['kind']) ?></span>
                        <?php if ($source['project_title'] !== null): ?>
                            <span class="pill"><?= e($source['project_title']) ?></span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
