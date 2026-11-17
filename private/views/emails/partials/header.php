<?php
/** @var string $appName */
/** @var string $eyebrow */
/** @var string|null $domainLabel */
/** @var string $headline */
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;border-radius:14px 14px 0 0;">
    <tr>
        <td style="padding:22px 26px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-family:'Segoe UI',Arial,sans-serif;color:#ffffff;font-size:19px;font-weight:700;letter-spacing:.2px;">
                        <?= e($appName) ?>
                        <span style="display:inline-block;margin-left:10px;padding:3px 9px;border-radius:99px;background:rgba(99,102,241,.25);color:#c7d2fe;font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;"><?= e($eyebrow) ?></span>
                    </td>
                    <?php if (!empty($domainLabel)): ?>
                        <td align="right" style="font-family:'Segoe UI',Arial,sans-serif;color:#94a3b8;font-size:12px;"><?= e($domainLabel) ?></td>
                    <?php endif; ?>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top:10px;font-family:'Segoe UI',Arial,sans-serif;color:#e2e8f0;font-size:15px;font-weight:600;">
                        <?= e($headline) ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
