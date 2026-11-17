<?php /** @var string|null $error */ ?>
<section class="auth-card">
    <h1>Welcome back</h1>
    <p class="muted">Sign in to the Nico learning workspace.</p>
    <?php if ($error !== null): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Email
            <input type="email" name="email" required autocomplete="email" autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="primary" type="submit">Sign in</button>
    </form>
    <p class="muted">No account? <a href="/register">Create one</a>.</p>
</section>
