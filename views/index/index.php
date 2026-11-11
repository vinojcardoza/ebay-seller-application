<?php

/**
 * View: Dashboard with KPI cards, this month's sales summary, order type breakdown and recent orders.
 *
 * @var \League\Plates\Template\Template $this
 * @var array{orders: int, revenue: float, items: int, products: int} $stats
 * @var array{label: string, from: string, to: string, current: array<string, float|int>,
 *     previous: array<string, float|int>} $month
 * @var list<array{order_type: string, total: int|string}> $orderTypes
 * @var list<array{id: int|string, order_reference: string, order_date: string, platform: string,
 *     order_type: string, full_name: string|null, total: string|null}> $recentOrders
 */

$this->layout('layout', ['title' => 'Dashboard']);

$kpis = [
    ['label' => 'Total orders', 'value' => number_format($stats['orders']), 'icon' => 'ti-shopping-cart', 'tint' => 'primary'],
    ['label' => 'Sales', 'value' => '£' . number_format($stats['revenue'], 2), 'icon' => 'ti-currency-pound', 'tint' => 'success'],
    ['label' => 'Items sold', 'value' => number_format($stats['items']), 'icon' => 'ti-box', 'tint' => 'warning'],
    ['label' => 'Active products', 'value' => number_format($stats['products']), 'icon' => 'ti-package', 'tint' => 'info'],
];

$current = $month['current'];
$previous = $month['previous'];

/**
 * Format an amount in pounds, with the minus sign before the symbol.
 */
$money = static fn (float $amount): string => ($amount < 0 ? '−£' : '£') . number_format(abs($amount), 2);

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
        <div class="col-sm-6 col-xl-3">
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
            <div class="card-header">
                <h2>Recent orders</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Platform</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order) : ?>
                            <tr>
                                <td class="text-nowrap"><?php echo $this->e($order['order_reference']); ?></td>
                                <td class="text-nowrap"><?php echo $this->e($order['full_name'] ?? '—'); ?></td>
                                <td><?php echo $this->e($order['platform']); ?></td>
                                <td>
                                    <span class="badge tint-<?php echo $typeTint($order['order_type']); ?>">
                                        <?php echo $this->e($order['order_type']); ?>
                                    </span>
                                </td>
                                <td class="text-nowrap"><?php echo $this->e(date('d M Y', (int) strtotime($order['order_date']))); ?></td>
                                <td class="text-end">£<?php echo number_format((float) ($order['total'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
