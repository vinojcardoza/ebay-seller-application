<?php

/**
 * View: Buyer feedback — summary, daily and monthly charts, and the filtered list.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\Feedback> $feedbacks Current page of entries.
 * @var int $total Entries matching the filters.
 * @var int $page Current page number.
 * @var int $pages Number of pages.
 * @var string $type Comment type filter ('' for all).
 * @var string $user Buyer filter ('' for all).
 * @var list<string> $types Comment types that can be filtered on.
 * @var array{total: int, positive: int, neutral: int, negative: int, positiveShare: float|null} $summary
 * @var array<string, mixed>|null $daily Daily chart series, or null when there is no feedback.
 * @var array<string, mixed>|null $monthly Monthly chart series, or null when there is no feedback.
 * @var string|null $notice Success message.
 * @var string|null $error Error message.
 */

$this->layout('layout', ['title' => 'Feedback', 'breadcrumbs' => ['Feedback' => null]]);

/**
 * Badge class for a comment type.
 */
$typeBadge = static fn (?string $commentType): string => match ($commentType) {
    'Positive' => 'tint-success',
    'Negative' => 'tint-danger',
    'Neutral' => 'tint-warning',
    default => 'tint-info',
};

/**
 * URL of this page with the current filters and the given page number.
 */
$pageUrl = static fn (int $number): string => '/feedbacks?' . http_build_query(
    array_filter(['type' => $type, 'user' => $user, 'page' => $number > 1 ? $number : null])
);

$share = $summary['positiveShare'];
$kpis = [
    ['label' => 'Total feedback', 'value' => number_format($summary['total']), 'icon' => 'ti-message-star', 'tint' => 'primary'],
    [
        'label' => 'Positive' . ($share !== null ? ' (' . number_format($share, 1) . '%)' : ''),
        'value' => number_format($summary['positive']),
        'icon' => 'ti-mood-smile',
        'tint' => 'success',
    ],
    ['label' => 'Neutral', 'value' => number_format($summary['neutral']), 'icon' => 'ti-mood-empty', 'tint' => 'warning'],
    ['label' => 'Negative', 'value' => number_format($summary['negative']), 'icon' => 'ti-mood-sad', 'tint' => 'danger'],
];
?>
<?php $this->start('actions') ?>
    <form method="post" action="/feedbacks/import">
        <button type="submit" class="btn btn-primary">
            <i class="ti ti-download" aria-hidden="true"></i> Import from eBay
        </button>
    </form>
<?php $this->stop() ?>

<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger" role="alert"><?php echo $this->e($error); ?></div>
<?php endif; ?>

<div class="row">
    <?php foreach ($kpis as $kpi) : ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <span class="kpi-icon tint-<?php echo $kpi['tint']; ?>">
                        <i class="ti <?php echo $kpi['icon']; ?>" aria-hidden="true"></i>
                    </span>
                    <div>
                        <p class="kpi-value"><?php echo $this->e($kpi['value']); ?></p>
                        <p class="kpi-label"><?php echo $this->e($kpi['label']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($daily !== null && $monthly !== null) : ?>
    <div class="row">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body">
                    <?php $this->insert('feedbacks/_column_chart', [
                        'id' => 'feedback-daily',
                        'title' => 'Feedback per day',
                        'subtitle' => $daily['from']->format('j M') . ' – ' . $daily['to']->format('j M Y'),
                        'unit' => 'feedback',
                        'series' => $daily,
                    ]) ?>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-body">
                    <?php $this->insert('feedbacks/_column_chart', [
                        'id' => 'feedback-monthly',
                        'title' => 'Feedback per month',
                        'subtitle' => $monthly['from']->format('M Y') . ' – ' . $monthly['to']->format('M Y'),
                        'unit' => 'feedback',
                        'series' => $monthly,
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Feedback</h2>
        <span class="text-body-secondary"><?php echo number_format($total); ?> entr<?php echo $total === 1 ? 'y' : 'ies'; ?></span>
    </div>
    <div class="card-body border-bottom">
        <form method="get" action="/feedbacks" class="row g-2 align-items-end">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All types</option>
                    <?php foreach ($types as $option) : ?>
                        <option value="<?php echo $this->e($option); ?>" <?php echo $option === $type ? 'selected' : ''; ?>>
                            <?php echo $this->e($option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-5 col-lg-4">
                <label class="form-label" for="user">Buyer (eBay user ID)</label>
                <input class="form-control" id="user" name="user" value="<?php echo $this->e($user); ?>" maxlength="100">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-light-primary">
                    <i class="ti ti-filter" aria-hidden="true"></i> Filter
                </button>
                <?php if ($type !== '' || $user !== '') : ?>
                    <a class="btn btn-light-secondary" href="/feedbacks">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($feedbacks === []) : ?>
        <div class="empty-state">
            <i class="ti ti-message-off" aria-hidden="true"></i>
            <?php echo $type !== '' || $user !== '' ? 'No feedback matches these filters.' : 'No feedback yet.'; ?>
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Buyer</th>
                        <th>Type</th>
                        <th>Comment</th>
                        <th>Item</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedbacks as $feedback) : ?>
                        <?php
                        $time = $feedback->getCommentTime();
                        $buyer = $feedback->getEbayUserId();
                        $price = $feedback->getItemPrice();
                        ?>
                        <tr>
                            <td class="text-nowrap"><?php echo $time !== null ? $time->format('d M Y H:i') : '—'; ?></td>
                            <td class="text-nowrap">
                                <?php if ($buyer !== null && $buyer !== '') : ?>
                                    <a href="/feedbacks?<?php echo $this->e(http_build_query(['user' => $buyer])); ?>">
                                        <?php echo $this->e($buyer); ?>
                                    </a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $typeBadge($feedback->getCommentType()); ?>">
                                    <?php echo $this->e($feedback->getCommentType() ?? 'Unknown'); ?>
                                </span>
                            </td>
                            <td class="feedback-comment"><?php echo $this->e($feedback->getCommentText() ?? ''); ?></td>
                            <td class="feedback-item">
                                <?php echo $this->e($feedback->getItemTitle() ?? ''); ?>
                                <?php if ($price !== null) : ?>
                                    <div class="small text-body-secondary">£<?php echo $this->e($price); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1) : ?>
            <nav class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2" aria-label="Feedback pages">
                <span class="small text-body-secondary">Page <?php echo $page; ?> of <?php echo number_format($pages); ?></span>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item<?php echo $page === 1 ? ' disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $this->e($pageUrl($page - 1)); ?>">Previous</a>
                    </li>
                    <?php foreach (array_unique([1, max(1, $page - 1), $page, min($pages, $page + 1), $pages]) as $number) : ?>
                        <li class="page-item<?php echo $number === $page ? ' active' : ''; ?>">
                            <a class="page-link" href="<?php echo $this->e($pageUrl($number)); ?>"
                                <?php echo $number === $page ? 'aria-current="page"' : ''; ?>><?php echo $number; ?></a>
                        </li>
                    <?php endforeach; ?>
                    <li class="page-item<?php echo $page === $pages ? ' disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $this->e($pageUrl($page + 1)); ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
