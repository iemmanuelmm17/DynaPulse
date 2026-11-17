<?php
/** @var string $content */
use DynaPulse\Http\Context;

$appName = (string) Context::config('app.name', 'DynaPulse');
$flash = flashes();
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title><?= e($title ?? 'Sign in') ?> · <?= e($appName) ?></title>
    <link rel="icon" href="<?= e(path_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
            try {
                var stored = localStorage.getItem('dp-theme');
                var theme = stored || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                document.documentElement.dataset.theme = theme;
            } catch (e) {}
        })();
    </script>
</head>
<body class="body--minimal">
<?= $content ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
