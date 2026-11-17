<?php /** @var list<array<string,mixed>> $projects */ ?>
<?php $maxPdfBytes = (int) ($maxPdfBytes ?? (20 * 1024 * 1024)); ?>
<?php $maxTextBytes = (int) ($maxTextBytes ?? (2 * 1024 * 1024)); ?>
<section class="page-head">
    <div>
        <p class="eyebrow">M1 + M2 knowledge</p>
        <h1>Add a knowledge source</h1>
        <p class="muted">Paste a note, brief, or transcript, or upload a PDF. Sources are stored locally and split into retrieval chunks.</p>
    </div>
</section>

<section class="panel narrow">
    <h2>Paste text</h2>
    <form method="post" action="/app/library" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Title
            <input name="title" required maxlength="255" placeholder="Robotics safety brief">
        </label>
        <label>Kind
            <select name="kind">
                <option value="note">Note</option>
                <option value="brief">Brief</option>
                <option value="transcript">Transcript</option>
            </select>
        </label>
        <label>Project (optional)
            <select name="project_id">
                <option value="0">Personal library</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"
                        <?= (int) ($_GET['project_id'] ?? 0) === (int) $project['id'] ? 'selected' : '' ?>>
                        <?= e($project['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Body
            <textarea name="body" rows="14" required placeholder="Write or paste the source material here."></textarea>
        </label>
        <button class="primary" type="submit">Store source</button>
    </form>
</section>

<section class="panel narrow">
    <h2>Upload a PDF</h2>
    <p class="muted">The PDF is read in your browser, then uploaded with its extracted text. Maximum <?= number_format($maxPdfBytes / 1048576, 1) ?> MB. Scanned PDFs need OCR, which is not available yet.</p>
    <form id="pdf-upload-form" method="post" action="/app/library/pdf" enctype="multipart/form-data" class="stack"
          data-max-bytes="<?= $maxPdfBytes ?>" data-max-text-bytes="<?= $maxTextBytes ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxPdfBytes ?>">
        <input type="hidden" name="extracted_text" id="pdf-extracted-text" value="">
        <input type="hidden" name="page_segments" id="pdf-page-segments" value="[]">
        <label>PDF file
            <input type="file" name="pdf" id="pdf-file" accept="application/pdf,.pdf" required>
        </label>
        <label>Title (optional)
            <input name="title" maxlength="255" placeholder="Defaults to the file name">
        </label>
        <label>Project (optional)
            <select name="project_id">
                <option value="0">Personal library</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"
                        <?= (int) ($_GET['project_id'] ?? 0) === (int) $project['id'] ? 'selected' : '' ?>>
                        <?= e($project['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p id="pdf-upload-status" class="muted" aria-live="polite">Choose a PDF to begin.</p>
        <noscript><p class="muted">JavaScript is required to read PDFs in the browser.</p></noscript>
        <button id="pdf-upload-button" class="primary" type="submit">Read and upload PDF</button>
    </form>
</section>

<script src="/assets/vendor/pdfjs/pdf.min.js" defer></script>
<script src="/assets/pdf-upload.js" defer></script>
