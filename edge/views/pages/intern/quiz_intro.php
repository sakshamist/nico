<section class="auth-card">
    <p class="eyebrow">Quiz</p>
    <h1><?= e($artifact['content']['title'] ?? 'Quiz') ?></h1>
    <p class="muted"><?= e($artifact['content']['instructions'] ?? 'Answer one question at a time.') ?></p>
    <p class="muted"><?= count($artifact['content']['questions'] ?? []) ?> questions · based on your course material.</p>
    <form method="post" action="/intern/artifacts/<?= (int) $artifact['id'] ?>/start">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="primary" type="submit">Start quiz</button>
    </form>
</section>
