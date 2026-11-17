<?php
/** @var array{user_id:int,max_open_tasks:int,availability:string} $profile */
/** @var array<string,int> $capabilities */
?>
<section class="page-head">
    <div>
        <p class="eyebrow">M5 people</p>
        <h1>Your capability profile</h1>
        <p class="muted">M5 uses these capabilities, availability, and concurrency cap to match tasks.</p>
    </div>
</section>

<div class="grid two">
    <section class="panel">
        <h2>Workload</h2>
        <form method="post" action="/app/profile" class="stack">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Max open tasks
                <input type="number" min="1" max="100" name="max_open_tasks" value="<?= (int) $profile['max_open_tasks'] ?>" required>
            </label>
            <label>Availability
                <select name="availability">
                    <?php foreach (['available', 'busy', 'away'] as $option): ?>
                        <option value="<?= e($option) ?>" <?= $profile['availability'] === $option ? 'selected' : '' ?>>
                            <?= e(ucfirst($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="primary" type="submit">Save workload</button>
        </form>
    </section>

    <section class="panel">
        <h2>Capabilities</h2>
        <?php if ($capabilities === []): ?>
            <p class="empty">No capabilities yet. Add tags such as php, design, or robotics.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($capabilities as $tag => $level): ?>
                    <li>
                        <span><?= e($tag) ?> <span class="pill">level <?= (int) $level ?></span></span>
                        <form method="post" action="/app/profile/capabilities/delete" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="tag" value="<?= e($tag) ?>">
                            <button class="link" type="submit">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="/app/profile/capabilities" class="stack compact-top">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Tag <input name="tag" required maxlength="64" placeholder="robotics"></label>
            <label>Level
                <select name="level">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>"><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button class="primary" type="submit">Add capability</button>
        </form>
    </section>
</div>
