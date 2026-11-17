<section class="page-head">
    <div>
        <p class="eyebrow">Messages</p>
        <h1>Your conversation</h1>
        <p class="muted">Talk with your admin here.</p>
    </div>
</section>

<section class="panel">
    <?php if ($messages === []): ?>
        <p class="empty">No messages yet. Say hello.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($messages as $message): ?>
                <li>
                    <strong><?= (int) $message['sender_id'] === (int) $user['id'] ? 'You' : 'Admin' ?></strong>
                    <span class="muted">· <?= e($message['created_at']) ?></span>
                    <p><?= nl2br(e($message['body'])) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel narrow">
    <h2>Write a message</h2>
    <form method="post" action="/intern/messages" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Message <textarea name="body" rows="5" required></textarea></label>
        <button class="primary" type="submit">Send</button>
    </form>
</section>
