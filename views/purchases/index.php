<?php

/**
 * View: Purchases list with filters, totals and pagination.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\Purchase> $purchases Current page.
 * @var array{count: int, units: int, spend: string, pending: int} $summary Totals for the filtered set.
 * @var int $total Purchases matching the filters.
 * @var int $page Current page.
 * @var int $pages Number of pages.
 * @var array{status: int|null, supplier: \App\Entities\Supplier|null, q: string} $filters
 * @var array<int, string> $suppliers Supplier names keyed by ID.
 * @var array<int, string> $statuses Status labels keyed by value.
 * @var string|null $notice Success message.
 * @var array{ids: list<int>, date: string, errors: array<string, string>} $bulk Bulk delivery form:
 *     selected purchase IDs, delivery date (Y-m-d) and errors keyed `ids` / `date`.
 * @var string $listQuery Current filters and page as a query string, kept by the bulk form.
 * @var string $today Today's date (Y-m-d), the latest allowed delivery date.
 */

use App\Entities\Purchase;

$this->layout('layout', ['title' => 'Purchases', 'breadcrumbs' => ['Purchases' => null]]);

$filtered = $filters['status'] !== null || $filters['supplier'] !== null || $filters['q'] !== '';

/**
 * URL of this page with the current filters and the given page number.
 */
$pageUrl = static fn (int $number): string => '/purchases?' . http_build_query(array_filter([
    'status' => $filters['status'],
    'supplier' => $filters['supplier']?->getId(),
    'q' => $filters['q'],
    'page' => $number > 1 ? $number : null,
], static fn ($value): bool => $value !== null && $value !== ''));

$pendingOnPage = array_filter(
    $purchases,
    static fn (Purchase $purchase): bool => $purchase->getStatus() !== Purchase::STATUS_DELIVERED
);
$bulkAction = '/purchases/receive' . ($listQuery !== '' ? '?' . $listQuery : '');

