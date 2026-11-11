<?php

/**
 * View: Dashboard with KPI cards, this month's sales summary, low stock products and order type breakdown.
 *
 * @var \League\Plates\Template\Template $this
 * @var array{orders: int, revenue: float, profit: float, items: int, products: int} $stats All-time figures;
 *     profit = net sales − item costs − postage − packaging.
 * @var array{label: string, from: string, to: string, current: array<string, float|int>,
 *     previous: array<string, float|int>} $month
 * @var list<array{order_type: string, total: int|string}> $orderTypes
 * @var array{total: int, out: int, products: list<array{id: int, sku: string, title: string, stock: int,
 *     threshold: int|null, pending: int, state: string}>} $lowStock Active products low or out of stock,
 *     lowest first (see StockLevels::lowStock()).
 */

$this->layout('layout', ['title' => 'Dashboard']);

/**
 * Format an amount in pounds, with the minus sign before the symbol.
 */
$money = static fn (float $amount): string => ($amount < 0 ? '−£' : '£') . number_format(abs($amount), 2);

$kpis = [
    ['label' => 'Total orders', 'value' => number_format($stats['orders']), 'icon' => 'ti-shopping-cart', 'tint' => 'primary'],
    ['label' => 'Sales', 'value' => $money($stats['revenue']), 'icon' => 'ti-currency-pound', 'tint' => 'success'],
    ['label' => 'Total profit', 'value' => $money($stats['profit']), 'icon' => 'ti-trending-up',
        'tint' => $stats['profit'] < 0 ? 'danger' : 'info'],
    ['label' => 'Items sold', 'value' => number_format($stats['items']), 'icon' => 'ti-box', 'tint' => 'warning'],
    ['label' => 'Active products', 'value' => number_format($stats['products']), 'icon' => 'ti-package', 'tint' => 'info'],
];

$current = $month['current'];
$previous = $month['previous'];

/**
 * Average net sale per order for a period's totals.
 */
$average = static fn (array $totals): float => $totals['orders'] > 0 ? $totals['net'] / $totals['orders'] : 0.0;

$monthStats = [
    ['label' => 'Net sales', 'value' => $money($current['net']), 'now' => $current['net'], 'before' => $previous['net']],
    ['label' => 'Orders', 'value' => number_format($current['orders']), 'now' => $current['orders'], 'before' => $previous['orders']],
    ['label' => 'Profit', 'value' => $money($current['profit']), 'now' => $current['profit'], 'before' => $previous['profit']],
    ['label' => 'Average order', 'value' => $money($average($current)), 'now' => $average($current), 'before' => $average($previous)],
];

$monthBreakdown = [
    'Units sold' => number_format($current['units']),
    'Gross sales' => $money($current['sold']),
    'Fees' => $money($current['fees']),
    'Item cost' => $money($current['cost']),
    'Postage & packaging' => $money($current['shipping']),
    'Margin' => $current['net'] > 0 ? number_format($current['profit'] / $current['net'] * 100, 1) . '%' : '—',
];

$typeTotal = max(1, array_sum(array_map(static fn (array $row): int => (int) $row['total'], $orderTypes)));

/**
 * Badge colour for each order type.
 */
