<?php
/**
 * @var int $status
 * @var string $message
 */
$isLoggedIn = \DynaPulse\Http\Context::$user !== null;
$hints = [
    403 => 'Your account does not have permission for that action. Ask an administrator if you need access.',
    404 => 'The page may have been removed, or the call or domain no longer exists.',
    405 => 'That request method is not supported here.',
    419 => 'Your session expired for security reasons. Sign in again and retry.',
    429 => 'Too many attempts. Wait a minute before trying again.',
    500 => 'Keep this code handy when checking the log: <code>private/storage/logs/app.log</code>.',
];
?>
<div class="error-page">
    <div class="error-code"><?= (int) $status ?></div>
    <h1><?= e($title ?? 'Request failed') ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <p class="hint" style="max-width:46ch;margin:10px auto 0;"><?= $hints[$status] ?? 'If this keeps happening, contact your DynaPulse administrator.' ?></p>
    <div class="row" style="justify-content:center;margin-top:22px;">
        <a class="btn btn--primary" href="<?= e(path_url('')) ?>"><?= icon('home') ?> Back to dashboard</a>
        <?php if (!$isLoggedIn): ?>
            <a class="btn btn--ghost" href="<?= e(path_url('login')) ?>">Sign in</a>
        <?php endif; ?>
    </div>
</div>
