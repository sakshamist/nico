<?php /** @var array<string,mixed> $artifact */ $content = $artifact['content'] ?? []; ?>
<section class="auth-card">
    <p class="eyebrow">Quiz</p>
    <h1><?= e($content['title'] ?? 'Quiz') ?></h1>
    <p class="muted"><?= e($content['instructions'] ?? 'Answer one question at a time.') ?></p>
    <p class="muted"><?= count($content['questions'] ?? []) ?> questions · grounded in your knowledge sources.</p>
    <form method="post" action="/app/artifacts/<?= (int) $artifact['id'] ?>/start">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="primary" type="submit">Start quiz</button>
    </form>
</section>
