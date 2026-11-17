<?php
/** @var array<string,mixed> $artifact */
/** @var array<string,mixed>|null $project */
$content = $artifact['content'] ?? [];
$sections = is_array($content) ? ($content['sections'] ?? []) : [];
$citations = is_array($content) ? ($content['citations'] ?? []) : [];
?>
<section class="page-head">
    <div>
        <p class="eyebrow"><?= e($artifact['module']) ?> · <?= e($artifact['type']) ?> · version <?= (int) $artifact['version'] ?></p>
        <h1><?= e($content['title'] ?? ucfirst((string) $artifact['type'])) ?></h1>
        <?php if ($project !== null): ?>
            <p class="muted"><a href="/app/projects/<?= (int) $project['id'] ?>"><?= e($project['title']) ?></a></p>
        <?php endif; ?>
        <div class="row">
            <span class="pill"><?= e($artifact['status']) ?></span>
            <?php if (!empty($content['generated_at'])): ?>
                <span class="pill"><?= e($content['generated_at']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($sections !== []): ?>
    <section class="panel report">
        <?php foreach ($sections as $section): ?>
            <article class="report-section">
                <h2><?= e($section['heading'] ?? '') ?></h2>
                <p><?= e($section['body'] ?? '') ?></p>
                <?php if (!empty($section['items'])): ?>
                    <ul>
                        <?php foreach ($section['items'] as $item): ?>
                            <li>
                                <?php if (isset($item['artifact_id'])): ?>
                                    <a href="/app/artifacts/<?= (int) $item['artifact_id'] ?>">
                                        <?= e($item['module'] ?? '') ?> <?= e($item['type'] ?? '') ?> v<?= (int) ($item['version'] ?? 1) ?>
                                    </a>
                                <?php elseif (isset($item['source_id'])): ?>
                                    <a href="/app/sources/<?= (int) $item['source_id'] ?>"><?= e($item['title'] ?? 'Source') ?></a>
                                <?php elseif (isset($item['activity_id'])): ?>
                                    <a href="/app/activities/<?= (int) $item['activity_id'] ?>"><?= e($item['title'] ?? 'Activity') ?></a>
                                <?php else: ?>
                                    <?= e($item['title'] ?? json_encode($item)) ?>
                                    <?php if (isset($item['status'])): ?><span class="muted"> · <?= e($item['status']) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($citations !== []): ?>
        <section class="panel">
            <h2>Citations</h2>
            <ul class="list">
                <?php foreach ($citations as $citation): ?>
                    <li>
                        <?php
                        $href = match ($citation['kind'] ?? '') {
                            'artifact' => '/app/artifacts/' . (int) $citation['id'],
                            'source'   => '/app/sources/' . (int) $citation['id'],
                            'activity' => '/app/activities/' . (int) $citation['id'],
                            default    => null,
                        };
                        ?>
                        <?php if ($href !== null): ?>
                            <a href="<?= e($href) ?>"><?= e($citation['label']) ?></a>
                        <?php else: ?>
                            <?= e($citation['label']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php else: ?>
    <section class="panel">
        <h2>Content</h2>
        <pre class="code"><?= e(json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </section>
<?php endif; ?>
