<?php
/**
 * @var array<string, mixed> $call
 * @var list<array<string, mixed>> $matches
 * @var string $timezone
 * @var string $appName
 * @var string|null $audioUrl
 * @var string $portalUrl
 * @var bool $includeTranscript
 * @var string $domainLabel
 * @var int $alertCount
 */
$severity = 'low';
foreach ($matches as $match) {
    if (\DynaPulse\Support\Str::severityRank((string) $match['severity']) > \DynaPulse\Support\Str::severityRank($severity)) {
        $severity = (string) $match['severity'];
    }
}
$severityColour = match ($severity) {
    'critical' => '#b91c1c',
    'high' => '#dc2626',
    'medium' => '#d97706',
    default => '#2563eb',
};
$positive = (int) $call['sentiment_positive'];
$neutral = (int) $call['sentiment_neutral'];
$negative = (int) $call['sentiment_negative'];
$sentimentTotal = max(1, $positive + $neutral + $negative);
$topics = (array) $call['transcript_topics'];
$started = \DynaPulse\Support\Time::display((string) $call['started_at'], $timezone);
$transcriptExcerpt = trim((string) $call['transcript_text']);
if ($includeTranscript && mb_strlen($transcriptExcerpt) > 900) {
    $transcriptExcerpt = mb_substr($transcriptExcerpt, 0, 900) . '…';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($appName) ?> keyword alert</title>
</head>
<body style="margin:0;padding:24px 12px;background:#eef2f7;">
<div style="max-width:640px;margin:0 auto;">

    <?php $eyebrow = 'Keyword alert'; $headline = sprintf('%d trigger%s on a call — %s', $alertCount, $alertCount === 1 ? '' : 's', $started); ?>
    <?php require __DIR__ . '/partials/header.php'; ?>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
        <tr>
            <td style="padding:24px 26px 6px;">
                <span style="display:inline-block;padding:5px 11px;border-radius:99px;background:<?= $severityColour ?>14;color:<?= $severityColour ?>;font-family:'Segoe UI',Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;">
                    <?= e($severity) ?> severity
                </span>
                <span style="margin-left:8px;font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#64748b;">
                    <?= e(ucfirst((string) $call['direction'])) ?> call · <?= e(\DynaPulse\Support\Time::humanDuration((int) $call['duration_seconds'])) ?>
                </span>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 26px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                    <tr>
                        <td style="padding:14px 16px;font-family:'Segoe UI',Arial,sans-serif;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="50%" style="padding-bottom:12px;vertical-align:top;">
                                        <div style="font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:700;">Caller</div>
                                        <div style="font-size:17px;color:#0f172a;font-weight:600;margin-top:2px;"><?= e(\DynaPulse\Support\Str::prettyPhone((string) $call['caller_number'])) ?></div>
                                        <?php if ((string) $call['caller_name'] !== ''): ?>
                                            <div style="font-size:12px;color:#64748b;"><?= e((string) $call['caller_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td width="50%" style="padding-bottom:12px;vertical-align:top;">
                                        <div style="font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:700;">Called</div>
                                        <div style="font-size:17px;color:#0f172a;font-weight:600;margin-top:2px;"><?= e(\DynaPulse\Support\Str::prettyPhone((string) $call['called_number'])) ?></div>
                                        <?php if ((string) $call['answered_by'] !== ''): ?>
                                            <div style="font-size:12px;color:#64748b;">Answered by <?= e((string) $call['answered_by']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="vertical-align:top;">
                                        <div style="font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:700;">Date &amp; time</div>
                                        <div style="font-size:13px;color:#334155;margin-top:2px;"><?= e($started) ?> <span style="color:#94a3b8;">(<?= e($timezone) ?>)</span></div>
                                    </td>
                                    <td style="vertical-align:top;">
                                        <div style="font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:700;">Recording</div>
                                        <div style="font-size:13px;color:#334155;margin-top:2px;">
                                            <?= (int) $call['has_recording'] === 1 ? 'Available' : 'Not available' ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding:22px 26px 0;">
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:10px;">
                    What triggered this alert
                </div>
                <?php foreach ($matches as $match): ?>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-left:3px solid <?= $severityColour ?>;border-radius:8px;margin-bottom:8px;background:#ffffff;">
                        <tr>
                            <td style="padding:11px 14px;font-family:'Segoe UI',Arial,sans-serif;">
                                <div style="font-size:14px;font-weight:650;color:#0f172a;">
                                    <?= e((string) $match['keyword_label']) ?>
                                    <span style="font-weight:500;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-left:6px;"><?= e((string) $match['field']) ?> · <?= e((string) $match['severity']) ?></span>
                                </div>
                                <?php if ((string) $match['snippet'] !== ''): ?>
                                    <div style="font-size:13px;color:#475569;line-height:20px;margin-top:4px;font-style:italic;">“<?= e((string) $match['snippet']) ?>”</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            </td>
        </tr>

        <?php if ((string) $call['transcript_summary'] !== ''): ?>
            <tr>
                <td style="padding:16px 26px 0;">
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:6px;">Summary</div>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:14px;line-height:22px;color:#334155;">
                        <?= nl2br(e((string) $call['transcript_summary'])) ?>
                    </div>
                </td>
            </tr>
        <?php endif; ?>

        <?php if ($topics !== []): ?>
            <tr>
                <td style="padding:16px 26px 0;">
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:8px;">Topics</div>
                    <?php foreach ($topics as $topic): ?>
                        <span style="display:inline-block;margin:0 6px 6px 0;padding:5px 10px;border-radius:99px;background:#eef2ff;color:#4338ca;font-family:'Segoe UI',Arial,sans-serif;font-size:12px;font-weight:600;"><?= e((string) $topic) ?></span>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endif; ?>

        <tr>
            <td style="padding:16px 26px 0;">
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:8px;">Sentiment</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:99px;overflow:hidden;">
                    <tr>
                        <?php if ($positive > 0): ?><td width="<?= (int) round($positive / $sentimentTotal * 100) ?>%" style="background:#16a34a;height:9px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                        <?php if ($neutral > 0): ?><td width="<?= (int) round($neutral / $sentimentTotal * 100) ?>%" style="background:#cbd5e1;height:9px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                        <?php if ($negative > 0): ?><td width="<?= (int) round($negative / $sentimentTotal * 100) ?>%" style="background:#dc2626;height:9px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                    </tr>
                </table>
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#64748b;margin-top:6px;">
                    <span style="color:#15803d;font-weight:600;"><?= $positive ?>% positive</span> ·
                    <?= $neutral ?>% neutral ·
                    <span style="color:#b91c1c;font-weight:600;"><?= $negative ?>% negative</span>
                </div>
            </td>
        </tr>

        <?php if ($includeTranscript && $transcriptExcerpt !== ''): ?>
            <tr>
                <td style="padding:18px 26px 0;">
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:6px;">Transcript excerpt</div>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:21px;color:#475569;white-space:pre-line;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;"><?= e($transcriptExcerpt) ?></div>
                </td>
            </tr>
        <?php endif; ?>

        <tr>
            <td style="padding:24px 26px 28px;">
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <?php if ($audioUrl !== null): ?>
                            <td style="padding-right:10px;">
                                <a href="<?= e($audioUrl) ?>" style="display:inline-block;padding:13px 22px;border-radius:10px;background:#4f46e5;color:#ffffff;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;text-decoration:none;">
                                    ▶&nbsp; Listen to the recording
                                </a>
                            </td>
                        <?php endif; ?>
                        <td>
                            <a href="<?= e($portalUrl) ?>" style="display:inline-block;padding:13px 22px;border-radius:10px;background:#ffffff;border:1px solid #cbd5e1;color:#0f172a;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;text-decoration:none;">
                                Open in <?= e($appName) ?>
                            </a>
                        </td>
                    </tr>
                </table>
                <?php if ($audioUrl !== null): ?>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:11px;color:#94a3b8;margin-top:10px;">
                        Recording links expire after 30 days and are unique to this alert.
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php require __DIR__ . '/partials/footer.php'; ?>

</div>
</body>
</html>
