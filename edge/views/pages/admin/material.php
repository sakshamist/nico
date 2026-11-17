<section class="page-head">
    <div>
        <p class="eyebrow">Course material</p>
        <h1>Material</h1>
        <p class="muted">Add material once, choose who gets it, and see everything generated from it.</p>
    </div>
</section>

<section class="panel narrow">
    <h2>Add written material</h2>
    <form method="post" action="/admin/material" class="stack">
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
        <fieldset>
            <legend>Assign to interns</legend>
            <?php foreach ($interns as $intern): ?>
                <label class="option">
                    <input type="checkbox" name="intern_ids[]" value="<?= (int) $intern['id'] ?>">
                    <span><?= e($intern['email']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button class="primary" type="submit">Add material</button>
    </form>
</section>

<section class="panel narrow">
    <h2>Add a PDF</h2>
    <p class="muted">The PDF is read in this browser. Maximum <?= number_format($maxPdfBytes / 1048576, 1) ?> MB.</p>
    <form id="pdf-upload-form" method="post" action="/admin/material/pdf" enctype="multipart/form-data" class="stack"
          data-max-bytes="<?= $maxPdfBytes ?>" data-max-text-bytes="<?= $maxTextBytes ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxPdfBytes ?>">
        <input type="hidden" name="extracted_text" id="pdf-extracted-text" value="">
        <input type="hidden" name="page_segments" id="pdf-page-segments" value="[]">
        <label>PDF file <input type="file" name="pdf" id="pdf-file" accept="application/pdf,.pdf" required></label>
        <label>Title (optional) <input name="title" maxlength="255"></label>
        <fieldset>
            <legend>Assign to interns</legend>
            <?php foreach ($interns as $intern): ?>
                <label class="option">
                    <input type="checkbox" name="intern_ids[]" value="<?= (int) $intern['id'] ?>">
                    <span><?= e($intern['email']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p id="pdf-upload-status" class="muted" aria-live="polite">Choose a PDF to begin.</p>
        <button id="pdf-upload-button" class="primary" type="submit">Read and add PDF</button>
    </form>
</section>

<section class="panel">
    <h2>All material</h2>
    <?php if ($materials === []): ?>
        <p class="empty">No material has been assigned yet.</p>
    <?php else: ?>
        <ul class="cards">
            <?php foreach ($materials as $material): ?>
                <li class="card">
                    <strong><?= e($material['title']) ?></strong>
                    <p class="muted"><?= e($material['kind']) ?> · assigned to <?= (int) $material['intern_count'] ?> intern(s)</p>
                    <p class="muted"><?= e($material['interns'] ?? 'Nobody') ?></p>

                    <form method="post" action="/admin/material/<?= (int) $material['id'] ?>/generate" class="row">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <select name="type">
                            <option value="lesson">Lesson</option>
                            <option value="quiz">Quiz</option>
                            <option value="report">Report</option>
                        </select>
                        <select name="scope">
                            <option value="shared">Shared with all assigned</option>
                            <option value="per_intern">One copy per intern</option>
                        </select>
                        <button class="primary" type="submit">Generate</button>
                    </form>

                    <?php if ($material['artifacts'] !== []): ?>
                        <p class="muted">Generated material:</p>
                        <ul class="list">
                            <?php foreach ($material['artifacts'] as $artifact): ?>
                                <li>
                                    <a href="/admin/material/artifacts/<?= (int) $artifact['id'] ?>">
                                        <?= e($artifact['content']['title'] ?? ucfirst($artifact['type'])) ?>
                                    </a>
                                    <span class="muted">· <?= e($artifact['type']) ?> · <?= e(str_replace('_', ' ', $artifact['scope'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <details class="disclosure">
                        <summary>Change assignments</summary>
                        <form method="post" action="/admin/material/<?= (int) $material['id'] ?>/assignments" class="stack">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <?php foreach ($interns as $intern): ?>
                                <label class="option">
                                    <input type="checkbox" name="intern_ids[]" value="<?= (int) $intern['id'] ?>"
                                        <?= in_array((int) $intern['id'], $material['assigned_intern_ids'], true) ? 'checked' : '' ?>>
                                    <span><?= e($intern['email']) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <button class="primary" type="submit">Save assignments</button>
                        </form>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<script src="/assets/vendor/pdfjs/pdf.min.js" defer></script>
<script src="/assets/pdf-upload.js" defer></script>
