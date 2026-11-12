<?php

/**
 * View: Orders list with filters, totals and pagination, followed by recent
 * eBay orders that are not imported yet.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<array<string, mixed>> $orders Current page rows (id, reference, date, platform, type,
 *     name, ebayUser, tracking, delivered, items, units, gross (sold price before fees), net, profit, and products: a list of
 *     {title, sku, quantity, cost} for each item line, cost being the unit item cost).
 * @var array{orders: int, net: string, cost: string, shipping: string, profit: string} $summary
 * @var int $total Orders matching the filters.
 * @var int $page Current page.
 * @var int $pages Number of pages.
 * @var array{q: string, platform: string, type: string, from: string, to: string, tracking: string} $filters
 * @var list<string> $platforms
 * @var list<string> $types
 * @var array<string, string> $trackingFilters
 * @var string|null $notice
 * @var string|null $error Error message for the last action.
 * @var array{connected: bool, previews: list<\App\Ebay\Import\ImportPreview>, error: string|null,
 *     days: int, dayOptions: list<int>, trackingDays: int} $ebay Recent eBay orders not imported yet.
 */

use App\Ebay\Import\ImportPreview;
use App\Ebay\Import\RecentOrders;

$this->layout('layout', ['title' => 'Orders', 'breadcrumbs' => ['Orders' => null]]);

$filtered = implode('', $filters) !== '';

// The eBay look-back period is kept in links and forms only when it is not the default.
$ebayDays = $ebay['days'] !== RecentOrders::DEFAULT_DAYS ? $ebay['days'] : null;

/**
 * URL of this page with the current filters and the given page number.
 */
$pageUrl = static fn (int $number): string => '/orders?' . http_build_query(array_filter(
    $filters + ['page' => $number > 1 ? $number : null, 'days' => $ebayDays],
    static fn ($value): bool => $value !== null && $value !== ''
));

/**
 * Badge class for how an eBay line's SKU matched.
 */
$matchBadge = static fn (?string $match): string => match ($match) {
    ImportPreview::MATCH_PRODUCT => 'tint-success',
    ImportPreview::MATCH_CUSTOM_SKU => 'tint-info',
    default => 'tint-danger',
};

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
    <form method="post" action="/orders/sync-tracking"
          title="Carrier, tracking number, shipped and delivered dates for eBay orders from the last <?php echo $ebay['trackingDays']; ?> days that are not delivered">
        <button type="submit" class="btn btn-light-primary" <?php echo $ebay['connected'] ? '' : 'disabled'; ?>>
            <i class="ti ti-truck-delivery" aria-hidden="true"></i> Sync tracking
        </button>
    </form>
    <a class="btn btn-primary" href="/orders/create"><i class="ti ti-plus" aria-hidden="true"></i> Add order</a>
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

