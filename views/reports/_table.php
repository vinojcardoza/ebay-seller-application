<?php

/**
 * Partial: sales breakdown table for a report, newest period first, with a totals row.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $periodHeading Heading for the period column, e.g. "Date".
 * @var list<array{label: string, from: string, to: string, orders: int, units: int, sold: float, fees: float,
 *     net: float, cost: float, shipping: float, profit: float}> $rows Report rows, oldest first.
 * @var array{orders: int, units: int, sold: float, fees: float, net: float, cost: float, shipping: float,
 *     profit: float} $totals
 */

/**
 * Format an amount in pounds, with the minus sign before the symbol.
 */
$money = static fn (float $amount): string => ($amount < 0 ? '−£' : '£') . number_format(abs($amount), 2);

/**
 * Profit as a share of net sales, or a dash when there were no net sales.
 */
$margin = static function (array $row): string {
    if ($row['net'] <= 0) {
        return '—';
    }

    $share = round($row['profit'] / $row['net'] * 100, 1);

    return ($share < 0 ? '−' : '') . number_format(abs($share), 1) . '%';
};

$amountColumns = [
    'sold' => 'Gross sales',
    'fees' => 'Fees',
    'net' => 'Net sales',
    'cost' => 'Item cost',
    'shipping' => 'Postage & packaging',
];
?>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                <th scope="col"><?php echo $this->e($periodHeading); ?></th>
                <th scope="col" class="text-end">Orders</th>
                <th scope="col" class="text-end">Units</th>
                <?php foreach ($amountColumns as $label) : ?>
                    <th scope="col" class="text-end text-nowrap"><?php echo $this->e($label); ?></th>
                <?php endforeach; ?>
                <th scope="col" class="text-end">Profit</th>
                <th scope="col" class="text-end">Margin</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($rows) as $row) : ?>
                <tr<?php echo $row['orders'] === 0 ? ' class="text-body-secondary"' : ''; ?>>
                    <td class="text-nowrap">
                        <?php if ($row['orders'] > 0) : ?>
                            <a href="/orders?<?php echo $this->e(http_build_query(['from' => $row['from'], 'to' => $row['to']])); ?>">
                                <?php echo $this->e($row['label']); ?>
                            </a>
                        <?php else : ?>
                            <?php echo $this->e($row['label']); ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo number_format($row['orders']); ?></td>
                    <td class="text-end"><?php echo number_format($row['units']); ?></td>
                    <?php foreach (array_keys($amountColumns) as $key) : ?>
                        <td class="text-end text-nowrap"><?php echo $this->e($money($row[$key])); ?></td>
                    <?php endforeach; ?>
                    <td class="text-end text-nowrap<?php echo $row['profit'] < 0 ? ' text-danger' : ''; ?>">
                        <?php echo $this->e($money($row['profit'])); ?>
                    </td>
                    <td class="text-end"><?php echo $this->e($margin($row)); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="fw-semibold">
                <th scope="row">Total</th>
                <td class="text-end"><?php echo number_format($totals['orders']); ?></td>
                <td class="text-end"><?php echo number_format($totals['units']); ?></td>
                <?php foreach (array_keys($amountColumns) as $key) : ?>
                    <td class="text-end text-nowrap"><?php echo $this->e($money($totals[$key])); ?></td>
                <?php endforeach; ?>
                <td class="text-end text-nowrap<?php echo $totals['profit'] < 0 ? ' text-danger' : ''; ?>">
                    <?php echo $this->e($money($totals['profit'])); ?>
                </td>
                <td class="text-end"><?php echo $this->e($margin($totals)); ?></td>
            </tr>
        </tfoot>
    </table>
</div>
