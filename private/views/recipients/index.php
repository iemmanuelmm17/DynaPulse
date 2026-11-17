<?php
/**
 * @var list<array<string, mixed>> $recipients
 * @var bool $canManage
 * @var list<array<string, mixed>> $domains
 * @var int|null $domainId
 */
?>
<?php if ($canManage): ?>
    <details class="panel" <?= $recipients === [] ? 'open' : '' ?>>
        <summary><?= icon('plus') ?> Add recipient</summary>
        <div class="panel__body">
            <form method="post" action="<?= e(path_url('recipients')) ?>">
                <?= csrf_field() ?>
                <div class="grid grid--form">
                    <div class="field">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" required placeholder="manager@acme.com">
                    </div>
                    <div class="field">
                        <label for="name">Name (optional)</label>
                        <input type="text" id="name" name="name" maxlength="90" placeholder="Dana from support">
                    </div>
                </div>

                <?php if ($domainId === null && $domains !== []): ?>
                    <div class="field" style="max-width:340px;">
                        <label for="domain_id">Domain</label>
                        <select id="domain_id" name="domain_id" required>
                            <?php foreach ($domains as $domain): ?>
                                <option value="<?= (int) $domain['id'] ?>"><?= e($domain['label'] ?: $domain['domain']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="row" style="gap:18px;">
                    <label class="check">
                        <input type="checkbox" name="receive_alerts" value="1" checked>
                        Keyword alert emails
                    </label>
                    <label class="check">
                        <input type="checkbox" name="receive_reports" value="1" checked>
                        Scheduled report emails
                    </label>
                </div>

                <div class="row" style="margin-top:16px;">
                    <button type="submit" class="btn btn--primary"><?= icon('plus') ?> Add recipient</button>
                </div>
            </form>
        </div>
    </details>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <h2>Notification list</h2>
        <span class="muted"><?= count($recipients) ?> address(es)</span>
    </div>

    <?php if ($recipients === []): ?>
        <div class="empty">
            <div class="empty__icon"><?= icon('mail') ?></div>
            <h3>No recipients yet</h3>
            <p>Alerts are only emailed when at least one active recipient is subscribed. Add the managers who should react to flagged calls.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Recipient</th>
                    <?php if ($domainId === null): ?><th>Domain</th><?php endif; ?>
                    <th>Alerts</th>
                    <th>Reports</th>
                    <th>Status</th>
                    <?php if ($canManage): ?><th></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recipients as $recipient): ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <strong><?= e((string) $recipient['email']) ?></strong>
                                <?php if (($recipient['name'] ?? '') !== ''): ?><small><?= e((string) $recipient['name']) ?></small><?php endif; ?>
                            </div>
                        </td>
                        <?php if ($domainId === null): ?>
                            <td class="muted"><?= e((string) ($recipient['domain_name'] ?? '')) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($canManage): ?>
                                <form method="post" action="<?= e(path_url('recipients/' . (int) $recipient['id'] . '/toggle')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="field" value="receive_alerts">
                                    <button type="submit" class="switch" aria-pressed="<?= (int) $recipient['receive_alerts'] === 1 ? 'true' : 'false' ?>" aria-label="Toggle alert emails"></button>
                                </form>
                            <?php else: ?>
                                <span class="badge <?= (int) $recipient['receive_alerts'] === 1 ? 'badge--success' : '' ?>"><?= (int) $recipient['receive_alerts'] === 1 ? 'on' : 'off' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canManage): ?>
                                <form method="post" action="<?= e(path_url('recipients/' . (int) $recipient['id'] . '/toggle')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="field" value="receive_reports">
                                    <button type="submit" class="switch" aria-pressed="<?= (int) $recipient['receive_reports'] === 1 ? 'true' : 'false' ?>" aria-label="Toggle report emails"></button>
                                </form>
                            <?php else: ?>
                                <span class="badge <?= (int) $recipient['receive_reports'] === 1 ? 'badge--success' : '' ?>"><?= (int) $recipient['receive_reports'] === 1 ? 'on' : 'off' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canManage): ?>
                                <form method="post" action="<?= e(path_url('recipients/' . (int) $recipient['id'] . '/toggle')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="field" value="active">
                                    <button type="submit" class="switch" aria-pressed="<?= (int) $recipient['active'] === 1 ? 'true' : 'false' ?>" aria-label="Toggle recipient status"></button>
                                </form>
                            <?php else: ?>
                                <span class="status-line"><span class="status-dot <?= (int) $recipient['active'] === 1 ? 'status-dot--ok' : '' ?>"></span><?= (int) $recipient['active'] === 1 ? 'active' : 'disabled' ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td class="right">
                                <form method="post" action="<?= e(path_url('recipients/' . (int) $recipient['id'] . '/delete')) ?>" class="inline-form"
                                      data-confirm="Remove <?= e((string) $recipient['email']) ?> from the list?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--danger btn--sm">Remove</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="banner banner--info" style="margin-top:14px;">
    <span class="banner__icon"><?= icon('shield') ?></span>
    <div>
        <strong>One email per call, not per keyword</strong>
        When a call triggers several keywords, DynaPulse groups them into a single alert so inboxes stay readable.
        Global fallback recipients can be added under <a href="<?= e(path_url('settings')) ?>">Settings</a>.
    </div>
</div>
