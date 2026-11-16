<?php
/**
 * @var array<string, mixed> $report
 * @var array<string, mixed> $filters
 * @var list<array<string, mixed>> $schedules
 * @var list<array<string, mixed>> $runs
 * @var list<array<string, mixed>> $domains
 * @var bool $canManage
 * @var string $timezone
 * @var string $exportQuery
 */
use DynaPulse\Http\Context;
use DynaPulse\Support\Time;

$stats = $report['stats'];
$sentiment = $report['sentiment'];
$rangeLabel = $report['range']['label'];
$exportSuffix = $exportQuery !== '' ? '&' . $exportQuery : '';
$maxKeyword = 1;
foreach ($report['topKeywords'] as $keyword) {
    $maxKeyword = max($maxKeyword, (int) $keyword['count']);
}
$cadence = static function (array $schedule): string {
    $time = substr((string) $schedule['send_time'], 0, 5);
    if ($schedule['frequency'] === 'daily') {
        return 'Every day at ' . $time;
    }
    if ($schedule['frequency'] === 'monthly') {
        return 'Day ' . (int) $schedule['day_of_month'] . ' each month at ' . $time;
    }
    $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    return ($days[(int) $schedule['weekday']] ?? 'Monday') . ' at ' . $time;
};
?>
<form method="get" action="<?= e(path_url('reports')) ?>" class="toolbar">
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
        <label for="keyword">Keyword</label>
        <select id="keyword" name="keyword" data-autosubmit>
            <option value="">Any</option>
            <?php foreach ($keywords as $keyword): ?>
                <option value="<?= (int) $keyword['id'] ?>" <?= (int) ($filters['keyword_id'] ?? 0) === (int) $keyword['id'] ? 'selected' : '' ?>><?= e($keyword['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="toolbar__actions">
        <button type="submit" class="btn btn--primary btn--sm"><?= icon('filter') ?> Apply</button>
        <a class="btn btn--ghost btn--sm" href="<?= e(path_url('reports')) ?>">Reset</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(path_url('exports/calls.csv') . '?1=1' . $exportSuffix) ?>"><?= icon('download') ?> Calls CSV</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(path_url('exports/alerts.xlsx') . '?1=1' . $exportSuffix) ?>"><?= icon('download') ?> Alerts XLSX</a>
        <button type="button" class="btn btn--ghost btn--sm" onclick="window.print()"><?= icon('chart') ?> Print</button>
    </div>
</form>

<div class="stat-grid">
    <div class="stat">
        <div class="stat__label">Calls</div>
        <div class="stat__value"><?= number_format((int) $stats['calls']) ?></div>
        <div class="stat__meta"><?= e($rangeLabel) ?></div>
    </div>
    <div class="stat stat--warn">
        <div class="stat__label">Flagged calls</div>
        <div class="stat__value"><?= number_format((int) $stats['alert_calls']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['matches']) ?> matches</div>
    </div>
    <div class="stat stat--danger">
        <div class="stat__label">Negative</div>
        <div class="stat__value"><?= (int) $sentiment['negative'] ?>%</div>
        <div class="stat__meta"><?= number_format((int) $stats['negative']) ?> calls</div>
    </div>
    <div class="stat stat--info">
        <div class="stat__label">Talk time</div>
        <div class="stat__value"><?= e(fmt_duration((int) $stats['talk_seconds'])) ?></div>
        <div class="stat__meta">avg <?= e(fmt_duration((int) $stats['avg_duration'])) ?></div>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2>Top keywords</h2><span class="muted"><?= e($rangeLabel) ?></span></div>
            <div class="card__body">
                <?php if ($report['topKeywords'] === []): ?>
                    <p class="muted" style="margin:0;font-size:12.5px;">No keyword matches in this period.</p>
                <?php else: ?>
                    <div class="bar-list">
                        <?php foreach ($report['topKeywords'] as $keyword): ?>
                            <div class="bar-row">
                                <div>
                                    <div class="bar-row__label">
                                        <span class="truncate"><?= e((string) $keyword['label']) ?></span>
                                        <span class="<?= e(severity_class((string) $keyword['severity'])) ?>"><?= e((string) $keyword['severity']) ?></span>
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
            <div class="card__head"><h2>Recent alerts</h2><a class="muted" href="<?= e(path_url('alerts')) ?>">All alerts</a></div>
            <?php if ($report['recentAlerts'] === []): ?>
                <div class="empty" style="padding:32px 20px;">
                    <p style="margin:0;">Nothing was flagged in this window.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Keyword</th><th>Call</th><th>Severity</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['recentAlerts'] as $alert): ?>
                            <tr>
                                <td>
                                    <div class="cell-main">
                                        <a class="row-link" href="<?= e(path_url('calls/' . (int) $alert['call_id'])) ?>"><strong><?= e((string) $alert['keyword_label']) ?></strong></a>
                                        <small><?= e(\DynaPulse\Support\Str::limit((string) $alert['snippet'], 70)) ?></small>
                                    </div>
                                </td>
                                <td class="nowrap"><?= e(pretty_phone($alert['caller_number'])) ?></td>
                                <td><span class="<?= e(severity_class((string) $alert['severity'])) ?>"><?= e((string) $alert['severity']) ?></span></td>
                                <td class="nowrap muted"><?= e(tz_date((string) $alert['started_at'], 'M j, g:i A', $timezone)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__head"><h2>Scheduled email reports</h2><span class="muted"><?= count($schedules) ?> schedule(s)</span></div>

            <?php if ($schedules === []): ?>
                <div class="empty" style="padding:30px 20px;">
                    <p style="margin:0;">No scheduled reports yet. Create one below to have a digest delivered automatically.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Report</th><th>Cadence</th><th>Recipients</th><th>Next run</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td>
                                    <div class="cell-main">
                                        <strong><?= e((string) $schedule['name']) ?></strong>
                                        <small><?= e(($schedule['domain_name'] ?? '') !== '' ? (string) $schedule['domain_name'] : 'All domains') ?> · last <?= $schedule['period_days'] ?> day(s)</small>
                                        <?php if (($schedule['last_error'] ?? '') !== ''): ?>
                                            <small class="text-danger"><?= e(\DynaPulse\Support\Str::limit((string) $schedule['last_error'], 70)) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="nowrap"><?= e($cadence($schedule)) ?></td>
                                <td>
                                    <span class="badge"><?= count((array) $schedule['recipients']) ?> address(es)</span>
                                </td>
                                <td class="nowrap muted">
                                    <?= e($schedule['active'] ? Time::display((string) $schedule['next_run_at'], (string) Context::config('app.timezone', 'UTC')) : 'paused') ?>
                                </td>
                                <td class="right nowrap">
                                    <?php if ($canManage): ?>
                                        <form method="post" action="<?= e(path_url('reports/schedules/' . (int) $schedule['id'] . '/run')) ?>" class="inline-form">
                                            <?= csrf_field() ?>
                                            <button class="btn btn--subtle btn--sm" type="submit">Send now</button>
                                        </form>
                                        <form method="post" action="<?= e(path_url('reports/schedules/' . (int) $schedule['id'] . '/delete')) ?>" class="inline-form"
                                              data-confirm="Delete the schedule “<?= e((string) $schedule['name']) ?>”?">
                                            <?= csrf_field() ?>
                                            <button class="btn btn--danger btn--sm" type="submit">Delete</button>
                                        </form>
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
            <div class="card__head"><h2>Recent report runs</h2></div>
            <?php if ($runs === []): ?>
                <div class="empty" style="padding:26px 20px;"><p style="margin:0;">No report has been sent yet.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Report</th><th>Ran</th><th>Calls</th><th>Alerts</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td><?= e((string) ($run['schedule_name'] ?? 'Deleted schedule')) ?></td>
                                <td class="nowrap muted"><?= e(tz_date((string) $run['ran_at'], 'M j, g:i A', (string) Context::config('app.timezone', 'UTC'))) ?></td>
                                <td class="num"><?= (int) $run['calls_count'] ?></td>
                                <td class="num"><?= (int) $run['alerts_count'] ?></td>
                                <td>
                                    <span class="badge <?= $run['status'] === 'sent' ? 'badge--success' : ($run['status'] === 'failed' ? 'badge--danger' : 'badge--warning') ?>">
                                        <?= e((string) $run['status']) ?>
                                    </span>
                                    <?php if (($run['error'] ?? '') !== ''): ?>
                                        <div class="muted" style="font-size:11px;max-width:260px;"><?= e(\DynaPulse\Support\Str::limit((string) $run['error'], 90)) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="stack sticky-side">
        <div class="card">
            <div class="card__head"><h2>Sentiment mix</h2></div>
            <div class="card__body">
                <div class="sentiment-bar">
                    <?php if ($sentiment['positive'] > 0): ?><span class="pos" style="width:<?= $sentiment['positive'] ?>%"></span><?php endif; ?>
                    <?php if ($sentiment['neutral'] > 0): ?><span class="neu" style="width:<?= $sentiment['neutral'] ?>%"></span><?php endif; ?>
                    <?php if ($sentiment['mixed'] > 0): ?><span class="mix" style="width:<?= $sentiment['mixed'] ?>%"></span><?php endif; ?>
                    <?php if ($sentiment['negative'] > 0): ?><span class="neg" style="width:<?= $sentiment['negative'] ?>%"></span><?php endif; ?>
                </div>
                <div class="sentiment-legend">
                    <span><i style="background:var(--positive)"></i><?= (int) $sentiment['positive'] ?>% positive</span>
                    <span><i style="background:var(--neutral)"></i><?= (int) $sentiment['neutral'] ?>% neutral</span>
                    <span><i style="background:var(--mixed)"></i><?= (int) $sentiment['mixed'] ?>% mixed</span>
                    <span><i style="background:var(--negative)"></i><?= (int) $sentiment['negative'] ?>% negative</span>
                </div>

                <?php if ($report['topTopics'] !== []): ?>
                    <div class="hr"></div>
                    <div class="label" style="margin-bottom:7px;">Top topics</div>
                    <div class="row" style="gap:6px;">
                        <?php foreach ($report['topTopics'] as $topic): ?>
                            <span class="chip"><?= e((string) $topic['topic']) ?> <span class="muted"><?= (int) $topic['count'] ?></span></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManage): ?>
            <div class="card">
                <div class="card__head"><h2>New schedule</h2></div>
                <div class="card__body">
                    <form method="post" action="<?= e(path_url('reports/schedules')) ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="name">Name</label>
                            <input type="text" id="name" name="name" required maxlength="90" placeholder="Weekly management digest">
                        </div>
                        <?php if ($domains !== [] && \DynaPulse\Http\Context::domainId() === null): ?>
                            <div class="field">
                                <label for="domain_id">Domain</label>
                                <select id="domain_id" name="domain_id">
                                    <option value="all">All domains</option>
                                    <?php foreach ($domains as $domain): ?>
                                        <option value="<?= (int) $domain['id'] ?>"><?= e($domain['label'] ?: $domain['domain']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="grid grid--form">
                            <div class="field">
                                <label for="frequency">Frequency</label>
                                <select id="frequency" name="frequency">
                                    <option value="daily">Daily</option>
                                    <option value="weekly" selected>Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="send_time">Send at</label>
                                <input type="time" id="send_time" name="send_time" value="07:00" required>
                            </div>
                            <div class="field">
                                <label for="weekday">Weekday (weekly)</label>
                                <select id="weekday" name="weekday">
                                    <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $value === 1 ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="day_of_month">Day (monthly)</label>
                                <input type="number" id="day_of_month" name="day_of_month" min="1" max="28" value="1">
                            </div>
                        </div>
                        <div class="field">
                            <label for="period_days">Look back (days)</label>
                            <input type="number" id="period_days" name="period_days" min="1" max="90" value="7">
                        </div>
                        <div class="field">
                            <label for="recipients">Recipients</label>
                            <input type="text" id="recipients" name="recipients" placeholder="ops@acme.com, ceo@acme.com">
                            <span class="hint">Comma separated. Leave blank to use the domain recipient list.</span>
                        </div>
                        <label class="check" style="margin-bottom:14px;">
                            <input type="checkbox" name="include_csv" value="1" checked>
                            Attach call and alert spreadsheets
                        </label>
                        <button type="submit" class="btn btn--primary btn--sm"><?= icon('plus') ?> Create schedule</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card__head"><h2>Report notes</h2></div>
            <div class="card__body">
                <ul class="notice-list">
                    <li><?= icon('clock') ?><span>Schedules run from <code>php private/bin/console.php reports:run</code> — point Task Scheduler at it every 5–15 minutes.</span></li>
                    <li><?= icon('mail') ?><span>Report emails follow the same Outlook SMTP settings as alerts.</span></li>
                    <li><?= icon('download') ?><span>Every table on this page can be exported as CSV or XLSX with the current filters applied.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>
