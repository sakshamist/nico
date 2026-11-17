<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Nico Admin</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="surface-admin">
<header class="topbar">
    <a class="brand" href="/admin/interns">Nico<span>.</span></a>
    <nav class="nav">
        <a href="/admin/interns" class="<?= str_starts_with($path, '/admin/interns') ? 'active' : '' ?>">Interns</a>
    </nav>
    <div class="account">
        <span class="pill">Admin</span>
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
    <span>Nico Admin</span>
    <span>Everything an intern needs, nothing they do not.</span>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
