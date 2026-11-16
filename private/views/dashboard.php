<?php
/**
 * @var array<string, mixed> $stats
 * @var array<string, int> $sentiment
 * @var list<array<string, mixed>> $series
 * @var list<array<string, mixed>> $topKeywords
 * @var list<array<string, mixed>> $topTopics
 * @var list<array<string, mixed>> $recentAlerts
 * @var list<array<string, mixed>> $recentCalls
 * @var array<string, bool> $checklist
 * @var list<array<string, mixed>> $domains
 * @var string $timezone
 * @var int $pendingCalls
 * @var int $notificationsFailed
 * @var int|null $lastPoll
 */
use DynaPulse\Http\Context;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

$missing = array_keys(array_filter($checklist, static fn (bool $done): bool => !$done));
$isAdmin = Context::can('admin');

$maxCalls = 1;
foreach ($series as $point) {
    $maxCalls = max($maxCalls, (int) $point['calls'], (int) $point['alerts']);
}

$chartWidth = 680;
$chartHeight = 190;
$padLeft = 30;
$padTop = 12;
$padBottom = 24;
$plotWidth = $chartWidth - $padLeft - 10;
$plotHeight = $chartHeight - $padTop - $padBottom;
$groupWidth = $plotWidth / max(1, count($series));
$barWidth = min(11, max(3, $groupWidth / 3.2));

