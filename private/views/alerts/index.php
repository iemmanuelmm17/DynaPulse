<?php
/**
 * @var list<array<string, mixed>> $matches
 * @var array<string, mixed> $filters
 * @var list<array<string, mixed>> $keywords
 * @var array<string, mixed> $pagination
 * @var string $timezone
 * @var list<array<string, mixed>> $topKeywords
 * @var string $exportQuery
 */
$exportSuffix = $exportQuery !== '' ? '&' . $exportQuery : '';
$maxKeyword = 1;
foreach ($topKeywords as $keyword) {
    $maxKeyword = max($maxKeyword, (int) $keyword['count']);
}
?>
<div class="layout-2col">
    <div>
        <form method="get" action="<?= e(path_url('alerts')) ?>" class="toolbar" role="search">
            <div class="field field--grow">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Keyword, snippet, number">
            </div>
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
            </div>
            <div class="field">
                <label for="keyword">Keyword</label>
                <select id="keyword" name="keyword" data-autosubmit>
                    <option value="">Any keyword</option>
                    <?php foreach ($keywords as $keyword): ?>
                        <option value="<?= (int) $keyword['id'] ?>" <?= (int) ($filters['keyword_id'] ?? 0) === (int) $keyword['id'] ? 'selected' : '' ?>>
                            <?= e($keyword['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="severity">Severity</label>
                <select id="severity" name="severity" data-autosubmit>
                    <option value="">Any</option>
                    <?php foreach (['low', 'medium', 'high', 'critical'] as $severity): ?>
                        <option value="<?= $severity ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>><?= ucfirst($severity) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar__actions">
                <button type="submit" class="btn btn--primary btn--sm"><?= icon('filter') ?> Apply</button>
                <a class="btn btn--ghost btn--sm" href="<?= e(path_url('alerts')) ?>">Reset</a>
                <a class="btn btn--ghost btn--sm" href="<?= e(path_url('exports/alerts.csv') . '?1=1' . $exportSuffix) ?>"><?= icon('download') ?> CSV</a>
                <a class="btn btn--ghost btn--sm" href="<?= e(path_url('exports/alerts.xlsx') . '?1=1' . $exportSuffix) ?>"><?= icon('download') ?> XLSX</a>
            </div>
        </form>

        <div class="card">
            <?php if ($matches === []): ?>
                <div class="empty">
                    <div class="empty__icon"><?= icon('bell') ?></div>
                    <h3>No matches in this period</h3>
                    <p>When a transcript, summary, topic or phone number matches one of your keywords it is listed here, and the recipients get an email.</p>
                    <a class="btn btn--subtle btn--sm" href="<?= e(path_url('keywords')) ?>">Review keywords</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Keyword</th>
                            <th>Call</th>
                            <th>Matched on</th>
                            <th>When</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($matches as $match): ?>
                            <tr>
                                <td style="min-width:180px;">
                                    <div class="cell-main">
                                        <span class="row" style="gap:7px;">
                                            <strong><?= e((string) $match['keyword_label']) ?></strong>
                                            <span class="<?= e(severity_class((string) $match['severity'])) ?>"><?= e((string) $match['severity']) ?></span>
                                        </span>
                                        <small><?= e((string) ($match['domain_name'] ?? '')) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-main">
                                        <strong><?= e(pretty_phone($match['caller_number'])) ?></strong>
                                        <small>→ <?= e(pretty_phone($match['called_number'])) ?></small>
                                    </div>
                                </td>
                                <td style="max-width:340px;">
                                    <div class="muted" style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;"><?= e((string) $match['field']) ?></div>
                                    <div class="clamp-2" style="font-size:12.5px;font-style:italic;">“<?= e((string) $match['snippet']) ?>”</div>
                                </td>
                                <td class="nowrap muted"><?= e(tz_date((string) $match['started_at'], 'M j, g:i A', (string) ($match['domain_timezone'] ?? $timezone))) ?></td>
                                <td class="right">
                                    <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls/' . (int) $match['call_id'])) ?>">Open call</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php require __DIR__ . '/../partials/pagination.php'; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="stack sticky-side">
        <div class="card">
            <div class="card__head"><h2>Most triggered</h2></div>
            <div class="card__body">
                <?php if ($topKeywords === []): ?>
                    <p class="muted" style="margin:0;font-size:12.5px;">No matches yet in this window.</p>
                <?php else: ?>
                    <div class="bar-list">
                        <?php foreach ($topKeywords as $keyword): ?>
                            <div class="bar-row">
                                <div>
                                    <div class="bar-row__label">
                                        <span class="truncate"><?= e($keyword['label']) ?></span>
                                        <span class="<?= e(severity_class($keyword['severity'])) ?>"><?= e($keyword['severity']) ?></span>
                                    </div>
                                    <div class="bar-row__track">
                                        <div class="bar-row__fill" style="width:<?= (int) round((int) $keyword['count'] / $maxKeyword * 100) ?>%"></div>
                                    </div>
                                </div>
                                <div class="bar-row__count"><?= (int) $keyword['count'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2>How alerts work</h2></div>
            <div class="card__body">
                <ol class="notice-list" style="padding-left:2px;counter-reset:step;">
                    <li>Completed call arrives by event subscription or poller.</li>
                    <li>Transcript, summary, topics and sentiment come from Call Intelligence.</li>
                    <li>Active keywords are matched.</li>
                    <li>One grouped email goes to the domain recipients.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
