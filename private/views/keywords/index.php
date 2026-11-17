<?php
/**
 * @var list<array<string, mixed>> $keywords
 * @var array<string, mixed>|null $editing
 * @var bool $canManage
 * @var list<array<string, mixed>> $domains
 * @var int|null $domainId
 * @var list<string> $matchTypes
 * @var list<string> $fields
 * @var list<string> $severities
 */
use DynaPulse\Http\Context;

$action = $editing !== null ? path_url('keywords/' . (int) $editing['id']) : path_url('keywords');
$selectedFields = $editing['fields'] ?? ['topics'];
$fieldLabels = [
    'topics' => 'Topics (recommended)',
    'summary' => 'Summary',
    'transcript' => 'Full transcript',
    'caller_number' => 'Caller number',
    'called_number' => 'Called number',
];
?>
<?php if ($canManage): ?>
    <details class="panel" <?= $editing !== null ? 'open' : '' ?>>
        <summary><?= icon($editing !== null ? 'refresh' : 'plus') ?> <?= $editing !== null ? 'Edit keyword' : 'Add a keyword' ?></summary>
        <div class="panel__body">
            <form method="post" action="<?= e($action) ?>">
                <?= csrf_field() ?>
                <div class="grid grid--form">
                    <div class="field">
                        <label for="label">Label</label>
                        <input type="text" id="label" name="label" required maxlength="80"
                               value="<?= e($editing['label'] ?? '') ?>" placeholder="Refund request">
                        <span class="hint">How this trigger is named in alerts and reports.</span>
                    </div>
                    <div class="field">
                        <label for="pattern">Pattern to match</label>
                        <input type="text" id="pattern" name="pattern" required maxlength="300"
                               value="<?= e($editing['pattern'] ?? '') ?>" placeholder="refund, cancel my order, chargeback">
                        <span class="hint">A phrase, a single word, or a regular expression.</span>
                    </div>
                    <div class="field">
                        <label for="match_type">Match type</label>
                        <select id="match_type" name="match_type">
                            <option value="phrase" <?= ($editing['match_type'] ?? '') === 'phrase' ? 'selected' : '' ?>>Phrase (anywhere in the text)</option>
                            <option value="word" <?= ($editing['match_type'] ?? '') === 'word' ? 'selected' : '' ?>>Whole word only</option>
                            <option value="regex" <?= ($editing['match_type'] ?? '') === 'regex' ? 'selected' : '' ?>>Regular expression</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="severity">Severity</label>
                        <select id="severity" name="severity">
                            <?php foreach ($severities as $severity): ?>
                                <option value="<?= $severity ?>" <?= ($editing['severity'] ?? 'medium') === $severity ? 'selected' : '' ?>>
                                    <?= ucfirst($severity) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Alerts below the minimum severity in Settings are skipped.</span>
                    </div>
                </div>

                <div class="field">
                    <span class="label">Where to look</span>
                    <div class="row" style="gap:16px;">
                        <?php foreach ($fields as $field): ?>
                            <label class="check">
                                <input type="checkbox" name="fields[]" value="<?= e($field) ?>"
                                    <?= in_array($field, $selectedFields, true) ? 'checked' : '' ?>>
                                <?= e($fieldLabels[$field] ?? $field) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="hint">
                        Keywords are compared against the Call Intelligence topics by default — the tags
                        NetSapiens extracted for the call. Tick Summary to search the narrative as well; leave
                        all boxes clear and Settings decides (topics, plus the summary when enabled there).
                    </span>
                </div>

                <div class="grid grid--form">
                    <div class="field">
                        <label class="check">
                            <input type="checkbox" name="case_sensitive" value="1" <?= (int) ($editing['case_sensitive'] ?? 0) === 1 ? 'checked' : '' ?>>
                            Case sensitive
                        </label>
                    </div>
                    <?php if (Context::can('admin')): ?>
                        <div class="field">
                            <label for="scope">Applies to</label>
                            <?php if ($domainId === null): ?>
                                <input type="hidden" name="scope" value="global">
                                <input type="text" value="All domains (global)" readonly disabled>
                                <span class="hint">Switch to a specific domain in the top bar to scope a keyword to it.</span>
                            <?php else: ?>
                                <select id="scope" name="scope">
                                    <option value="domain">Current domain only</option>
                                    <option value="global">All domains (global)</option>
                                </select>
                                <span class="hint">Global keywords apply everywhere and are managed by admins.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="notes">Notes (optional)</label>
                    <input type="text" id="notes" name="notes" maxlength="200" value="<?= e($editing['notes'] ?? '') ?>"
                           placeholder="Why this matters, who acts on it…">
                </div>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= icon('check') ?> <?= $editing !== null ? 'Save changes' : 'Add keyword' ?></button>
                    <?php if ($editing !== null): ?>
                        <a class="btn btn--ghost" href="<?= e(path_url('keywords')) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </details>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <h2>Watched keywords</h2>
        <span class="muted"><?= count($keywords) ?> configured</span>
    </div>

    <?php if ($keywords === []): ?>
        <div class="empty">
            <div class="empty__icon"><?= icon('target') ?></div>
            <h3>No keywords yet</h3>
            <p>Add words or phrases that should raise a flag — refunds, cancellations, churn risk, compliance phrases, anything.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Keyword</th>
                    <th>Pattern</th>
                    <th>Type</th>
                    <th>Looks in</th>
                    <th>Severity</th>
                    <th>Scope</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($keywords as $keyword): ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <strong><?= e((string) $keyword['label']) ?></strong>
                                <?php if (($keyword['notes'] ?? '') !== ''): ?>
                                    <small><?= e((string) $keyword['notes']) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><code><?= e(\DynaPulse\Support\Str::limit((string) $keyword['pattern'], 42)) ?></code></td>
                        <td><span class="badge"><?= e((string) $keyword['match_type']) ?></span></td>
                        <td>
                            <div class="row" style="gap:4px;">
                                <?php if ((array) $keyword['fields'] === []): ?>
                                    <span class="chip" style="font-size:10.5px;" title="Resolved from Settings">default</span>
                                <?php endif; ?>
                                <?php foreach ((array) $keyword['fields'] as $field): ?>
                                    <span class="chip" style="font-size:10.5px;"><?= e($fieldLabels[$field] ?? $field) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><span class="<?= e(severity_class((string) $keyword['severity'])) ?>"><?= e((string) $keyword['severity']) ?></span></td>
                        <td class="muted">
                            <?= ($keyword['domain_id'] ?? null) === null ? 'All domains' : e((string) ($keyword['domain_name'] ?? $keyword['domain_id'])) ?>
                        </td>
                        <td>
                            <?php if ((int) $keyword['active'] === 1): ?>
                                <span class="status-line"><span class="status-dot status-dot--ok"></span> active</span>
                            <?php else: ?>
                                <span class="status-line"><span class="status-dot"></span> paused</span>
                            <?php endif; ?>
                        </td>
                        <td class="right nowrap">
                            <?php if ($canManage): ?>
                                <a class="btn btn--ghost btn--sm" href="<?= e(path_url('keywords?edit=' . (int) $keyword['id'])) ?>">Edit</a>
                                <form method="post" action="<?= e(path_url('keywords/' . (int) $keyword['id'] . '/toggle')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= (int) $keyword['active'] === 1 ? 'Pause' : 'Resume' ?></button>
                                </form>
                                <form method="post" action="<?= e(path_url('keywords/' . (int) $keyword['id'] . '/delete')) ?>" class="inline-form"
                                      data-confirm="Delete the keyword “<?= e((string) $keyword['label']) ?>”? Existing matches are kept.">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--danger btn--sm" type="submit">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">read-only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head"><h2>Matching tips</h2></div>
    <div class="card__body">
        <div class="grid grid--3">
            <div>
                <div class="label">Phrase</div>
                <p class="hint" style="margin-top:4px;">Simple substring, best for natural language: <code>cancel my order</code>, <code>speak to a manager</code>.</p>
            </div>
            <div>
                <div class="label">Whole word</div>
                <p class="hint" style="margin-top:4px;">Avoids partial hits, e.g. <code>churn</code> will not match “churning”.</p>
            </div>
            <div>
                <div class="label">Regex</div>
                <p class="hint" style="margin-top:4px;">For power users: <code>refund|chargeback</code>, <code>\b(?:cancel|cancelled)\b</code>.</p>
            </div>
        </div>
    </div>
</div>
