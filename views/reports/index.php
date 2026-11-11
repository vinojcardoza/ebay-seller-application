<?php

/**
 * View: Sales reports — last 30 days by day and the last 12 months by month,
 * each with summary figures and a net sales chart; the monthly report also
 * has a breakdown table.
 *
 * @var \League\Plates\Template\Template $this
 * @var array{rows: list<array<string, mixed>>, totals: array<string, int|float>, chart: array<string, mixed>,
 *     from: \DateTimeImmutable, to: \DateTimeImmutable} $daily
 * @var array{rows: list<array<string, mixed>>, totals: array<string, int|float>, chart: array<string, mixed>,
 *     from: \DateTimeImmutable, to: \DateTimeImmutable} $monthly
 */

$this->layout('layout', ['title' => 'Reports', 'breadcrumbs' => ['Reports' => null]]);

/**
 * Format an amount in pounds, with the minus sign before the symbol.
 */
$money = static fn (int|float $amount): string => ($amount < 0 ? '−£' : '£') . number_format(abs($amount), 2);

/**
 * Format a y-axis amount in whole pounds.
 */
$tickMoney = static fn (int $amount): string => '£' . number_format($amount);

/**
 * Headline figures for a report's totals.
 *
 * @return list<array{label: string, value: string, icon: string, tint: string}>
 */
$kpis = static fn (array $totals): array => [
    ['label' => 'Net sales', 'value' => $money($totals['net']), 'icon' => 'ti-currency-pound', 'tint' => 'success'],
    ['label' => 'Orders', 'value' => number_format($totals['orders']), 'icon' => 'ti-shopping-cart', 'tint' => 'primary'],
    ['label' => 'Profit', 'value' => $money($totals['profit']), 'icon' => 'ti-trending-up',
        'tint' => $totals['profit'] < 0 ? 'danger' : 'info'],
    ['label' => 'Average order', 'value' => $money($totals['orders'] > 0 ? $totals['net'] / $totals['orders'] : 0),
        'icon' => 'ti-receipt', 'tint' => 'warning'],
];

$sections = [
    [
        'id' => 'daily',
        'heading' => 'Last 30 days',
        'period' => $daily['from']->format('j M') . ' – ' . $daily['to']->format('j M Y'),
        'chartTitle' => 'Net sales per day',
        'tableTitle' => null,
        'report' => $daily,
    ],
    [
        'id' => 'monthly',
        'heading' => 'Monthly',
        'period' => $monthly['from']->format('M Y') . ' – ' . $monthly['to']->format('M Y'),
        'chartTitle' => 'Net sales per month',
        'tableTitle' => 'Monthly breakdown',
        'periodHeading' => 'Month',
        'report' => $monthly,
    ],
];
?>
<?php foreach ($sections as $section) : ?>
    <section aria-labelledby="report-<?php echo $section['id']; ?>">
        <div class="d-flex align-items-baseline gap-2 mb-3">
            <h2 class="h5 mb-0" id="report-<?php echo $section['id']; ?>"><?php echo $this->e($section['heading']); ?></h2>
            <span class="text-body-secondary small"><?php echo $this->e($section['period']); ?></span>
        </div>

        <div class="row">
            <?php foreach ($kpis($section['report']['totals']) as $kpi) : ?>
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
            <div class="card-body">
                <?php $this->insert('feedbacks/_column_chart', [
                    'id' => 'sales-' . $section['id'],
                    'title' => $section['chartTitle'],
                    'subtitle' => $section['period'],
                    'unit' => '',
                    'series' => $section['report']['chart'],
                    'format' => $money,
                    'tickFormat' => $tickMoney,
                    'valueHeading' => 'Net sales',
                ]) ?>
            </div>
        </div>

        <?php if ($section['tableTitle'] !== null) : ?>
            <div class="card">
                <div class="card-header">
                    <h2><?php echo $this->e($section['tableTitle']); ?></h2>
                </div>
                <?php $this->insert('reports/_table', [
                    'periodHeading' => $section['periodHeading'],
                    'rows' => $section['report']['rows'],
                    'totals' => $section['report']['totals'],
                ]) ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