$typeTint = static fn (string $type): string => match ($type) {
    'Purchase' => 'success',
    'Refund', 'Cancelled' => 'danger',
    'Return', 'Replacement' => 'warning',
    default => 'info',
};
?>
<div class="row">
    <?php foreach ($kpis as $kpi) : ?>
        <div class="col-sm-6 col-lg-4 col-xxl">
            <div class="card kpi-card">
                <div class="card-body">
                    <span class="kpi-icon tint-<?php echo $kpi['tint']; ?>"><i class="ti <?php echo $kpi['icon']; ?>" aria-hidden="true"></i></span>
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
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2>Sales this month <span class="text-body-secondary fw-normal">· <?php echo $this->e($month['label']); ?></span></h2>
        <a class="btn btn-light-primary btn-sm"
           href="/orders?<?php echo $this->e(http_build_query(['from' => $month['from'], 'to' => $month['to']])); ?>">
            <i class="ti ti-list" aria-hidden="true"></i> View orders
        </a>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <?php foreach ($monthStats as $stat) : ?>
                <?php
                $change = $stat['before'] != 0 ? ($stat['now'] - $stat['before']) / abs($stat['before']) * 100 : null;
                ?>
                <div class="col-6 col-lg-3">
                    <p class="kpi-label mt-0"><?php echo $this->e($stat['label']); ?></p>
                    <p class="kpi-value mb-1"><?php echo $this->e($stat['value']); ?></p>
                    <?php if ($change === null) : ?>
                        <small class="text-body-secondary">No sales same time last month</small>
                    <?php else : ?>
                        <small>
                            <span class="badge tint-<?php echo $change >= 0 ? 'success' : 'danger'; ?>">
                                <i class="ti ti-trending-<?php echo $change >= 0 ? 'up' : 'down'; ?>" aria-hidden="true"></i>
                                <?php echo ($change >= 0 ? '+' : '−') . number_format(abs($change), 1); ?>%
                            </span>
                            <span class="text-body-secondary">vs same time last month</span>
                        </small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <hr>
        <dl class="row g-3 mb-0">
            <?php foreach ($monthBreakdown as $label => $value) : ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <dt class="kpi-label mt-0 fw-normal"><?php echo $this->e($label); ?></dt>
                    <dd class="mb-0 fw-semibold"><?php echo $this->e($value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</div>

<div class="row">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h2>
                    Low stock
                    <?php if ($lowStock['total'] > 0) : ?>
                        <span class="text-body-secondary fw-normal">
                            · <?php echo number_format($lowStock['total']); ?> product<?php echo $lowStock['total'] === 1 ? '' : 's'; ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <?php if ($lowStock['total'] > 0) : ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($lowStock['out'] > 0) : ?>
                            <a class="btn btn-light-danger btn-sm" href="/products?stock=out&amp;status=active">
                                <?php echo number_format($lowStock['out']); ?> out of stock
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-light-primary btn-sm" href="/products?stock=low&amp;status=active">
                            <i class="ti ti-list" aria-hidden="true"></i> View low stock
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($lowStock['products'] === []) : ?>
                <div class="empty-state">
                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                    Every active product is above its low stock level.
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">In stock</th>
                                <th class="text-end">Low at</th>
                                <th class="text-end">On order</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStock['products'] as $product) : ?>
                                <tr>
                                    <td>
                                        <a href="/product/<?php echo $product['id']; ?>"><?php echo $this->e($product['title']); ?></a>
                                        <div class="small text-body-secondary"><?php echo $this->e($product['sku']); ?></div>
                                    </td>
                                    <td class="text-end fw-semibold<?php echo $product['stock'] <= 0 ? ' text-danger' : ''; ?>">
                                        <?php echo number_format($product['stock']); ?>
                                    </td>
                                    <td class="text-end"><?php echo $product['threshold'] !== null ? number_format($product['threshold']) : '—'; ?></td>
                                    <td class="text-end">
                                        <?php if ($product['pending'] > 0) : ?>
                                            <a href="/purchases?<?php echo $this->e(http_build_query(['status' => 0, 'q' => $product['sku']])); ?>">
                                                <?php echo number_format($product['pending']); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="text-body-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($product['state'] === 'out') : ?>
                                            <span class="badge tint-danger">Out of stock</span>
                                        <?php else : ?>
                                            <span class="badge tint-warning">Low stock</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($lowStock['total'] > count($lowStock['products'])) : ?>
                    <div class="card-body border-top small text-body-secondary">
                        Showing the <?php echo count($lowStock['products']); ?> lowest of <?php echo number_format($lowStock['total']); ?>.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">
                <h2>Orders by type</h2>
            </div>
            <div class="card-body">
                <?php foreach ($orderTypes as $row) : ?>
                    <?php $share = (int) $row['total'] / $typeTotal * 100; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><?php echo $this->e($row['order_type']); ?></span>
                            <span class="text-body-secondary"><?php echo number_format((int) $row['total']); ?></span>
                        </div>
                        <div class="progress" role="progressbar" style="height: 6px"
                             aria-label="<?php echo $this->e($row['order_type']); ?> share of orders"
                             aria-valuenow="<?php echo round($share, 1); ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-<?php echo $typeTint($row['order_type']); ?>"
                                 style="width: <?php echo max(0.5, round($share, 2)); ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