$donutTotal = max(1, array_sum($sentiment));
$radius = 42;
$circumference = 2 * M_PI * $radius;
$donutOffset = 0;
$donutSegments = [
    ['label' => 'Positive', 'value' => $sentiment['positive'], 'colour' => 'var(--positive)'],
    ['label' => 'Neutral', 'value' => $sentiment['neutral'], 'colour' => 'var(--neutral)'],
    ['label' => 'Mixed', 'value' => $sentiment['mixed'], 'colour' => 'var(--mixed)'],
    ['label' => 'Negative', 'value' => $sentiment['negative'], 'colour' => 'var(--negative)'],
];
?>
<?php if ($isAdmin && $missing !== []): ?>
    <div class="banner banner--warn">
        <span class="banner__icon"><?= icon('alert') ?></span>
        <div>
            <strong>Finish setting up DynaPulse</strong>
            <?= e(implode(' · ', array_map(static fn (string $item): string => match ($item) {
                'domains' => 'add a domain',
                'keywords' => 'create keywords',
                'recipients' => 'add alert recipients',
                'mail' => 'configure Outlook SMTP in .env',
                'webhook' => 'create the NetSapiens event subscription',
                default => $item,
            }, $missing))) ?>
            — open <a href="<?= e(path_url('settings')) ?>">Settings</a> to finish.
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat__icon"><?= icon('phone') ?></div>
        <div class="stat__label">Calls reviewed</div>
        <div class="stat__value"><?= number_format((int) $stats['calls']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['with_transcript']) ?> with transcript · <?= number_format((int) $stats['with_recording']) ?> recordings</div>
    </div>
    <div class="stat stat--warn">
        <div class="stat__icon"><?= icon('target') ?></div>
        <div class="stat__label">Keyword matches</div>
        <div class="stat__value"><?= number_format((int) $stats['matches']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['alert_calls']) ?> call(s) flagged · <?= number_format((int) $stats['alerts_sent']) ?> alerts sent</div>
    </div>
    <div class="stat stat--danger">
        <div class="stat__icon"><?= icon('alert') ?></div>
        <div class="stat__label">Negative sentiment</div>
        <div class="stat__value"><?= $sentiment['negative'] ?>%</div>
        <div class="stat__meta"><?= number_format((int) $stats['negative']) ?> call(s) on the negative side</div>
    </div>
    <div class="stat stat--info">
        <div class="stat__icon"><?= icon('clock') ?></div>
        <div class="stat__label">Average call</div>
        <div class="stat__value"><?= e(fmt_duration((int) $stats['avg_duration'])) ?></div>
        <div class="stat__meta"><?= e(fmt_duration((int) $stats['talk_seconds'])) ?> total talk time</div>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card">
            <div class="card__head">
                <h2>Calls &amp; alerts</h2>
                <span class="muted">last 14 days</span>
            </div>
            <div class="card__body">
                <svg class="chart" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="Daily calls and alerts for the last 14 days">
                    <?php foreach ([0, 0.5, 1] as $step): ?>
                        <?php $y = $padTop + $plotHeight - ($step * $plotHeight); ?>
                        <line class="grid-line" x1="<?= $padLeft ?>" y1="<?= $y ?>" x2="<?= $chartWidth - 10 ?>" y2="<?= $y ?>"/>
                        <text class="axis" x="0" y="<?= $y + 3 ?>"><?= (int) round($maxCalls * $step) ?></text>
                    <?php endforeach; ?>

                    <?php foreach ($series as $index => $point): ?>
                        <?php
                        $centre = $padLeft + ($index * $groupWidth) + ($groupWidth / 2);
                        $callsHeight = max(1, ((int) $point['calls'] / $maxCalls) * $plotHeight);
                        $alertsHeight = max(0, ((int) $point['alerts'] / $maxCalls) * $plotHeight);
                        ?>
                        <rect class="bar" x="<?= round($centre - $barWidth - 1, 2) ?>" y="<?= round($padTop + $plotHeight - $callsHeight, 2) ?>"
                              width="<?= round($barWidth, 2) ?>" height="<?= round($callsHeight, 2) ?>" rx="2">
                            <title><?= e($point['label']) ?>: <?= (int) $point['calls'] ?> calls</title>
                        </rect>
                        <?php if ((int) $point['alerts'] > 0): ?>
                            <rect class="bar bar--alert" x="<?= round($centre + 1, 2) ?>" y="<?= round($padTop + $plotHeight - $alertsHeight, 2) ?>"
                                  width="<?= round($barWidth, 2) ?>" height="<?= round($alertsHeight, 2) ?>" rx="2">
                                <title><?= e($point['label']) ?>: <?= (int) $point['alerts'] ?> flagged</title>
                            </rect>
                        <?php endif; ?>
                        <?php if ($index % 2 === 0 || $index === count($series) - 1): ?>
                            <text class="axis" x="<?= round($centre, 2) ?>" y="<?= $chartHeight - 6 ?>" text-anchor="middle"><?= e($point['label']) ?></text>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </svg>
                <div class="row" style="margin-top:6px;font-size:11.5px;color:var(--muted);">
                    <span class="row" style="gap:6px;"><i style="width:8px;height:8px;border-radius:2px;background:var(--accent);display:inline-block"></i> Calls</span>
                    <span class="row" style="gap:6px;"><i style="width:8px;height:8px;border-radius:2px;background:var(--mixed);display:inline-block"></i> Flagged</span>
                    <span class="spacer"></span>
                    <span>Timezone: <?= e($timezone) ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>Latest alerts</h2>
                <a class="muted" href="<?= e(path_url('alerts')) ?>">View all</a>
            </div>
            <?php if ($recentAlerts === []): ?>
                <div class="empty">
                    <div class="empty__icon"><?= icon('bell') ?></div>
                    <h3>No keyword matches yet</h3>
                    <p>Once calls are processed, anything that matches your keyword list shows up here and triggers an email.</p>
                    <?php if (Context::can('manage')): ?>
                        <a class="btn btn--subtle btn--sm" href="<?= e(path_url('keywords')) ?>">Add a keyword</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Keyword</th><th>Call</th><th>When</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($recentAlerts as $alert): ?>
                            <tr>
                                <td>
                                    <div class="cell-main">
                                        <strong><?= e($alert['keyword_label']) ?></strong>
                                        <small><?= e(ucfirst((string) $alert['field'])) ?> · <?= e(Str::limit((string) $alert['snippet'], 64)) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-main">
                                        <strong><?= e(pretty_phone($alert['caller_number'])) ?></strong>
                                        <small>→ <?= e(pretty_phone($alert['called_number'])) ?></small>
                                    </div>
                                </td>
                                <td class="nowrap muted"><?= e(tz_date((string) $alert['started_at'], 'M j, g:i A')) ?></td>
                                <td class="right">
                                    <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls/' . (int) $alert['call_id'])) ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>Recent calls</h2>
                <a class="muted" href="<?= e(path_url('calls')) ?>">Call history</a>
            </div>
            <?php if ($recentCalls === []): ?>
                <div class="empty">
                    <div class="empty__icon"><?= icon('phone') ?></div>
                    <h3>No calls in this period</h3>
                    <p>DynaPulse ingests CDRs from the NetSapiens event subscription and the scheduled poller.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Caller</th><th>Called</th><th>When</th><th>Duration</th><th>Sentiment</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($recentCalls as $call): ?>
                            <tr>
                                <td class="strong"><?= e(pretty_phone($call['caller_number'])) ?></td>
                                <td><?= e(pretty_phone($call['called_number'])) ?></td>
                                <td class="nowrap muted"><?= e(tz_date((string) $call['started_at'], 'M j, g:i A')) ?></td>
                                <td class="nowrap"><?= e(fmt_duration((int) $call['duration_seconds'])) ?></td>
                                <td><span class="<?= e(sentiment_class((string) $call['sentiment_label'])) ?>"><?= e($call['sentiment_label'] ?: 'unknown') ?></span></td>
                                <td class="right"><a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls/' . (int) $call['id'])) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($domains !== []): ?>
            <div class="card">
                <div class="card__head"><h2>Domains</h2><a class="muted" href="<?= e(path_url('domains')) ?>">Manage</a></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Domain</th><th class="num">Calls</th><th class="num">Recipients</th><th class="num">Keywords</th><th>Last poll</th></tr></thead>
                        <tbody>
                        <?php foreach ($domains as $domain): ?>
                            <tr>
                                <td>
                                    <div class="cell-main">
                                        <strong><?= e($domain['label'] ?: $domain['domain']) ?></strong>
                                        <small><?= e($domain['domain']) ?></small>
                                    </div>
                                </td>
                                <td class="num"><?= number_format((int) $domain['call_count']) ?></td>
                                <td class="num"><?= number_format((int) $domain['recipient_count']) ?></td>
                                <td class="num"><?= number_format((int) $domain['keyword_count']) ?></td>
                                <td class="nowrap muted"><?= e(Time::relative($domain['last_polled_at'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card__head"><h2>Sentiment mix</h2></div>
            <div class="card__body">
                <div class="donut">
                    <svg class="donut__chart" viewBox="0 0 100 100" role="img" aria-label="Sentiment distribution">
                        <circle cx="50" cy="50" r="<?= $radius ?>" fill="none" stroke="var(--surface-3)" stroke-width="13"/>
                        <?php foreach ($donutSegments as $segment): ?>
                            <?php
                            $fraction = $segment['value'] / $donutTotal;
                            $dash = $fraction * $circumference;
                            if ($dash <= 0) {
                                continue;
                            }
                            ?>
                            <circle cx="50" cy="50" r="<?= $radius ?>" fill="none"
                                    stroke="<?= e($segment['colour']) ?>" stroke-width="13"
                                    stroke-dasharray="<?= round($dash, 2) ?> <?= round($circumference - $dash, 2) ?>"
                                    stroke-dashoffset="<?= round(-$donutOffset, 2) ?>"
                                    transform="rotate(-90 50 50)" stroke-linecap="butt">
                                <title><?= e($segment['label']) ?>: <?= (int) $segment['value'] ?>%</title>
                            </circle>
                            <?php $donutOffset += $dash; ?>
                        <?php endforeach; ?>
                        <text x="50" y="47" text-anchor="middle" style="font-size:15px;font-weight:700;fill:var(--text)"><?= (int) $stats['calls'] ?></text>
                        <text x="50" y="60" text-anchor="middle" style="font-size:8px;fill:var(--muted);letter-spacing:.06em">CALLS</text>
                    </svg>
                    <div class="donut__legend">
                        <?php foreach ($donutSegments as $segment): ?>
                            <span>
                                <i style="background:<?= e($segment['colour']) ?>"></i>
                                <?= e($segment['label']) ?>
                                <b><?= (int) $segment['value'] ?>%</b>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2>Top keywords</h2><a class="muted" href="<?= e(path_url('alerts')) ?>">Alerts</a></div>
            <div class="card__body">
                <?php if ($topKeywords === []): ?>
                    <p class="muted" style="margin:0;font-size:12.5px;">No matches yet. Keywords are checked against transcripts, summaries, topics and phone numbers.</p>
                <?php else: ?>
                    <?php $maxKeyword = max(1, ...array_map(static fn (array $k): int => (int) $k['count'], $topKeywords)); ?>
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
            <div class="card__head"><h2>Trending topics</h2></div>
            <div class="card__body">
                <?php if ($topTopics === []): ?>
                    <p class="muted" style="margin:0;font-size:12.5px;">Topics appear after Call Intelligence returns transcripts.</p>
                <?php else: ?>
                    <div class="row" style="gap:6px;">
                        <?php foreach ($topTopics as $topic): ?>
                            <span class="chip"><?= e($topic['topic']) ?> <span class="muted"><?= (int) $topic['count'] ?></span></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2>Pipeline</h2></div>
            <div class="card__body">
                <ul class="notice-list">
                    <li>
                        <span class="status-dot <?= $pendingCalls > 0 ? 'status-dot--warn' : 'status-dot--ok' ?>"></span>
                        <span><strong><?= number_format($pendingCalls) ?></strong> call(s) waiting in the processing queue</span>
                    </li>
                    <li>
                        <span class="status-dot <?= $notificationsFailed > 0 ? 'status-dot--err' : 'status-dot--ok' ?>"></span>
                        <span><strong><?= number_format($notificationsFailed) ?></strong> failed/blocked email(s) in the last 7 days</span>
                    </li>
                    <li>
                        <span class="status-dot <?= $lastPoll !== null ? 'status-dot--ok' : 'status-dot--warn' ?>"></span>
                        <span>Last CDR poll: <?= e($lastPoll !== null ? Time::relative($lastPoll) : 'never') ?></span>
                    </li>
                </ul>
                <div class="hr"></div>
                <p class="hint" style="margin:0;">
                    Run <code>php private/bin/console.php work</code> every minute from Windows Task Scheduler
                    to process the queue and deliver alert emails.
                </p>
            </div>
        </div>
    </div>
</div>
