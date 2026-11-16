<?php
/**
 * @var array<string, mixed> $call
 * @var list<array<string, mixed>> $matches
 * @var list<array<string, mixed>> $notifications
 * @var string $timezone
 * @var bool $canManage
 */
use DynaPulse\Http\Context;
use DynaPulse\Support\Str;

$topics = (array) $call['transcript_topics'];
$segments = (array) $call['transcript_segments'];
$hasTranscript = (int) $call['has_transcript'] === 1;
$positive = (int) $call['sentiment_positive'];
$neutral = (int) $call['sentiment_neutral'];
$negative = (int) $call['sentiment_negative'];
$sentimentTotal = max(1, $positive + $neutral + $negative);
$alertLabels = [
    'sent' => ['success', 'Alert emailed'],
    'queued' => ['warning', 'Alert queued'],
    'failed' => ['danger', 'Alert failed'],
    'blocked' => ['danger', 'Email not configured'],
    'no_recipients' => ['warning', 'No recipients'],
    'below_threshold' => ['info', 'Below severity threshold'],
    'disabled' => ['info', 'Alerts disabled'],
    'none' => ['info', 'No alert'],
];
[$alertTone, $alertLabel] = $alertLabels[$call['alert_status']] ?? ['info', ucfirst((string) $call['alert_status'])];
$mailReady = (bool) Context::config('mail.host') && (bool) Context::config('mail.username');
?>
<div class="card">
    <div class="detail-head">
        <div class="detail-head__main">
            <div class="row" style="gap:8px;margin-bottom:6px;">
                <span class="badge <?= $call['direction'] === 'Inbound' ? 'badge--info' : 'badge--accent' ?>"><?= e($call['direction'] ?: 'Call') ?></span>
                <span class="<?= e(sentiment_class((string) $call['sentiment_label'])) ?>"><?= e($call['sentiment_label'] ?: 'no sentiment') ?></span>
                <?php if ((int) $call['match_count'] > 0): ?>
                    <span class="badge badge--warning"><?= (int) $call['match_count'] ?> keyword match<?= (int) $call['match_count'] === 1 ? '' : 'es' ?></span>
                <?php endif; ?>
                <span class="badge <?= 'badge--' . $alertTone ?>"><?= e($alertLabel) ?></span>
            </div>
            <h2><?= e(pretty_phone($call['caller_number'])) ?> <span class="muted" style="font-weight:500;">→</span> <?= e(pretty_phone($call['called_number'])) ?></h2>
            <div class="sub">
                <?= e(tz_date((string) $call['started_at'], 'l, M j, Y · g:i:s A', $timezone)) ?>
                · <?= e(fmt_duration((int) $call['duration_seconds'])) ?>
                · <?= e((string) $call['domain_name']) ?>
            </div>
        </div>
        <div class="detail-head__actions">
            <?php if ($hasTranscript): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls/' . (int) $call['id'] . '/transcript')) ?>"><?= icon('download') ?> Transcript</a>
            <?php endif; ?>
            <?php if ((int) $call['has_recording'] === 1): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(path_url('calls/' . (int) $call['id'] . '/audio?download=1')) ?>"><?= icon('download') ?> Recording</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" action="<?= e(path_url('calls/' . (int) $call['id'] . '/retry')) ?>"
                      data-confirm="Re-run transcription, recording lookup and keyword matching for this call?">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--subtle btn--sm"><?= icon('refresh') ?> Reprocess</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <dl class="meta-grid">
        <div>
            <dt>Answered</dt>
            <dd><?= e(tz_date((string) $call['answered_at'], 'M j, g:i A', $timezone)) ?></dd>
        </div>
        <div>
            <dt>Talk time</dt>
            <dd><?= e(fmt_duration((int) $call['talk_seconds'])) ?> <span class="muted">of <?= e(fmt_duration((int) $call['duration_seconds'])) ?></span></dd>
        </div>
        <div>
            <dt>Answered by</dt>
            <dd><?= e($call['answered_by'] !== '' ? $call['answered_by'] : ($call['called_name'] !== '' ? $call['called_name'] : '—')) ?></dd>
        </div>
        <div>
            <dt>Disconnect</dt>
            <dd><?= e($call['disconnect_reason'] !== '' ? Str::limit($call['disconnect_reason'], 40) : '—') ?></dd>
        </div>
    </dl>

    <?php if ((int) $call['has_recording'] === 1): ?>
        <div class="audio-box">
            <audio controls preload="none" data-audio src="<?= e(path_url('calls/' . (int) $call['id'] . '/audio')) ?>">
                Your browser does not support the audio element.
            </audio>
            <div class="row">
                <span class="muted" style="font-size:11.5px;">
                    <?= (int) $call['recording_duration'] > 0 ? e(fmt_duration((int) $call['recording_duration'])) : 'Recording' ?>
                    <?php if ((int) $call['recording_size_kb'] > 0): ?>· <?= number_format((int) $call['recording_size_kb'] / 1024, 1) ?> MB<?php endif; ?>
                    <?= (bool) Context::config('security.recording_cache') ? '· cached' : '' ?>
                </span>
                <span class="spacer"></span>
                <span class="muted" style="font-size:11px;">Speed</span>
                <button type="button" class="btn btn--ghost speed" data-rate="1">1×</button>
                <button type="button" class="btn btn--ghost speed" data-rate="1.5">1.5×</button>
                <button type="button" class="btn btn--ghost speed" data-rate="2">2×</button>
            </div>
        </div>
    <?php elseif ($call['recording_status'] === 'error'): ?>
        <div class="banner banner--danger" style="margin:16px;">
            <span class="banner__icon"><?= icon('alert') ?></span>
            <div><strong>Recording could not be fetched</strong><?= e($call['last_error'] ?: 'NetSapiens did not return a recording for this call.') ?></div>
        </div>
    <?php else: ?>
        <div class="banner banner--info" style="margin:16px;">
            <span class="banner__icon"><?= icon('inbox') ?></span>
            <div><strong>No recording on file</strong>This call has no recording available in NetSapiens, or recording was disabled for the user.</div>
        </div>
    <?php endif; ?>

    <?php if (in_array($call['status'], ['pending', 'processing', 'failed'], true)): ?>
        <div class="banner <?= $call['status'] === 'failed' ? 'banner--danger' : 'banner--warn' ?>" style="margin:16px;">
            <span class="banner__icon"><?= icon('clock') ?></span>
            <div>
                <strong><?= e(ucfirst((string) $call['status'])) ?> · attempt <?= (int) $call['attempts'] ?></strong>
                <?php if ((int) $call['attempts'] === 0 && (string) $call['status'] === 'pending'): ?>
                    The background worker has not picked this call up yet. It runs every few minutes once
                    the scheduled task from the README is in place, or immediately with
                    <code>php bin\console.php process</code>.
                <?php else: ?>
                    <?= e($call['last_error'] !== '' ? $call['last_error'] : 'Call Intelligence is still working on this call.') ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="layout-2col" style="margin-top:16px;">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2>Summary</h2></div>
            <div class="card__body">
                <?php if (($call['transcript_summary'] ?? '') !== ''): ?>
                    <div class="summary-box"><?= nl2br(e((string) $call['transcript_summary'])) ?></div>
                <?php else: ?>
                    <p class="muted" style="margin:0;font-size:13px;">
                        <?= $call['transcript_status'] === 'pending' ? 'Waiting for NetSapiens Call Intelligence…' : 'No summary available for this call.' ?>
                    </p>
                <?php endif; ?>

                <?php if ($topics !== []): ?>
                    <div style="margin-top:14px;">
                        <div class="label" style="margin-bottom:7px;">Topics</div>
                        <div class="row" style="gap:6px;">
                            <?php foreach ($topics as $topic): ?>
                                <span class="chip"><?= e((string) $topic) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($sentimentTotal > 0): ?>
                    <div style="margin-top:16px;">
                        <div class="label" style="margin-bottom:7px;">Sentiment</div>
                        <div class="sentiment-bar">
                            <?php if ($positive > 0): ?><span class="pos" style="width:<?= (int) round($positive / $sentimentTotal * 100) ?>%"></span><?php endif; ?>
                            <?php if ($neutral > 0): ?><span class="neu" style="width:<?= (int) round($neutral / $sentimentTotal * 100) ?>%"></span><?php endif; ?>
                            <?php if ($negative > 0): ?><span class="neg" style="width:<?= (int) round($negative / $sentimentTotal * 100) ?>%"></span><?php endif; ?>
                        </div>
                        <div class="sentiment-legend">
                            <span><i style="background:var(--positive)"></i><?= $positive ?>% positive</span>
                            <span><i style="background:var(--neutral)"></i><?= $neutral ?>% neutral</span>
                            <span><i style="background:var(--negative)"></i><?= $negative ?>% negative</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>Transcript</h2>
                <span class="muted"><?= count($segments) ?> segment(s)</span>
            </div>
            <?php if (!$hasTranscript): ?>
                <div class="empty">
                    <div class="empty__icon"><?= icon('wave') ?></div>
                    <h3>No transcript yet</h3>
                    <p><?= e($call['transcript_status'] === 'unavailable' ? 'Call Intelligence did not return a transcript for this call.' : 'The transcript will appear as soon as Call Intelligence finishes.') ?></p>
                </div>
            <?php else: ?>
                <div class="card__body" style="padding-bottom:8px;">
                    <input type="search" data-transcript-search placeholder="Search inside the transcript…" aria-label="Search transcript">
                </div>
                <div class="transcript">
                    <?php foreach ($segments as $segment): ?>
                        <div class="segment segment--<?= e(strtolower((string) ($segment['sentiment'] ?? 'neutral'))) ?>" data-segment>
                            <div class="segment__speaker">
                                <?= e((string) ($segment['speaker'] ?? 'Unknown')) ?>
                                <div class="muted" style="font-weight:500;font-size:10.5px;"><?= e((string) ($segment['start'] ?? '')) ?></div>
                            </div>
                            <div class="segment__text"><?= e((string) ($segment['comment'] ?? '')) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($segments === [] && (string) $call['transcript_text'] !== ''): ?>
                        <div class="segment">
                            <div class="segment__speaker">Transcript</div>
                            <div class="segment__text"><?= nl2br(e((string) $call['transcript_text'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="stack sticky-side">
        <div class="card">
            <div class="card__head"><h2>Why it was flagged</h2></div>
            <div class="card__body">
                <?php if ($matches === []): ?>
                    <p class="muted" style="margin:0;font-size:12.5px;">No keyword matched this call.</p>
                <?php else: ?>
                    <?php foreach ($matches as $match): ?>
                        <div class="match-item">
                            <div class="match-item__body">
                                <div class="match-item__label">
                                    <?= e((string) $match['keyword_label']) ?>
                                    <span class="<?= e(severity_class((string) $match['severity'])) ?>"><?= e((string) $match['severity']) ?></span>
                                </div>
                                <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-top:3px;"><?= e((string) $match['field']) ?></div>
                                <div class="match-item__snippet">“<?= e((string) $match['snippet']) ?>”</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2>Alerts</h2></div>
            <div class="card__body">
                <div class="row" style="justify-content:space-between;">
                    <span class="badge badge--<?= $alertTone ?>"><?= e($alertLabel) ?></span>
                    <span class="muted" style="font-size:11.5px;"><?= (int) $call['match_count'] ?> match(es)</span>
                </div>

                <?php if (!$mailReady): ?>
                    <div class="hint" style="margin-top:10px;color:var(--mixed);">
                        SMTP is not configured yet, so alert emails cannot be delivered.
                        <?php if (Context::can('admin')): ?>
                            <a href="<?= e(path_url('settings')) ?>">Configure it</a>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($notifications !== []): ?>
                    <div class="hr"></div>
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $statusTone = match ($notification['status']) {
                            'sent' => 'success',
                            'queued' => 'warning',
                            default => 'danger',
                        };
                        ?>
                        <div class="row" style="align-items:flex-start;gap:8px;margin-bottom:8px;">
                            <span class="badge badge--<?= $statusTone ?>"><?= e((string) $notification['status']) ?></span>
                            <div style="min-width:0;">
                                <div class="truncate" style="font-size:12px;"><?= e((string) $notification['recipients']) ?></div>
                                <small class="muted"><?= e(tz_date($notification['sent_at'] ?? $notification['created_at'], 'M j, g:i A', $timezone)) ?></small>
                                <?php if (($notification['error'] ?? '') !== ''): ?>
                                    <div class="text-danger" style="font-size:11px;"><?= e(Str::limit((string) $notification['error'], 120)) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ((int) $call['match_count'] > 0): ?>
                    <p class="muted" style="margin:10px 0 0;font-size:12px;">No email was generated for this call.</p>
                <?php endif; ?>
            </div>
        </div>

        <details class="panel">
            <summary><?= icon('shield') ?> Technical details</summary>
            <div class="panel__body">
                <dl style="margin:0;font-size:12px;line-height:1.9;">
                    <div class="row--between row"><dt class="muted">Call ID</dt><dd class="mono truncate" style="margin:0;max-width:190px;" title="<?= e((string) $call['call_id']) ?>"><?= e((string) $call['call_id']) ?></dd></div>
                    <div class="row--between row"><dt class="muted">Orig / Term</dt><dd class="mono"><?= e(Str::limit((string) $call['orig_call_id'], 14)) ?> / <?= e(Str::limit((string) ($call['term_call_id'] ?? ''), 14)) ?></dd></div>
                    <div class="row--between row"><dt class="muted">Transcript job</dt><dd class="mono"><?= e($call['transcript_job_id'] !== '' ? $call['transcript_job_id'] : '—') ?></dd></div>
                    <div class="row--between row"><dt class="muted">Status</dt><dd><?= e((string) $call['status']) ?> · <?= (int) $call['attempts'] ?> attempt(s)</dd></div>
                    <div class="row--between row"><dt class="muted">Processed</dt><dd><?= e(tz_date($call['processed_at'] ?? null, 'M j, g:i A', $timezone)) ?></dd></div>
                    <div class="row--between row"><dt class="muted">Recording</dt><dd><?= e((string) $call['recording_status']) ?></dd></div>
                </dl>
                <?php if (($call['last_error'] ?? '') !== ''): ?>
                    <div class="hr"></div>
                    <div class="text-warn" style="font-size:11.5px;"><?= e((string) $call['last_error']) ?></div>
                <?php endif; ?>
            </div>
        </details>
    </div>
</div>
