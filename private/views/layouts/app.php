<?php
/**
 * @var string $content
 * @var string|null $title
 * @var string|null $subtitle
 * @var array{type:string,message:string,title:?string}[] $flash
 */
use DynaPulse\Http\Context;

$user = Context::$user;
$appName = (string) Context::config('app.name', 'DynaPulse');
$domainRow = Context::$domainRow;
$flash = flashes();
$title = $title ?? 'Dashboard';
$subtitle = $subtitle ?? '';
$domains = Context::$domains;
$nav = [
    ['/', 'Dashboard', 'grid'],
    ['/calls', 'Calls', 'phone'],
    ['/alerts', 'Alerts', 'bell'],
    ['/keywords', 'Keywords', 'target'],
    ['/recipients', 'Recipients', 'mail'],
    ['/reports', 'Reports', 'chart'],
];
$adminNav = [
    ['/domains', 'Domains', 'layers'],
    ['/users', 'Portal users', 'users'],
    ['/settings', 'Settings', 'cog'],
];
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
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
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h4l2.5-7 4 14L15.5 12H22"/></svg>
            </span>
            <span class="brand__text">
                <strong><?= e($appName) ?></strong>
                <small>Call intelligence</small>
            </span>
        </div>

        <nav class="nav" aria-label="Main">
            <?php foreach ($nav as [$path, $label, $icon]): ?>
                <a href="<?= e(path_url($path)) ?>" class="nav__link <?= active_if($path) ?>">
                    <?= icon($icon) ?>
                    <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($user?->isAdmin()): ?>
            <div class="nav__label">Administration</div>
            <nav class="nav" aria-label="Administration">
                <?php foreach ($adminNav as [$path, $label, $icon]): ?>
                    <a href="<?= e(path_url($path)) ?>" class="nav__link <?= active_if($path) ?>">
                        <?= icon($icon) ?>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="sidebar__foot">
            <div class="sidebar__domain">
                <span class="dot <?= $domainRow === null ? 'dot--all' : '' ?>"></span>
                <span>
                    <small><?= $domainRow === null ? 'Scope' : 'Domain' ?></small>
                    <strong><?= e($domainRow['label'] ?? ($domainRow['domain'] ?? 'All domains')) ?></strong>
                </span>
            </div>
            <small class="muted">v1.0 · <a href="<?= e(path_url('health')) ?>" target="_blank" rel="noopener">health</a></small>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn topbar__menu" type="button" data-sidebar-toggle aria-label="Toggle navigation" aria-expanded="false">
                <?= icon('menu') ?>
            </button>

            <div class="topbar__title">
                <h1><?= e($title) ?></h1>
                <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
            </div>

            <div class="topbar__actions">
                <?php if ($user?->isAdmin() && $domains !== []): ?>
                    <form method="post" action="<?= e(path_url('domains/switch')) ?>" class="domain-switch">
                        <?= csrf_field() ?>
                        <label class="sr-only" for="domain-switch">Active domain</label>
                        <select name="domain_id" id="domain-switch" data-autosubmit>
                            <option value="all" <?= $domainRow === null ? 'selected' : '' ?>>All domains</option>
                            <?php foreach ($domains as $domain): ?>
                                <option value="<?= (int) $domain['id'] ?>" <?= $domainRow !== null && (int) $domainRow['id'] === (int) $domain['id'] ? 'selected' : '' ?>>
                                    <?= e($domain['label'] !== '' ? $domain['label'] : $domain['domain']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>

                <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                    <span class="theme-sun"><?= icon('sun') ?></span>
                    <span class="theme-moon"><?= icon('moon') ?></span>
                </button>

                <?php if ($user !== null): ?>
                    <div class="user-chip" title="<?= e($user->uid()) ?> (<?= e($user->roleLabel()) ?>)">
                        <span class="avatar"><?= e(initials($user->displayName)) ?></span>
                        <span class="user-chip__text">
                            <strong><?= e($user->displayName) ?></strong>
                            <small><?= e($user->roleLabel()) ?></small>
                        </span>
                    </div>
                    <form method="post" action="<?= e(path_url('logout')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--ghost btn--sm">Sign out</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <main class="content" id="main">
            <?= $content ?>
        </main>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="polite" aria-atomic="true"></div>
<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
