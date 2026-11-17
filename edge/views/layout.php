<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Nico</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/app">Nico<span>.</span></a>
    <?php if ($user !== null): ?>
        <nav class="nav">
            <a href="/app">Dashboard</a>
            <a href="/app/projects">Projects</a>
            <a href="/app/library">Library</a>
            <a href="/app/profile">Profile</a>
        </nav>
        <div class="account">
            <span class="muted"><?= e($user['email']) ?></span>
            <span class="pill"><?= e($user['role']) ?></span>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="link" type="submit">Sign out</button>
            </form>
        </div>
    <?php endif; ?>
</header>
<main class="container">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="footer">
    <span>Nico</span>
    <span>Learn, build, and track progress.</span>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
