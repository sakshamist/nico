<section class="page-head">
    <div>
        <p class="eyebrow">People</p>
        <h1>Interns</h1>
        <p class="muted">Open an intern to add material and follow their progress.</p>
    </div>
</section>

<section class="panel">
    <?php if ($interns === []): ?>
        <p class="empty">No interns yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($interns as $intern): ?>
                <li>
                    <a href="/admin/interns/<?= (int) $intern['id'] ?>"><?= e($intern['email']) ?></a>
                    <span class="muted"><?= (int) $intern['material_count'] ?> material item(s)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
