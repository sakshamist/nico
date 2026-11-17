<?php /** @var string|null $error */ ?>
<section class="auth-card">
    <h1>Create account</h1>
    <p class="muted">Start with a project, add notes, and generate a lesson or quiz.</p>
    <?php if ($error !== null): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/register" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Email
            <input type="email" name="email" required autocomplete="email" autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label>Role
            <select name="role">
                <option value="student">Student</option>
                <option value="teacher">Teacher</option>
            </select>
        </label>
        <button class="primary" type="submit">Create account</button>
    </form>
    <p class="muted">Already registered? <a href="/login">Sign in</a>.</p>
</section>
