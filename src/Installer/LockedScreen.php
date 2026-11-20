<?php

declare(strict_types=1);

// Only included by public/index.php; it is never a standalone HTTP entry point.
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Adult Core · <?= $escape($heading) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body><div class="shell">
    <header class="topbar"><div class="mark">AC</div><span>Adult Core</span><span class="version">Secure installation gate</span></header>
    <main><section class="notice"><h1><?= $escape($heading) ?></h1><p><?= $escape($message) ?></p></section></main>
    <footer>Adult Core · afterburn25/Adult_Core</footer>
</div></body>
</html>
