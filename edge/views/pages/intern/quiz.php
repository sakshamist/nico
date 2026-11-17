<?php
$content = $artifact['content'] ?? [];
$questions = $content['questions'] ?? [];
$answered = count(array_filter($attempt['answers'] ?? [], static fn ($a): bool => $a !== null));
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Quiz</p>
        <h1><?= e($content['title'] ?? 'Quiz') ?></h1>
        <p class="muted">
            <?= $current === null ? 'Complete' : 'Question ' . ($current + 1) . ' of ' . count($questions) ?>
            · <?= $answered ?>/<?= count($questions) ?> answered
        </p>
    </div>
</section>

<div class="progressbar"><span style="width: <?= count($questions) > 0 ? (int) round(($answered / count($questions)) * 100) : 0 ?>%"></span></div>

<?php if ($current === null || $question === null): ?>
    <section class="panel quiz-summary">
        <h2>Score: <?= (int) $attempt['score'] ?> / <?= (int) $attempt['total'] ?></h2>
        <ul class="list">
            <?php foreach ($attempt['feedback'] as $item): ?>
                <li>
                    <span class="pill"><?= $item['correct'] ? 'Correct' : 'Review' ?></span>
                    <span><?= e($item['explanation']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="actions">
            <a class="button" href="/intern/course">Back to course</a>
            <form method="post" action="/intern/artifacts/<?= (int) $artifact['id'] ?>/start">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="primary" type="submit">Retake quiz</button>
            </form>
        </div>
    </section>
<?php else: ?>
    <section class="panel quiz-question">
        <h2><?= e($question['prompt']) ?></h2>
        <form method="post" action="/intern/attempts/<?= (int) $attempt['id'] ?>/answer" class="stack">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="question_index" value="<?= (int) $current ?>">
            <?php foreach ($question['options'] as $index => $option): ?>
                <label class="option">
                    <input type="radio" name="option_index" value="<?= (int) $index ?>" required>
                    <span><?= e($option) ?></span>
                </label>
            <?php endforeach; ?>
            <button class="primary" type="submit">Next</button>
        </form>
    </section>
<?php endif; ?>
