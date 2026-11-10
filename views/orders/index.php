<?php

/**
 * View: Orders list with filters, totals and pagination.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<array<string, mixed>> $orders Current page rows (id, reference, date, platform, type,
 *     name, ebayUser, tracking, delivered, items, units, net, profit).
 * @var array{orders: int, net: string, cost: string, shipping: string, profit: string} $summary
 * @var int $total Orders matching the filters.
 * @var int $page Current page.
 * @var int $pages Number of pages.
 * @var array{q: string, platform: string, type: string, from: string, to: string, tracking: string} $filters
 * @var list<string> $platforms
 * @var list<string> $types
 * @var array<string, string> $trackingFilters
 * @var string|null $notice
 */

$this->layout('layout', ['title' => 'Orders', 'breadcrumbs' => ['Orders' => null]]);

$filtered = implode('', $filters) !== '';

/**
 * URL of this page with the current filters and the given page number.
 */
$pageUrl = static fn (int $number): string => '/orders?' . http_build_query(array_filter(
    $filters + ['page' => $number > 1 ? $number : null],
    static fn ($value): bool => $value !== null && $value !== ''
));

/**
 * Badge class for an order type.
 */
$typeBadge = static fn (string $type): string => match ($type) {
    'Purchase' => 'tint-success',
    'Refund', 'Cancelled', 'Return' => 'tint-danger',
    'Replacement' => 'tint-warning',
    default => 'tint-info',
};

/**
 * Format a signed amount as pounds, e.g. "-3.27" → "−£3.27".
 */
$pounds = static fn (string $amount): string => ((float) $amount < 0 ? '−£' : '£') . number_format(abs((float) $amount), 2);

$kpis = [
    ['label' => $filtered ? 'Orders (filtered)' : 'Orders', 'value' => number_format($summary['orders']), 'icon' => 'ti-shopping-cart', 'tint' => 'primary'],
    ['label' => 'Net sales (after fees)', 'value' => $pounds($summary['net']), 'icon' => 'ti-currency-pound', 'tint' => 'success'],
    ['label' => 'Item costs + shipping', 'value' => $pounds((string) ((float) $summary['cost'] + (float) $summary['shipping'])), 'icon' => 'ti-receipt', 'tint' => 'warning'],
    ['label' => 'Estimated profit', 'value' => $pounds($summary['profit']), 'icon' => 'ti-chart-line', 'tint' => 'info'],
];
?>
<?php $this->start('actions') ?>
    <a class="btn btn-primary" href="/orders/create"><i class="ti ti-plus" aria-hidden="true"></i> Add order</a>
<?php $this->stop() ?>

<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
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

<div class="card">
    <div class="card-header">
        <h2>Orders</h2>
        <span class="text-body-secondary"><?php echo number_format($total); ?> order<?php echo $total === 1 ? '' : 's'; ?></span>
    </div>
    <div class="card-body border-bottom">
        <form method="get" action="/orders" class="row g-2 align-items-end">
            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" maxlength="100" value="<?php echo $this->e($filters['q']); ?>"
                       placeholder="Reference, buyer, email, postcode, tracking">
            </div>
            <div class="col-sm-4 col-md-3 col-xl-1">
                <label class="form-label" for="platform">Platform</label>
                <select class="form-select" id="platform" name="platform">
                    <option value="">All</option>
                    <?php foreach ($platforms as $platform) : ?>
                        <option value="<?php echo $platform; ?>" <?php echo $filters['platform'] === $platform ? 'selected' : ''; ?>><?php echo $platform; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-md-3 col-xl-2">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    <?php foreach ($types as $type) : ?>
                        <option value="<?php echo $type; ?>" <?php echo $filters['type'] === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-md-4 col-xl-2">
                <label class="form-label" for="tracking">Tracking</label>
                <select class="form-select" id="tracking" name="tracking">
                    <option value="">All</option>
                    <?php foreach ($trackingFilters as $value => $label) : ?>
                        <option value="<?php echo $value; ?>" <?php echo $filters['tracking'] === $value ? 'selected' : ''; ?>><?php echo $this->e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-1">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?php echo $this->e($filters['from']); ?>">
            </div>
            <div class="col-6 col-md-4 col-xl-1">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?php echo $this->e($filters['to']); ?>">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-light-primary"><i class="ti ti-filter" aria-hidden="true"></i> Filter</button>
                <?php if ($filtered) : ?>
                    <a class="btn btn-light-secondary" href="/orders">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($orders === []) : ?>
        <div class="empty-state">
            <i class="ti ti-shopping-cart-off" aria-hidden="true"></i>
            <?php echo $filtered ? 'No orders match these filters.' : 'No orders yet.'; ?>
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Buyer</th>
                        <th>Type</th>
                        <th class="text-end">Items</th>
                        <th class="text-end">Net</th>
                        <th class="text-end">Profit</th>
                        <th>Tracking</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order) : ?>
                        <tr>
                            <td class="text-nowrap"><?php echo $order['date']->format('d M Y H:i'); ?></td>
                            <td class="text-nowrap">
                                <a href="/orders/<?php echo $order['id']; ?>"><?php echo $this->e($order['reference']); ?></a>
                                <div class="small text-body-secondary"><?php echo $this->e($order['platform']); ?></div>
                            </td>
                            <td>
                                <?php echo $this->e($order['name'] !== '' ? $order['name'] : '—'); ?>
                                <?php if ($order['ebayUser'] !== '') : ?>
                                    <div class="small">
                                        <a class="text-body-secondary" href="/orders?<?php echo $this->e(http_build_query(['q' => $order['ebayUser']])); ?>"><?php echo $this->e($order['ebayUser']); ?></a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $typeBadge($order['type']); ?>"><?php echo $this->e($order['type']); ?></span></td>
                            <td class="text-end">
                                <?php if ($order['items'] === 0) : ?>
                                    <span class="badge tint-warning">None</span>
                                <?php else : ?>
                                    <?php echo $order['units']; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap"><?php echo $pounds($order['net']); ?></td>
                            <td class="text-end text-nowrap"><?php echo $pounds($order['profit']); ?></td>
                            <td class="text-nowrap">
                                <?php if ($order['delivered']) : ?>
                                    <span class="badge tint-success">Delivered</span>
                                <?php elseif ($order['tracking'] !== '') : ?>
                                    <span class="small"><?php echo $this->e($order['tracking']); ?></span>
                                <?php else : ?>
                                    <span class="text-body-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1) : ?>
            <nav class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2" aria-label="Order pages">
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
