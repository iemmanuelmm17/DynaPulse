<?php
/**
 * @var array<string, string> $settings
 * @var array<string, mixed> $mail
 * @var array<string, mixed> $ns
 * @var array<string, mixed> $env
 * @var array<string, mixed> $webhook
 * @var list<array{0:string,1:bool,2:string}> $checks
 * @var list<array<string, mixed>> $domains
 * @var string $logTail
 */

$severities = ['low', 'medium', 'high', 'critical'];
$value = static fn (string $key, string $fallback = ''): string => (string) ($settings[$key] ?? $fallback);
$truthy = static fn (string $key): bool => in_array(strtolower($value($key)), ['1', 'true', 'yes', 'on'], true);
?>
<nav class="settings-rail">
    <a href="#behaviour">Alerting</a>
    <a href="#mail">Outlook email</a>
    <a href="#netsapiens">NetSapiens</a>
    <a href="#ingestion">Ingestion</a>
    <a href="#environment">Environment</a>
</nav>

<div class="stack">
    <form method="post" action="<?= e(path_url('settings')) ?>" id="behaviour">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card__head">
                <h2><?= icon('sliders') ?> Alerting &amp; retention</h2>
                <span class="muted">Saved to the database, no restart needed</span>
            </div>
            <div class="card__body">
                <div class="grid grid--form">
                    <div class="field">
                        <label for="alert_min_severity">Minimum severity to alert</label>
                        <select id="alert_min_severity" name="alert_min_severity">
                            <?php foreach ($severities as $severity): ?>
                                <option value="<?= $severity ?>" <?= $value('alert_min_severity', 'low') === $severity ? 'selected' : '' ?>><?= ucfirst($severity) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Alerts below this severity are matched but not emailed.</span>
                    </div>
                    <div class="field">
                        <label for="dashboard_days">Dashboard window (days)</label>
                        <input type="number" id="dashboard_days" name="dashboard_days" min="1" max="90" value="<?= (int) $value('dashboard_days', '30') ?>">
                    </div>
                    <div class="field">
                        <label for="transcript_max_attempts">Transcript retries</label>
                        <input type="number" id="transcript_max_attempts" name="transcript_max_attempts" min="1" max="30" value="<?= (int) $value('transcript_max_attempts', '8') ?>">
                        <span class="hint">Call Intelligence can take a while; each retry backs off further.</span>
                    </div>
                    <div class="field">
                        <label for="retention_days">Keep calls for (days)</label>
                        <input type="number" id="retention_days" name="retention_days" min="0" max="3650" value="<?= (int) $value('retention_days', '365') ?>">
                        <span class="hint">0 disables pruning. <code>console.php prune</code> applies it.</span>
                    </div>
                    <div class="field">
                        <label for="poll_window_hours">Poll lookback (hours)</label>
                        <input type="number" id="poll_window_hours" name="poll_window_hours" min="1" max="168" value="<?= (int) $value('poll_window_hours', '24') ?>">
                    </div>
                </div>

                <div class="row" style="gap:20px;margin:2px 0 16px;">
                    <label class="check">
                        <input type="checkbox" name="alerts_enabled" value="1" <?= $truthy('alerts_enabled') ? 'checked' : '' ?>>
                        Email alerts enabled
                    </label>
                    <label class="check">
                        <input type="checkbox" name="alert_include_transcript" value="1" <?= $truthy('alert_include_transcript') ? 'checked' : '' ?>>
                        Include the transcript in alert emails
                    </label>
                    <label class="check">
                        <input type="checkbox" name="auto_import_domains" value="1" <?= $truthy('auto_import_domains') ? 'checked' : '' ?>>
                        Auto-import unknown domains from webhook posts
                    </label>
                </div>

                <div class="field">
                    <label for="alert_extra_recipients">Always copy (alerts)</label>
                    <input type="text" id="alert_extra_recipients" name="alert_extra_recipients" value="<?= e($value('alert_extra_recipients')) ?>" placeholder="security@acme.com, noc@acme.com">
                    <span class="hint">Added to every alert email in addition to the domain recipient list.</span>
                </div>
                <div class="field">
                    <label for="default_report_recipients">Default report recipients</label>
                    <input type="text" id="default_report_recipients" name="default_report_recipients" value="<?= e($value('default_report_recipients')) ?>" placeholder="management@acme.com">
                    <span class="hint">Used by report schedules that leave the recipient field blank.</span>
                </div>
            </div>
            <div class="card__foot">
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> Save settings</button>
            </div>
        </div>
    </form>

    <div class="card" id="mail">
        <div class="card__head">
            <h2><?= icon('mail') ?> Outlook SMTP</h2>
            <span class="badge <?= $mail['configured'] ? 'badge--success' : 'badge--danger' ?>">
                <?= $mail['configured'] ? 'configured' : 'incomplete' ?>
            </span>
        </div>
        <div class="card__body">
            <?php if ($mail['missing'] !== []): ?>
                <div class="banner banner--warn" style="margin-bottom:14px;">
                    <span class="banner__icon"><?= icon('alert') ?></span>
                    <div>
                        <strong>Missing configuration</strong>
                        <?= e(implode(', ', $mail['missing'])) ?> — set these in
                        <code><?= e($env['env_file']) ?></code>.
                    </div>
                </div>
            <?php endif; ?>

            <dl class="meta-grid">
                <div><dt>SMTP host</dt><dd class="mono"><?= e((string) $mail['host']) ?>:<?= (int) $mail['port'] ?></dd></div>
                <div><dt>Encryption</dt><dd><?= e(strtoupper((string) $mail['encryption'])) ?></dd></div>
                <div><dt>Sender mailbox</dt><dd><?= e($mail['username'] !== '' ? (string) $mail['username'] : 'not set') ?></dd></div>
                <div><dt>App password</dt><dd><?= $mail['password_set'] ? 'set' : 'missing' ?></dd></div>
                <div><dt>From</dt><dd><?= e((string) $mail['from_name']) ?> &lt;<?= e((string) $mail['from']) ?>&gt;</dd></div>
                <div><dt>Reply-to</dt><dd><?= e($mail['reply_to'] !== '' ? (string) $mail['reply_to'] : 'same as sender') ?></dd></div>
            </dl>

            <p class="hint" style="margin-top:12px;">
                Credentials stay in the environment file so they are never stored in the database or rendered into HTML.
                Edit <code><?= e($env['env_file']) ?></code> and restart the site to change them. Microsoft 365 requires
                <strong>SMTP AUTH</strong> to be enabled on the mailbox and an app password when MFA is on.
            </p>

            <div class="hr"></div>

            <form method="post" action="<?= e(path_url('settings/test-mail')) ?>">
                <?= csrf_field() ?>
                <div class="row" style="align-items:flex-end;">
                    <div class="field field--grow">
                        <label for="test_to">Send a test message to</label>
                        <input type="email" id="test_to" name="email" required value="<?= e((string) $mail['username']) ?>">
                    </div>
                    <button type="submit" class="btn btn--subtle" <?= $mail['configured'] ? '' : 'disabled' ?>><?= icon('send') ?> Send test</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" id="netsapiens">
        <div class="card__head">
            <h2><?= icon('cloud') ?> NetSapiens API</h2>
            <span class="badge <?= $ns['api_key_set'] && $ns['client_secret_set'] ? 'badge--success' : 'badge--danger' ?>">
                <?= $ns['api_key_set'] && $ns['client_secret_set'] ? 'configured' : 'incomplete' ?>
            </span>
        </div>
        <div class="card__body">
            <dl class="meta-grid">
                <div><dt>Base URL</dt><dd class="mono truncate"><?= e((string) $ns['base_url']) ?></dd></div>
                <div><dt>Client id</dt><dd class="mono"><?= e($ns['client_id'] !== '' ? (string) $ns['client_id'] : 'not set') ?></dd></div>
                <div><dt>Client secret</dt><dd><?= $ns['client_secret_set'] ? 'set' : 'missing' ?></dd></div>
                <div><dt>API key</dt><dd><?= $ns['api_key_set'] ? 'set' : 'missing' ?></dd></div>
                <div><dt>HTTP timeout</dt><dd><?= (int) $ns['timeout'] ?>s</dd></div>
                <div><dt>Poll overlap</dt><dd><?= (int) $ns['lookback'] ?> min</dd></div>
            </dl>
            <p class="hint" style="margin-top:12px;">
                Manager sign-in is validated with <code>POST /tokens</code> using these OAuth credentials; the domain comes
                back on the token. Recordings, transcripts and CDRs are read with the API key. All four values live in
                <code><?= e($env['env_file']) ?></code>.
            </p>
        </div>
    </div>

    <div class="card" id="ingestion">
        <div class="card__head">
            <h2><?= icon('bolt') ?> Ingestion</h2>
            <span class="badge <?= $webhook['subscription'] !== null ? 'badge--success' : 'badge--warning' ?>">
                <?= $webhook['subscription'] !== null ? 'subscribed' : 'polling only' ?>
            </span>
        </div>
        <div class="card__body">
            <div class="field">
                <label for="webhook_url">Webhook endpoint</label>
                <div class="row">
                    <input type="text" id="webhook_url" value="<?= e((string) $webhook['url']) ?>" readonly class="mono" style="font-size:11.5px;">
                    <button type="button" class="btn btn--ghost" data-copy="#webhook_url"><?= icon('copy') ?> Copy</button>
                </div>
                <span class="hint">Use this as <code>post-url</code>. The last path segment is the shared secret (<code>WEBHOOK_SECRET</code>); posts without it get a 404.</span>
            </div>

            <div class="hr"></div>

            <?php if ($webhook['subscription'] !== null): ?>
                <div class="row" style="justify-content:space-between;align-items:flex-start;">
                    <div>
                        <div class="label">Active CDR subscription</div>
                        <div class="muted" style="font-size:12.5px;margin-top:4px;">
                            <span class="mono"><?= e((string) ($webhook['subscription']['id'] ?? '')) ?></span> ·
                            status <?= e((string) ($webhook['subscription']['status'] ?? 'unknown')) ?> ·
                            <?= number_format((int) ($webhook['subscription']['posts-count'] ?? 0)) ?> posts ·
                            <?= number_format((int) ($webhook['subscription']['error-count'] ?? 0)) ?> errors
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row" style="justify-content:space-between;align-items:flex-start;">
                    <div>
                        <div class="label">No cdr subscription</div>
                        <div class="<?= $webhook['error'] !== null ? 'text-danger' : 'muted' ?>" style="font-size:12.5px;margin-top:4px;">
                            <?= e((string) ($webhook['error'] ?? 'Calls currently arrive only through the scheduled poller.')) ?>
                        </div>
                    </div>
                    <form method="post" action="<?= e(path_url('settings/webhook')) ?>"
                          data-confirm="Create a NetSapiens cdr subscription that posts to this endpoint?">
                        <?= csrf_field() ?>
                        <?php if ($domains !== []): ?>
                            <select name="scope" aria-label="Subscription scope" style="margin-bottom:8px;">
                                <option value="all">All domains</option>
                                <?php foreach ($domains as $domain): ?>
                                    <option value="<?= e((string) $domain['domain']) ?>"><?= e($domain['label'] ?: $domain['domain']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--subtle btn--sm"><?= icon('bolt') ?> Create subscription</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="hr"></div>

            <div class="grid grid--2">
                <div>
                    <div class="label" style="margin-bottom:6px;">Scheduled jobs</div>
                    <pre class="log-tail" style="max-height:none;">php private/bin/console.php poll
php private/bin/console.php process
php private/bin/console.php notify
php private/bin/console.php reports:run
php private/bin/console.php prune</pre>
                </div>
                <div>
                    <div class="label" style="margin-bottom:6px;">Task Scheduler</div>
                    <p class="hint" style="margin:0;">
                        Run <code>poll</code> + <code>process</code> every 5 minutes, <code>notify</code> every minute,
                        <code>reports:run</code> every 15 minutes and <code>prune</code> once a night. Each command is
                        idempotent, so overlapping runs are safe.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="environment">
        <div class="card__head"><h2><?= icon('shield') ?> Environment health</h2></div>
        <div class="card__body">
            <div class="grid grid--2">
                <div>
                    <div class="label" style="margin-bottom:8px;">Pre-flight checks</div>
                    <ul class="notice-list">
                        <?php foreach ($checks as $check): ?>
                            <li>
                                <?= icon($check[1] ? 'check' : 'alert') ?>
                                <span class="<?= $check[1] ? '' : 'text-warn' ?>">
                                    <strong><?= e($check[0]) ?></strong>
                                    <?php if ($check[1]): ?>ok<?php else: ?><?= e($check[2]) ?><?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <div class="label" style="margin-bottom:8px;">Paths</div>
                    <dl style="margin:0;font-size:12px;line-height:1.9;">
                        <div class="row row--between"><dt class="muted">Environment</dt><dd class="truncate" style="max-width:250px;"><?= e((string) $env['env_file']) ?></dd></div>
                        <div class="row row--between"><dt class="muted">Database</dt><dd class="truncate" style="max-width:250px;"><?= e((string) $env['database']) ?></dd></div>
                        <div class="row row--between"><dt class="muted">Storage</dt><dd class="truncate" style="max-width:250px;"><?= e((string) $env['storage']) ?></dd></div>
                        <div class="row row--between"><dt class="muted">PHP</dt><dd><?= e((string) $env['php']) ?></dd></div>
                    </dl>
                    <p class="hint" style="margin-top:12px;">
                        Prune from the CLI: <code>php private/bin/console.php prune</code>.
                    </p>
                </div>
            </div>

            <div class="hr"></div>
            <div class="label" style="margin-bottom:8px;">Ingestion</div>
            <p class="hint" style="margin:0 0 10px;">
                Newest call in the database:
                <strong><?= e((string) ($ingestion['latest_call'] ?? '') !== '' ? tz_date((string) $ingestion['latest_call'], 'M j, Y g:i A') : 'none yet') ?></strong>.
                Calls arrive through the NetSapiens <em>cdr</em> subscription or the scheduled poller — with
                neither running this list stays empty even when calls exist in NetSapiens.
            </p>

            <?php if (($ingestion['domains'] ?? []) === []): ?>
                <p class="hint">
                    No domains yet — import them under <a href="<?= e(path_url('domains')) ?>">Domains</a>
                    (“Sync from NetSapiens”), then run the poller; without a domain there is nothing to query.
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Domain</th><th>Polling</th><th class="right">Calls</th><th>Last poll</th><th>Last error</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ingestion['domains'] as $domain): ?>
                            <tr>
                                <td class="strong"><?= e((string) $domain['domain']) ?></td>
                                <td>
                                    <?php if ((int) ($domain['enabled'] ?? 0) !== 1): ?>
                                        <span class="badge badge--warning">disabled</span>
                                    <?php elseif ((int) ($domain['poll_enabled'] ?? 0) !== 1): ?>
                                        <span class="badge badge--warning">polling off</span>
                                    <?php else: ?>
                                        <span class="badge badge--success">on</span>
                                    <?php endif; ?>
                                </td>
                                <td class="right"><?= number_format((int) ($domain['call_count'] ?? 0)) ?></td>
                                <td class="nowrap muted"><?= e(((string) ($domain['last_polled_at'] ?? '')) !== '' ? tz_date((string) $domain['last_polled_at'], 'M j, g:i A') : 'never') ?></td>
                                <td class="muted"><?= e(((string) ($domain['last_poll_error'] ?? '')) !== '' ? (string) $domain['last_poll_error'] : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="hint" style="margin-top:10px;">
                Catch up from a Command Prompt, in this order:
                <code>php bin\console.php ns:test</code> &rarr;
                <code>php bin\console.php domains:sync</code> &rarr;
                <code>php bin\console.php poll 72</code> &rarr;
                <code>php bin\console.php process</code>.
            </p>

            <div class="hr"></div>
            <details class="panel" style="border:0;background:transparent;">
                <summary style="padding-left:0;"><?= icon('file') ?> Latest log entries — <?= e((string) $env['log_file']) ?></summary>
                <div class="panel__body" style="padding-left:0;">
                    <pre class="log-tail"><?= e($logTail !== '' ? $logTail : 'No log entries yet.') ?></pre>
                </div>
            </details>
        </div>
    </div>
</div>
