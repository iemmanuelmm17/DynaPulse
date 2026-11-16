<?php
/**
 * @var list<array<string, mixed>> $domains
 * @var list<string> $timezones
 * @var string $defaultTimezone
 * @var array<string, mixed>|null $subscription
 * @var string|null $subscriptionError
 */
use DynaPulse\Support\Time;
?>
<div class="row" style="justify-content:space-between;margin-bottom:14px;">
    <div class="muted">Each domain gets its own manager portal, keywords, recipients and reports.</div>
    <form method="post" action="<?= e(path_url('domains/sync')) ?>" data-confirm="Import any domains that exist in NetSapiens but not in DynaPulse?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost btn--sm"><?= icon('refresh') ?> Sync from NetSapiens</button>
    </form>
</div>

<?php if ($subscription === null): ?>
    <div class="banner <?= $subscriptionError !== null ? 'banner--danger' : 'banner--warn' ?>">
        <span class="banner__icon"><?= icon('alert') ?></span>
        <div>
            <strong><?= $subscriptionError !== null ? 'Could not read NetSapiens subscriptions' : 'No CDR event subscription yet' ?></strong>
            <?= e($subscriptionError ?? 'Without it, completed calls arrive only through the scheduled poller. Create it from Settings → Ingestion.') ?>
            <a href="<?= e(path_url('settings')) ?>">Open Settings</a>
        </div>
    </div>
<?php endif; ?>

<details class="panel" <?= $domains === [] ? 'open' : '' ?>>
    <summary><?= icon('plus') ?> Add domain</summary>
    <div class="panel__body">
        <form method="post" action="<?= e(path_url('domains')) ?>">
            <?= csrf_field() ?>
            <div class="grid grid--form">
                <div class="field">
                    <label for="domain">NetSapiens domain</label>
                    <input type="text" id="domain" name="domain" required placeholder="acme.com" maxlength="64">
                </div>
                <div class="field">
                    <label for="label">Display label</label>
                    <input type="text" id="label" name="label" maxlength="90" placeholder="Acme Corporation">
                </div>
            </div>
            <div class="field" style="max-width:380px;">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone">
                    <?php foreach ($timezones as $timezone): ?>
                        <option value="<?= e($timezone) ?>" <?= $timezone === $defaultTimezone ? 'selected' : '' ?>><?= e($timezone) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Used for every timestamp and CDR query for this domain.</span>
            </div>
            <button type="submit" class="btn btn--primary"><?= icon('plus') ?> Add domain</button>
        </form>
    </div>
</details>

<div class="card">
    <div class="card__head"><h2>Monitored domains</h2><span class="muted"><?= count($domains) ?> configured</span></div>
    <?php if ($domains === []): ?>
        <div class="empty">
            <div class="empty__icon"><?= icon('layers') ?></div>
            <h3>No domains yet</h3>
            <p>Add the first domain above, or pull the list straight from NetSapiens. Managers can sign in as soon as their domain exists.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Domain</th>
                    <th>Timezone</th>
                    <th class="num">Calls</th>
                    <th class="num">Alerts</th>
                    <th class="num">Recipients</th>
                    <th class="num">Keywords</th>
                    <th>Monitoring</th>
                    <th>Polling</th>
                    <th>Last poll</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($domains as $domain): ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <strong><?= e($domain['label'] ?: $domain['domain']) ?></strong>
                                <small><?= e($domain['domain']) ?></small>
                            </div>
                        </td>
                        <td class="muted"><?= e($domain['timezone']) ?></td>
                        <td class="num"><?= number_format((int) $domain['stats']['calls']) ?></td>
                        <td class="num"><?= number_format((int) $domain['stats']['alerts']) ?></td>
                        <td class="num"><?= number_format((int) $domain['stats']['recipients']) ?></td>
                        <td class="num"><?= number_format((int) $domain['stats']['keywords']) ?></td>
                        <td>
                            <form method="post" action="<?= e(path_url('domains/' . (int) $domain['id'] . '/toggle')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="field" value="enabled">
                                <button type="submit" class="switch" aria-pressed="<?= (int) $domain['enabled'] === 1 ? 'true' : 'false' ?>" aria-label="Toggle monitoring"></button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="<?= e(path_url('domains/' . (int) $domain['id'] . '/toggle')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="field" value="poll_enabled">
                                <button type="submit" class="switch" aria-pressed="<?= (int) $domain['poll_enabled'] === 1 ? 'true' : 'false' ?>" aria-label="Toggle polling"></button>
                            </form>
                        </td>
                        <td class="muted nowrap">
                            <?= e(Time::relative($domain['last_polled_at'] ?? null)) ?>
                            <?php if (($domain['last_poll_error'] ?? '') !== ''): ?>
                                <div class="text-danger" style="font-size:11px;max-width:180px;"><?= e(\DynaPulse\Support\Str::limit((string) $domain['last_poll_error'], 80)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="right">
                            <form method="post" action="<?= e(path_url('domains/' . (int) $domain['id'] . '/delete')) ?>" class="inline-form"
                                  data-confirm="Delete <?= e($domain['domain']) ?>? Its stored calls, keywords, recipients and schedules will be removed.">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid--2" style="margin-top:16px;">
    <div class="card">
        <div class="card__head"><h2>Event subscription</h2></div>
        <div class="card__body">
            <?php if ($subscription !== null): ?>
                <dl style="margin:0;font-size:12.5px;line-height:1.9;">
                    <div class="row row--between"><dt class="muted">ID</dt><dd class="mono"><?= e((string) ($subscription['id'] ?? '—')) ?></dd></div>
                    <div class="row row--between"><dt class="muted">Model</dt><dd><?= e((string) ($subscription['model'] ?? 'cdr')) ?></dd></div>
                    <div class="row row--between"><dt class="muted">Domain</dt><dd><?= e((string) ($subscription['domain'] ?? '*')) ?></dd></div>
                    <div class="row row--between"><dt class="muted">Posts</dt><dd><?= number_format((int) ($subscription['posts-count'] ?? 0)) ?></dd></div>
                    <div class="row row--between"><dt class="muted">Errors</dt><dd class="<?= (int) ($subscription['error-count'] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= number_format((int) ($subscription['error-count'] ?? 0)) ?></dd></div>
                    <div class="row row--between"><dt class="muted">Status</dt><dd><?= e((string) ($subscription['status'] ?? 'unknown')) ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="muted" style="margin:0;font-size:12.5px;">
                    No active subscription found. Create one under
                    <a href="<?= e(path_url('settings')) ?>">Settings</a> so completed calls are pushed instantly.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2>Ingestion</h2></div>
        <div class="card__body">
            <ul class="notice-list">
                <li><?= icon('check') ?><span><strong>Webhook</strong> — one POST per completed call from the CDR subscription.</span></li>
                <li><?= icon('check') ?><span><strong>Poller</strong> — queries <code>/domains/&#123;domain&#125;/cdrs</code> every run as a safety net.</span></li>
                <li><?= icon('clock') ?><span>Both write to the same queue; duplicates are merged on the NetSapiens call id.</span></li>
            </ul>
        </div>
    </div>
</div>