<div class="card" id="ebay-orders">
    <div class="card-header">
        <h2>eBay orders not imported</h2>
        <form method="get" action="/orders#ebay-orders" class="d-flex align-items-center gap-2">
            <?php foreach (array_filter($filters, static fn (string $value): bool => $value !== '') as $name => $value) : ?>
                <input type="hidden" name="<?php echo $this->e($name); ?>" value="<?php echo $this->e($value); ?>">
            <?php endforeach; ?>
            <?php if ($page > 1) : ?>
                <input type="hidden" name="page" value="<?php echo $page; ?>">
            <?php endif; ?>
            <label class="small text-nowrap" for="days">Created in the last</label>
            <select class="form-select form-select-sm" id="days" name="days" style="min-width: 7.5rem"
                    onchange="this.form.submit()">
                <?php foreach ($ebay['dayOptions'] as $option) : ?>
                    <option value="<?php echo $option; ?>" <?php echo $option === $ebay['days'] ? 'selected' : ''; ?>>
                        <?php echo $option; ?> day<?php echo $option === 1 ? '' : 's'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-light-secondary">Show</button></noscript>
        </form>
    </div>

    <?php if (!$ebay['connected']) : ?>
        <div class="empty-state">
            <i class="ti ti-plug-connected-x" aria-hidden="true"></i>
            <span><a href="/ebay">Connect your eBay account</a> to see recent eBay orders.</span>
        </div>
    <?php elseif ($ebay['error'] !== null) : ?>
        <div class="card-body">
            <div class="alert alert-danger mb-0" role="alert"><?php echo $this->e($ebay['error']); ?></div>
        </div>
    <?php elseif ($ebay['previews'] === []) : ?>
        <div class="empty-state">
            <i class="ti ti-circle-check" aria-hidden="true"></i>
            Every eBay order from the last <?php echo $ebay['days']; ?> day<?php echo $ebay['days'] === 1 ? '' : 's'; ?> is imported.
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Buyer</th>
                        <th>Items</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th class="text-end">Import</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($ebay['previews'], true) as $preview) : ?>
                        <tr>
                            <td class="text-nowrap">
                                <?php echo $this->e($preview->orderId); ?>
                                <?php if ($preview->created !== null) : ?>
                                    <div class="small text-body-secondary">
                                        <?php echo $preview->created->format('d M Y H:i'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $this->e($preview->buyer !== '' ? $preview->buyer : '—'); ?></td>
                            <td>
                                <?php foreach ($preview->lines as $line) : ?>
                                    <div>
                                        <span class="badge <?php echo $matchBadge($line['match']); ?>">
                                            <?php echo $this->e($line['sku'] !== '' ? $line['sku'] : 'No SKU'); ?>
                                        </span>
                                        × <?php echo $line['quantity']; ?>
                                        <span class="small text-body-secondary"><?php echo $this->e($line['title']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php echo $this->e($preview->currency === 'GBP' ? '£' : $preview->currency . ' '); ?><?php echo $this->e($preview->total); ?>
                            </td>
                            <td>
                                <?php if ($preview->canImport()) : ?>
                                    <span class="badge tint-success">Ready</span>
                                <?php else : ?>
                                    <span class="badge tint-danger">Needs attention</span>
                                    <div class="small text-body-secondary"><?php echo $this->e(implode(' ', $preview->problems())); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($preview->canImport()) : ?>
                                    <form method="post" action="/orders/ebay/<?php echo $this->e(rawurlencode($preview->orderId)); ?>"
                                          class="d-inline-flex align-items-center gap-2">
                                        <input type="hidden" name="days" value="<?php echo $ebay['days']; ?>">
                                        <label class="small text-nowrap">
                                            <input type="checkbox" name="promoted" value="1"> Promoted
                                        </label>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="ti ti-download" aria-hidden="true"></i> Import
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
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
                <?php if ($ebayDays !== null) : ?>
                    <input type="hidden" name="days" value="<?php echo $ebayDays; ?>">
                <?php endif; ?>
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
                        <th>Products</th>
                        <th class="text-end">Units</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Net</th>
                        <th class="text-end">Postage</th>
                        <th class="text-end">Packaging</th>
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
                            <td>
                                <?php if ($order['products'] === []) : ?>
                                    <span class="text-body-secondary">—</span>
                                <?php else : ?>
                                    <ul class="list-unstyled mb-0 small">
                                        <?php foreach ($order['products'] as $product) : ?>
                                            <li title="<?php echo $this->e($product['sku']); ?>">
                                                <span class="fw-semibold"><?php echo $product['quantity']; ?> ×</span>
                                                <?php echo $this->e($product['title']); ?>
                                                <span class="text-body-secondary text-nowrap">@ <?php echo $pounds($product['cost']); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($order['items'] === 0) : ?>
                                    <span class="badge tint-warning">None</span>
                                <?php else : ?>
                                    <?php echo $order['units']; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap"><?php echo $pounds($order['gross']); ?></td>
                            <td class="text-end text-nowrap"><?php echo $pounds($order['net']); ?></td>
                            <td class="text-end text-nowrap"><?php echo $pounds($order['postage']); ?></td>
                            <td class="text-end text-nowrap"><?php echo $pounds($order['packaging']); ?></td>
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