<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Nico</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="surface-intern">
<header class="topbar">
    <a class="brand" href="/intern">Nico<span>.</span></a>
    <nav class="nav">
        <a href="/intern" class="<?= str_starts_with($path, '/intern') && $path !== '/intern/course' ? 'active' : '' ?>">Home</a>
        <a href="/intern/course" class="<?= str_starts_with($path, '/intern/course') ? 'active' : '' ?>">Course</a>
        <a href="/intern/messages" class="<?= str_starts_with($path, '/intern/messages') ? 'active' : '' ?>">Messages</a>
        <a href="/intern/projects" class="<?= str_starts_with($path, '/intern/projects') ? 'active' : '' ?>">Projects</a>
    </nav>
    <div class="account">
        <span class="pill">Intern</span>
        <span class="muted"><?= e($user['email']) ?></span>
        <form method="post" action="/logout" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="link" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main class="container">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="footer">
    <span>Nico</span>
    <span>Learn one step at a time.</span>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
