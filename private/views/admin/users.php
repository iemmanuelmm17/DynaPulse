<?php
/**
 * @var list<array<string, mixed>> $users
 * @var list<string> $roles
 * @var int $currentUserId
 */
?>
<div class="banner banner--info">
    <span class="banner__icon"><?= icon('shield') ?></span>
    <div>
        <strong>Portal access only</strong>
        Accounts are created automatically the first time someone signs in with their NetSapiens credentials.
        Passwords are never stored here — this page only controls the role and whether the portal account is active.
    </div>
</div>

<div class="card">
    <div class="card__head"><h2>Users</h2><span class="muted"><?= count($users) ?> account(s)</span></div>
    <?php if ($users === []): ?>
        <div class="empty">
            <div class="empty__icon"><?= icon('users') ?></div>
            <h3>No portal users yet</h3>
            <p>The first successful sign-in becomes the administrator. After that, every NetSapiens user who signs in is provisioned as a manager of their own domain.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>User</th>
                    <th>Domain</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th>Last login</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $account): ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <strong><?= e((string) ($account['display_name'] ?: $account['username'])) ?></strong>
                                <small><?= e((string) $account['username']) ?><?= (int) $account['id'] === $currentUserId ? ' · you' : '' ?></small>
                            </div>
                        </td>
                        <td class="muted"><?= e((string) $account['domain']) ?></td>
                        <td class="muted"><?= e((string) ($account['email'] ?: '—')) ?></td>
                        <td colspan="4">
                            <form method="post" action="<?= e(path_url('users/' . (int) $account['id'])) ?>" class="row" style="gap:8px;">
                                <?= csrf_field() ?>
                                <select name="role" aria-label="Role" style="width:130px;">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= e($role) ?>" <?= $account['role'] === $role ? 'selected' : '' ?>><?= ucfirst($role) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="check" style="font-size:12px;">
                                    <input type="checkbox" name="active" value="1" <?= (int) $account['active'] === 1 ? 'checked' : '' ?>>
                                    Active
                                </label>
                                <button type="submit" class="btn btn--ghost btn--sm">Save</button>
                                <span class="muted" style="font-size:11.5px;">last login <?= e($account['last_login_at'] ? \DynaPulse\Support\Time::relative((string) $account['last_login_at']) : 'never') ?></span>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head"><h2>Roles</h2></div>
    <div class="card__body">
        <div class="grid grid--3">
            <div>
                <div class="label">Administrator</div>
                <p class="hint" style="margin-top:4px;">All domains, domain switcher, keywords for every domain, portal users, SMTP and ingestion settings.</p>
            </div>
            <div>
                <div class="label">Manager</div>
                <p class="hint" style="margin-top:4px;">Their own domain only: calls, recordings, alerts, keywords, recipients and report schedules.</p>
            </div>
            <div>
                <div class="label">Viewer</div>
                <p class="hint" style="margin-top:4px;">Read-only access to calls, alerts and reports for their domain. No changes to keywords or recipients.</p>
            </div>
        </div>
    </div>
</div>