$kpis = [
    ['label' => $filtered ? 'Spend (filtered)' : 'Total spend', 'value' => '£' . number_format((float) $summary['spend'], 2), 'icon' => 'ti-currency-pound', 'tint' => 'primary'],
    ['label' => 'Purchases', 'value' => number_format($summary['count']), 'icon' => 'ti-receipt', 'tint' => 'info'],
    ['label' => 'Units bought', 'value' => number_format($summary['units']), 'icon' => 'ti-box', 'tint' => 'success'],
    ['label' => 'Awaiting delivery', 'value' => number_format($summary['pending']), 'icon' => 'ti-truck', 'tint' => 'warning'],
];
?>
<?php $this->start('actions') ?>
    <a class="btn btn-primary" href="/purchases/create"><i class="ti ti-plus" aria-hidden="true"></i> Add purchase</a>
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
        <h2>Purchases</h2>
        <span class="text-body-secondary"><?php echo number_format($total); ?> purchase<?php echo $total === 1 ? '' : 's'; ?></span>
    </div>
    <div class="card-body border-bottom">
        <form method="get" action="/purchases" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-4">
                <label class="form-label" for="q">Product SKU or title</label>
                <input class="form-control" id="q" name="q" maxlength="100" value="<?php echo $this->e($filters['q']); ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="supplier">Supplier</label>
                <select class="form-select" id="supplier" name="supplier">
                    <option value="">All</option>
                    <?php foreach ($suppliers as $id => $name) : ?>
                        <option value="<?php echo $id; ?>" <?php echo $filters['supplier']?->getId() === $id ? 'selected' : ''; ?>>
                            <?php echo $this->e($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach ($statuses as $value => $label) : ?>
                        <option value="<?php echo $value; ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>>
                            <?php echo $this->e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-light-primary"><i class="ti ti-filter" aria-hidden="true"></i> Filter</button>
                <?php if ($filtered) : ?>
                    <a class="btn btn-light-secondary" href="/purchases">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($pendingOnPage !== []) : ?>
        <form id="bulk-receive" method="post" action="<?php echo $this->e($bulkAction); ?>"
              class="card-body border-bottom row g-2 align-items-end" novalidate>
            <?php if (isset($bulk['errors']['ids'])) : ?>
                <div class="col-12">
                    <div class="alert alert-danger mb-0" role="alert"><?php echo $this->e($bulk['errors']['ids']); ?></div>
                </div>
            <?php endif; ?>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="received_date">Delivery date</label>
                <input class="form-control<?php echo isset($bulk['errors']['date']) ? ' is-invalid' : ''; ?>" type="date"
                       id="received_date" name="received_date" max="<?php echo $today; ?>" required
                       value="<?php echo $this->e($bulk['date']); ?>"
                    <?php echo isset($bulk['errors']['date']) ? 'aria-invalid="true" aria-describedby="received_date-error"' : ''; ?>>
                <?php if (isset($bulk['errors']['date'])) : ?>
                    <div class="invalid-feedback" id="received_date-error"><?php echo $this->e($bulk['errors']['date']); ?></div>
                <?php endif; ?>
            </div>
            <div class="col-auto d-flex align-items-center gap-3">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-truck-delivery" aria-hidden="true"></i> Mark selected delivered
                </button>
                <span class="small text-body-secondary" data-check-count="receive" aria-live="polite">
                    Tick pending purchases below to select them.
                </span>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($purchases === []) : ?>
        <div class="empty-state">
            <i class="ti ti-receipt-off" aria-hidden="true"></i>
            <?php echo $filtered ? 'No purchases match these filters.' : 'No purchases yet.'; ?>
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 1%">
                            <?php if ($pendingOnPage !== []) : ?>
                                <input class="form-check-input" type="checkbox" data-check-all="receive"
                                       aria-label="Select all pending purchases on this page">
                            <?php endif; ?>
                        </th>
                        <th>Purchased</th>
                        <th>Supplier</th>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Per item</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchases as $purchase) : ?>
                        <?php
                        $product = $purchase->getProduct();
                        $received = $purchase->getReceivedDate();
                        $delivered = $purchase->getStatus() === Purchase::STATUS_DELIVERED;
                        $quantity = $purchase->getQuantity();
                        ?>
                        <tr>
                            <td class="text-center">
                                <?php if (!$delivered) : ?>
                                    <input class="form-check-input" type="checkbox" form="bulk-receive" name="ids[]"
                                           value="<?php echo $purchase->getId(); ?>" data-check-group="receive"
                                           aria-label="Select purchase of <?php echo $this->e($product?->getTitle() ?? 'unknown product'); ?>"
                                        <?php echo in_array($purchase->getId(), $bulk['ids'], true) ? 'checked' : ''; ?>>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?php echo $purchase->getPurchasedDate()->format('d M Y'); ?></td>
                            <td class="text-nowrap"><?php echo $this->e($purchase->getSupplier()?->getSupplierName() ?? '—'); ?></td>
                            <td>
                                <?php if ($product !== null) : ?>
                                    <a href="/product/<?php echo $product->getId(); ?>"><?php echo $this->e($product->getTitle()); ?></a>
                                    <div class="small text-body-secondary"><?php echo $this->e($product->getSku()); ?></div>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo number_format($quantity); ?></td>
                            <td class="text-end text-nowrap">£<?php echo $this->e($purchase->getTotalPrice()); ?></td>
                            <td class="text-end text-nowrap">
                                £<?php echo $quantity > 0 ? number_format((float) $purchase->getTotalPrice() / $quantity, 2) : '—'; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($delivered) : ?>
                                    <span class="badge tint-success">Delivered</span>
                                    <?php if ($received !== null) : ?>
                                        <div class="small text-body-secondary"><?php echo $received->format('d M Y'); ?></div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="badge tint-warning">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if (!$delivered) : ?>
                                    <form method="post" class="d-inline" action="/purchases/<?php echo $purchase->getId(); ?>/receive">
                                        <button type="submit" class="btn btn-sm btn-light-primary">
                                            <i class="ti ti-check" aria-hidden="true"></i> Mark delivered
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-light-secondary" href="/purchases/<?php echo $purchase->getId(); ?>/edit">
                                    <i class="ti ti-edit" aria-hidden="true"></i> Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1) : ?>
            <nav class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2" aria-label="Purchase pages">
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
