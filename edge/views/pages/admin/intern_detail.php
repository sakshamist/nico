<section class="page-head">
    <div>
        <p class="eyebrow">Intern</p>
        <h1><?= e($intern['email']) ?></h1>
        <p class="muted">Add material, generate learning, and see how this intern is doing.</p>
    </div>
</section>

<section class="panel">
    <div class="row tabs">
        <a class="button <?= $tab === 'material' ? 'primary' : '' ?>" href="/admin/interns/<?= (int) $intern['id'] ?>?tab=material">Material</a>
        <a class="button <?= $tab === 'progress' ? 'primary' : '' ?>" href="/admin/interns/<?= (int) $intern['id'] ?>?tab=progress">Progress</a>
    </div>
</section>

<?php if ($tab === 'progress'): ?>
    <section class="panel">
        <h2>Lessons</h2>
        <?php if ($progress === []): ?>
            <p class="empty">No lesson progress yet.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($progress as $row): ?>
                    <li>
                        <span><?= e($row['title'] ?? 'Lesson') ?></span>
                        <span class="muted">
                            step <?= (int) $row['position'] + 1 ?>
                            <?= $row['completed'] ? ' · completed' : '' ?>
                            · <?= e($row['updated_at']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Quizzes</h2>
        <?php if ($attempts === []): ?>
            <p class="empty">No quiz results yet.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($attempts as $row): ?>
                    <li>
                        <span><?= e($row['title'] ?? 'Quiz') ?></span>
                        <span class="muted">
                            <?= (int) ($row['score'] ?? 0) ?>/<?= (int) $row['total'] ?>
                            · <?= e($row['created_at']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel narrow">
        <h2>Add written material</h2>
        <form method="post" action="/admin/interns/<?= (int) $intern['id'] ?>/material" class="stack">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Title <input name="title" required maxlength="255"></label>
            <label>Kind
                <select name="kind">
                    <option value="note">Note</option>
                    <option value="brief">Brief</option>
                    <option value="transcript">Transcript</option>
                </select>
            </label>
            <label>Body <textarea name="body" rows="8" required></textarea></label>
            <button class="primary" type="submit">Add to course</button>
        </form>
    </section>

    <section class="panel narrow">
        <h2>Add a PDF</h2>
        <p class="muted">The PDF is read in this browser. Maximum <?= number_format($maxPdfBytes / 1048576, 1) ?> MB.</p>
        <form id="pdf-upload-form" method="post" action="/admin/interns/<?= (int) $intern['id'] ?>/material/pdf" enctype="multipart/form-data" class="stack"
              data-max-bytes="<?= $maxPdfBytes ?>" data-max-text-bytes="<?= $maxTextBytes ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxPdfBytes ?>">
            <input type="hidden" name="extracted_text" id="pdf-extracted-text" value="">
            <input type="hidden" name="page_segments" id="pdf-page-segments" value="[]">
            <label>PDF file <input type="file" name="pdf" id="pdf-file" accept="application/pdf,.pdf" required></label>
            <label>Title (optional) <input name="title" maxlength="255"></label>
            <p id="pdf-upload-status" class="muted" aria-live="polite">Choose a PDF to begin.</p>
            <button id="pdf-upload-button" class="primary" type="submit">Read and add PDF</button>
        </form>
    </section>

    <section class="panel">
        <h2>Assigned material</h2>
        <?php if ($sources === []): ?>
            <p class="empty">Nothing assigned yet.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($sources as $source): ?>
                    <li>
                        <span>
                            <strong><?= e($source['title']) ?></strong>
                            <span class="muted">· <?= e($source['kind']) ?></span>
                        </span>
                        <div class="row">
                            <form method="post" action="/admin/interns/<?= (int) $intern['id'] ?>/sources/<?= (int) $source['id'] ?>/generate" class="inline">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="type" value="lesson">
                                <button class="link" type="submit">Generate lesson</button>
                            </form>
                            <form method="post" action="/admin/interns/<?= (int) $intern['id'] ?>/sources/<?= (int) $source['id'] ?>/generate" class="inline">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="type" value="quiz">
                                <button class="link" type="submit">Generate quiz</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <script src="/assets/vendor/pdfjs/pdf.min.js" defer></script>
    <script src="/assets/pdf-upload.js" defer></script>
<?php endif; ?>
