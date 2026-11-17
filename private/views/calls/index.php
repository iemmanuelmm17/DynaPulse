<?php
/**
 * @var list<array<string, mixed>> $calls
 * @var array<string, mixed> $stats
 * @var array<string, mixed> $filters
 * @var list<array<string, mixed>> $keywords
 * @var array<string, mixed> $pagination
 * @var string $timezone
 * @var string $exportQuery
 */
use DynaPulse\Http\Context;

$canManage = Context::can('manage');
$exportSuffix = $exportQuery !== '' ? '&' . $exportQuery : '';
?>
<div class="stat-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
    <div class="stat">
        <div class="stat__label">Calls in range</div>
        <div class="stat__value"><?= number_format((int) $stats['calls']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['with_transcript']) ?> transcribed</div>
    </div>
    <div class="stat stat--warn">
        <div class="stat__label">Flagged calls</div>
        <div class="stat__value"><?= number_format((int) $stats['alert_calls']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['matches']) ?> keyword matches</div>
    </div>
    <div class="stat stat--info">
        <div class="stat__label">Recordings</div>
        <div class="stat__value"><?= number_format((int) $stats['with_recording']) ?></div>
        <div class="stat__meta">ready to play</div>
    </div>
    <div class="stat stat--danger">
        <div class="stat__label">Negative</div>
        <div class="stat__value"><?= number_format((int) $stats['negative']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['positive']) ?> positive</div>
    </div>
</div>

<form method="get" action="<?= e(path_url('calls')) ?>" class="toolbar" role="search">
    <div class="field field--grow">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Number, name or words from the transcript">
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
        <label for="sentiment">Sentiment</label>
        <select id="sentiment" name="sentiment" data-autosubmit>
            <option value="">Any</option>
            <?php foreach (['positive', 'neutral', 'mixed', 'negative'] as $option): ?>
                <option value="<?= $option ?>" <?= $filters['sentiment'] === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="direction">Direction</label>
        <select id="direction" name="direction" data-autosubmit>
            <option value="">Any</option>
            <?php foreach (['Inbound', 'Outbound', 'Internal', 'Missed'] as $option): ?>
                <option value="<?= $option ?>" <?= $filters['direction'] === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="keyword">Keyword</label>
        <select id="keyword" name="keyword" data-autosubmit>
            <option value="">Any</option>
            <?php foreach ($keywords as $keyword): ?>
                <option value="<?= (int) $keyword['id'] ?>" <?= (int) ($filters['keyword_id'] ?? 0) === (int) $keyword['id'] ? 'selected' : '' ?>>
                    <?= e($keyword['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="sort">Sort</label>
        <select id="sort" name="sort" data-autosubmit>
            <?php foreach (['recent' => 'Newest first', 'oldest' => 'Oldest first', 'alerts' => 'Most matches', 'duration' => 'Longest', 'sentiment' => 'Most negative'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="toolbar__actions">
        <label class="check" style="margin-right:4px;">
            <input type="checkbox" name="alerts" value="1" <?= $filters['alert_only'] ? 'checked' : '' ?> data-autosubmit>
            Flagged only
        </label>
        <button type="submit" class="btn btn--primary btn--sm"><?= icon('filter') ?> Apply</button>
        <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls')) ?>">Reset</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(path_url('exports/calls.csv') . '?1=1' . $exportSuffix) ?>"><?= icon('download') ?> CSV</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(path_url('exports/calls.xlsx') . '?1=1' . $exportSuffix) ?>"><?= icon('download') ?> XLSX</a>
    </div>
</form>

<div class="card">
    <?php if ($calls === []): ?>
        <div class="empty">
            <div class="empty__icon"><?= icon('inbox') ?></div>
            <h3>No calls match these filters</h3>
            <p>Try widening the date range or clearing the search. Calls appear as soon as NetSapiens posts the CDR and DynaPulse finishes processing it.</p>
            <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls')) ?>">Clear filters</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Caller → Called</th>
                    <th>Direction</th>
                    <th class="num">Duration</th>
                    <th>Sentiment</th>
                    <th>Matches</th>
                    <th>Recording</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($calls as $call): ?>
                    <?php $matched = (int) $call['match_count'] > 0; ?>
                    <tr>
                        <td class="nowrap">
                            <div class="cell-main">
                                <strong><?= e(tz_date((string) $call['started_at'], 'M j', (string) $call['domain_timezone'])) ?></strong>
                                <small><?= e(tz_date((string) $call['started_at'], 'g:i A', (string) $call['domain_timezone'])) ?></small>
                            </div>
                        </td>
                        <td>
                            <div class="cell-main">
                                <span class="truncate">
                                    <strong><?= e(pretty_phone($call['caller_number'])) ?></strong>
                                    <span class="muted">→</span>
                                    <?= e(pretty_phone($call['called_number'])) ?>
                                </span>
                                <small>
                                    <?= e($call['domain_name']) ?>
                                    <?php if (($call['caller_name'] ?? '') !== ''): ?> · <?= e($call['caller_name']) ?><?php endif; ?>
                                </small>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $call['direction'] === 'Inbound' ? 'badge--info' : ($call['direction'] === 'Outbound' ? 'badge--accent' : '') ?>">
                                <?= e($call['direction'] ?: 'Unknown') ?>
                            </span>
                        </td>
                        <td class="num nowrap"><?= e(fmt_duration((int) $call['duration_seconds'])) ?></td>
                        <td>
                            <span class="<?= e(sentiment_class((string) $call['sentiment_label'])) ?>"><?= e($call['sentiment_label'] ?: 'n/a') ?></span>
                        </td>
                        <td>
                            <?php if ($matched): ?>
                                <span class="badge badge--warning"><?= (int) $call['match_count'] ?> match<?= (int) $call['match_count'] === 1 ? '' : 'es' ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $call['has_recording'] === 1): ?>
                                <span class="status-line"><span class="status-dot status-dot--ok"></span> ready</span>
                            <?php elseif ($call['recording_status'] === 'pending'): ?>
                                <span class="status-line"><span class="status-dot status-dot--warn"></span> pending</span>
                            <?php else: ?>
                                <span class="muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td class="right nowrap">
                            <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls/' . (int) $call['id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
