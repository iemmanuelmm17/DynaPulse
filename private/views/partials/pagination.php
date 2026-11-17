<?php
/**
 * @var array{page:int, pages:int, per_page:int, total:int, offset:int} $pagination
 */
use DynaPulse\Http\Context;

$query = Context::$request?->queryAll() ?? [];
unset($query['page']);
$base = Context::$request?->path() ?? '/';

$pageUrl = static function (int $page) use ($query, $base): string {
    $query['page'] = $page;
    $filtered = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

    return $base . '?' . http_build_query($filtered);
};

$first = $pagination['offset'] + 1;
$last = min($pagination['total'], $pagination['offset'] + $pagination['per_page']);
?>
<div class="pagination">
    <span>
        Showing <?= number_format($first) ?>–<?= number_format($last) ?>
        of <?= number_format($pagination['total']) ?>
    </span>
    <div class="pagination__links">
        <?php if ($pagination['page'] > 1): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($pageUrl($pagination['page'] - 1)) ?>"><?= icon('chevron-left') ?> Prev</a>
        <?php endif; ?>
        <span class="muted">Page <?= (int) $pagination['page'] ?> of <?= (int) $pagination['pages'] ?></span>
        <?php if ($pagination['page'] < $pagination['pages']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($pageUrl($pagination['page'] + 1)) ?>">Next <?= icon('chevron-right') ?></a>
        <?php endif; ?>
    </div>
</div>
