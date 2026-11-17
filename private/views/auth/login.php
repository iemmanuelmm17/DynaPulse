<?php
/**
 * @var string $next
 * @var string $login
 */
use DynaPulse\Http\Context;

$appName = (string) Context::config('app.name', 'DynaPulse');
$env = (string) Context::config('app.env', 'production');
?>
<button class="icon-btn login__theme" type="button" data-theme-toggle aria-label="Toggle dark mode">
    <span class="theme-sun"><?= icon('sun') ?></span>
    <span class="theme-moon"><?= icon('moon') ?></span>
</button>

<div class="login">
    <section class="login__art">
        <div class="login__brand">
            <span class="mark"><?= icon('wave') ?></span>
            <strong><?= e($appName) ?></strong>
        </div>

        <h2>Every call, transcribed and watched.</h2>
        <p>
            DynaPulse reads NetSapiens Call Intelligence for your domain, matches your keyword list
            against transcripts, summaries and topics, and emails the people who need to know —
            with the recording one click away.
        </p>

        <ul>
            <li><?= icon('bell') ?><span>Keyword alerts with caller, called number, time, summary and the exact trigger.</span></li>
            <li><?= icon('play') ?><span>Listen to the recording straight from the email or the portal.</span></li>
            <li><?= icon('chart') ?><span>Scheduled reports, CSV/XLSX exports and sentiment trends per domain.</span></li>
        </ul>

        <p style="margin-top:auto;font-size:12px;color:#94a3b8;">
            NetSapiens API v2 · Call Intelligence · Office 365 delivery
        </p>
    </section>

    <section class="login__panel">
        <h1>Sign in</h1>
        <p class="sub">Use your NetSapiens extension or email and password.</p>

        <form method="post" action="<?= e(path_url('login')) ?>" autocomplete="on">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">

            <div class="field">
                <label for="login">Extension or email</label>
                <input type="text" id="login" name="login" value="<?= e($login) ?>"
                       placeholder="2000@acme.com" autocomplete="username" required autofocus>
                <span class="hint">Anything your NetSapiens login accepts. Include the domain when your server needs it.</span>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn--primary btn--block">Sign in</button>
        </form>

        <p class="login__foot">
            Passwords are verified against NetSapiens and never stored by <?= e($appName) ?>.
            <?php if ($env === 'local'): ?>
                <br>Running in <strong>local</strong> mode.
            <?php endif; ?>
        </p>
    </section>
</div>
