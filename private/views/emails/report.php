<?php
/**
 * @var array<string, mixed> $schedule
 * @var array<string, mixed> $report
 * @var string $domainLabel
 * @var string $timezone
 * @var string $appName
 * @var string $portalUrl
 */
$stats = $report['stats'];
$sentiment = $report['sentiment'];
$rangeLabel = (string) $report['range']['label'];
$topKeywords = $report['topKeywords'];
$topTopics = $report['topTopics'];
$maxKeyword = 1;
foreach ($topKeywords as $keyword) {
    $maxKeyword = max($maxKeyword, (int) $keyword['count']);
}
$eyebrow = ucfirst((string) $schedule['frequency']) . ' report';
$headline = $rangeLabel . ' · ' . $domainLabel;
$kpis = [
    ['Calls reviewed', (string) $stats['calls'], '#0f172a'],
    ['Keyword matches', (string) $stats['matches'], '#4338ca'],
    ['Calls flagged', (string) $stats['alert_calls'], '#b45309'],
    ['With recording', (string) $stats['with_recording'], '#0f766e'],
    ['Avg duration', \DynaPulse\Support\Time::humanDuration((int) $stats['avg_duration']), '#0f172a'],
    ['Talk time', \DynaPulse\Support\Time::humanDuration((int) $stats['talk_seconds']), '#0f172a'],
];
$totalSentiment = max(1, $sentiment['positive'] + $sentiment['neutral'] + $sentiment['negative'] + $sentiment['mixed']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($appName) ?> report — <?= e($rangeLabel) ?></title>
</head>
<body style="margin:0;padding:24px 12px;background:#eef2f7;">
<div style="max-width:680px;margin:0 auto;">

    <?php require __DIR__ . '/partials/header.php'; ?>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
        <tr>
            <td style="padding:22px 26px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <?php foreach (array_chunk($kpis, 3) as $rowIndex => $row): ?>
                        <tr>
                            <?php foreach ($row as $kpi): ?>
                                <td width="33.33%" style="padding:0 8px 12px 0;vertical-align:top;">
                                    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:13px 14px;background:#f8fafc;font-family:'Segoe UI',Arial,sans-serif;">
                                        <div style="font-size:11px;letter-spacing:.7px;text-transform:uppercase;color:#94a3b8;font-weight:700;"><?= e($kpi[0]) ?></div>
                                        <div style="font-size:22px;font-weight:700;color:<?= e($kpi[2]) ?>;margin-top:3px;"><?= e($kpi[1]) ?></div>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                            <?php if (count($row) < 3): ?>
                                <td width="33.33%">&nbsp;</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding:6px 26px 0;">
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:8px;">Sentiment mix</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:99px;overflow:hidden;">
                    <tr>
                        <?php if ($sentiment['positive'] > 0): ?><td width="<?= (int) round($sentiment['positive'] / $totalSentiment * 100) ?>%" style="background:#16a34a;height:10px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                        <?php if ($sentiment['neutral'] > 0): ?><td width="<?= (int) round($sentiment['neutral'] / $totalSentiment * 100) ?>%" style="background:#cbd5e1;height:10px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                        <?php if ($sentiment['mixed'] > 0): ?><td width="<?= (int) round($sentiment['mixed'] / $totalSentiment * 100) ?>%" style="background:#f59e0b;height:10px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                        <?php if ($sentiment['negative'] > 0): ?><td width="<?= (int) round($sentiment['negative'] / $totalSentiment * 100) ?>%" style="background:#dc2626;height:10px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
                    </tr>
                </table>
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#64748b;margin-top:7px;">
                    <span style="color:#15803d;font-weight:600;"><?= $sentiment['positive'] ?>% positive</span> ·
                    <?= $sentiment['neutral'] ?>% neutral ·
                    <?= $sentiment['mixed'] ?>% mixed ·
                    <span style="color:#b91c1c;font-weight:600;"><?= $sentiment['negative'] ?>% negative</span>
                </div>
            </td>
        </tr>

        <tr>
            <td style="padding:22px 26px 0;">
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:8px;">Top keywords</div>
                <?php if ($topKeywords === []): ?>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#94a3b8;padding:6px 0;">No keyword matches in this period.</div>
                <?php endif; ?>
                <?php foreach ($topKeywords as $keyword): ?>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:7px;">
                        <tr>
                            <td width="45%" style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#334155;font-weight:600;">
                                <?= e((string) $keyword['label']) ?>
                                <span style="font-weight:500;font-size:10px;letter-spacing:.6px;text-transform:uppercase;color:#94a3b8;margin-left:4px;"><?= e((string) $keyword['severity']) ?></span>
                            </td>
                            <td width="45%" style="padding:0 8px;vertical-align:middle;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                    <td width="<?= (int) round((int) $keyword['count'] / $maxKeyword * 100) ?>%" style="background:#6366f1;height:7px;border-radius:99px;font-size:0;line-height:0;">&nbsp;</td>
                                    <td style="background:#eef2ff;height:7px;border-radius:99px;font-size:0;line-height:0;">&nbsp;</td>
                                </tr></table>
                            </td>
                            <td width="10%" align="right" style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#0f172a;font-weight:700;"><?= (int) $keyword['count'] ?></td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            </td>
        </tr>

        <tr>
            <td style="padding:18px 26px 0;">
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:8px;">Top topics</div>
                <?php if ($topTopics === []): ?>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#94a3b8;">No topics captured in this period.</div>
                <?php endif; ?>
                <?php foreach ($topTopics as $topic): ?>
                    <span style="display:inline-block;margin:0 6px 6px 0;padding:5px 10px;border-radius:99px;background:#f1f5f9;color:#334155;font-family:'Segoe UI',Arial,sans-serif;font-size:12px;font-weight:600;">
                        <?= e((string) $topic['topic']) ?> <span style="color:#94a3b8;font-weight:500;">· <?= (int) $topic['count'] ?></span>
                    </span>
                <?php endforeach; ?>
            </td>
        </tr>

        <tr>
            <td style="padding:20px 26px 0;">
                <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:8px;">Latest alerts</div>
                <?php if ($report['recentAlerts'] === []): ?>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#94a3b8;">Nothing flagged in this period.</div>
                <?php endif; ?>
                <?php foreach ($report['recentAlerts'] as $alert): ?>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1px solid #f1f5f9;">
                        <tr>
                            <td style="padding:9px 0;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#334155;">
                                <strong style="color:#0f172a;"><?= e((string) $alert['keyword_label']) ?></strong>
                                <span style="color:#94a3b8;"> · <?= e(\DynaPulse\Support\Str::prettyPhone((string) $alert['caller_number'])) ?> → <?= e(\DynaPulse\Support\Str::prettyPhone((string) $alert['called_number'])) ?></span>
                                <div style="font-size:12px;color:#64748b;margin-top:2px;"><?= e(\DynaPulse\Support\Str::limit((string) $alert['snippet'], 110)) ?></div>
                            </td>
                            <td align="right" style="padding:9px 0;font-family:'Segoe UI',Arial,sans-serif;font-size:11px;color:#94a3b8;white-space:nowrap;vertical-align:top;">
                                <?= e(tz_date((string) $alert['started_at'], 'M j, g:i A', $timezone)) ?>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            </td>
        </tr>

        <tr>
            <td style="padding:24px 26px 28px;">
                <a href="<?= e($portalUrl) ?>" style="display:inline-block;padding:13px 22px;border-radius:10px;background:#4f46e5;color:#ffffff;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;text-decoration:none;">
                    Open reports in <?= e($appName) ?>
                </a>
                <?php if ((int) $schedule['include_csv'] === 1): ?>
                    <div style="font-family:'Segoe UI',Arial,sans-serif;font-size:11px;color:#94a3b8;margin-top:10px;">
                        Call and alert spreadsheets are attached to this email.
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php require __DIR__ . '/partials/footer.php'; ?>

</div>
</body>
</html>
