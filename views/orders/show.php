<?php

/**
 * View: One order with buyer, shipping, items, totals, transactions and the add-item form.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Order $order
 * @var list<\App\Entities\OrderItem> $items
 * @var array<string, string|int> $totals sold, fees, net, cost, postage, packaging, profit, units.
 * @var list<\App\Entities\Transaction> $transactions
 * @var array<string, array<int, string>> $productGroups
 * @var array<string, string> $itemValues
 * @var array<string, string> $itemErrors
 * @var string|null $notice
 * @var string|null $error
 * @var array<string, string> $shippingServices Shipping service labels keyed by code.
 */

$this->layout('layout', [
    'title' => 'Order ' . $order->getOrderReference(),
    'breadcrumbs' => ['Orders' => '/orders', $order->getOrderReference() => null],
]);

$base = '/orders/' . $order->getId();

/**
 * Format a signed amount as pounds, e.g. "-3.27" → "−£3.27".
 */
$pounds = static fn (?string $amount): string => $amount === null || $amount === ''
    ? '—' : ((float) $amount < 0 ? '−£' : '£') . number_format(abs((float) $amount), 2);

/**
 * Format an optional date, or a dash.
 */
$date = static fn (?\DateTimeInterface $value): string => $value?->format('d M Y H:i') ?? '—';

$address = array_filter([
    $order->getStreet1(),
    $order->getStreet2(),
    $order->getCity(),
    $order->getState(),
    $order->getPostcode(),
    $order->getCountryName() ?? $order->getCountry(),
]);
$service = $order->getShippingService();
?>
<?php $this->start('actions') ?>
    <a class="btn btn-light-secondary" href="/orders"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <a class="btn btn-primary" href="<?php echo $base; ?>/edit"><i class="ti ti-edit" aria-hidden="true"></i> Edit</a>
<?php $this->stop() ?>

<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger" role="alert"><?php echo $this->e($error); ?></div>
<?php endif; ?>

<div class="row">
    <?php
    $tiles = [
        ['label' => 'Net (after fees)', 'value' => $pounds((string) $totals['net'])],
        ['label' => 'Item costs', 'value' => $pounds((string) $totals['cost'])],
        ['label' => 'Postage + packaging', 'value' => $pounds((string) ((float) $totals['postage'] + (float) $totals['packaging']))],
        ['label' => 'Profit', 'value' => $pounds((string) $totals['profit'])],
    ];
    ?>
    <?php foreach ($tiles as $tile) : ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <div>
                        <p class="kpi-value"><?php echo $this->e($tile['value']); ?></p>
                        <p class="kpi-label"><?php echo $this->e($tile['label']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <h2>Order</h2>
                <span class="badge tint-info"><?php echo $this->e($order->getOrderType()); ?></span>
            </div>
            <div class="card-body">
                <dl class="row detail-list mb-0">
                    <dt class="col-sm-4">Reference</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getOrderReference()); ?></dd>
                    <dt class="col-sm-4">Platform</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getPlatform()); ?></dd>
                    <dt class="col-sm-4">Ordered</dt>
                    <dd class="col-sm-8"><?php echo $date($order->getOrderDate()); ?></dd>
                    <dt class="col-sm-4">Shipping service</dt>
                    <dd class="col-sm-8"><?php echo $this->e($service !== null ? ($shippingServices[$service] ?? $service) : '—'); ?></dd>
                    <dt class="col-sm-4">Carrier</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getShippingCompany() ?? '—'); ?></dd>
                    <dt class="col-sm-4">Tracking</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getTrackingCode() ?: '—'); ?></dd>
                    <dt class="col-sm-4">Shipped</dt>
                    <dd class="col-sm-8"><?php echo $date($order->getShippingDatetime()); ?></dd>
                    <dt class="col-sm-4">Delivered</dt>
                    <dd class="col-sm-8"><?php echo $date($order->getShippingDeliveryDatetime()); ?></dd>
                    <?php if (($order->getAdditionalNotes() ?? '') !== '') : ?>
                        <dt class="col-sm-4">Notes</dt>
                        <dd class="col-sm-8"><?php echo nl2br($this->e($order->getAdditionalNotes() ?? '')); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <h2>Buyer</h2>
            </div>
            <div class="card-body">
                <dl class="row detail-list mb-0">
                    <dt class="col-sm-4">Name</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getFullName() ?? '—'); ?></dd>
                    <dt class="col-sm-4">eBay user</dt>
                    <dd class="col-sm-8">
                        <?php if (($order->getEbayUserId() ?? '') !== '') : ?>
                            <a href="/orders?<?php echo $this->e(http_build_query(['q' => $order->getEbayUserId()])); ?>"><?php echo $this->e($order->getEbayUserId() ?? ''); ?></a>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getEmail() ?? '—'); ?></dd>
                    <dt class="col-sm-4">Phone</dt>
                    <dd class="col-sm-8"><?php echo $this->e($order->getPhone() ?? '—'); ?></dd>
                    <dt class="col-sm-4">Address</dt>
                    <dd class="col-sm-8">
                        <?php echo $address === [] ? '—' : implode('<br>', array_map(fn ($line): string => $this->e((string) $line), $address)); ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Items</h2>
        <span class="text-body-secondary"><?php echo count($items); ?> line<?php echo count($items) === 1 ? '' : 's'; ?>, <?php echo $totals['units']; ?> unit<?php echo $totals['units'] === 1 ? '' : 's'; ?></span>
    </div>
    <?php if ($items === []) : ?>
        <div class="empty-state">
            <i class="ti ti-package-off" aria-hidden="true"></i>
            No items yet. Add what was sold below.
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Cost each</th>
                        <th class="text-end">Sold</th>
                        <th class="text-end">Fees</th>
                        <th class="text-end">Net</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <?php $product = $item->getProduct(); ?>
                        <tr>
                            <td>
                                <?php if ($product !== null) : ?>
                                    <a href="/product/<?php echo $product->getId(); ?>"><?php echo $this->e($product->getTitle()); ?></a>
                                    <div class="small text-body-secondary"><?php echo $this->e($product->getSku()); ?></div>
                                <?php else : ?>
                                    Unknown product
                                <?php endif; ?>
                                <?php if (($item->getAdditionalNotes() ?? '') !== '') : ?>
                                    <div class="small"><?php echo $this->e($item->getAdditionalNotes() ?? ''); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo (int) $item->getQuantity(); ?></td>
                            <td class="text-end"><?php echo $pounds($item->getItemCost()); ?></td>
                            <td class="text-end"><?php echo $pounds($item->getSoldPrice()); ?></td>
                            <td class="text-end"><?php echo $pounds($item->getFeesDeductions()); ?></td>
                            <td class="text-end"><?php echo $pounds($item->getTotalSoldPrice()); ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-light-primary" href="<?php echo $base; ?>/items/<?php echo $item->getId(); ?>/edit">
                                    <i class="ti ti-edit" aria-hidden="true"></i> Edit
                                </a>
                                <form method="post" class="d-inline" action="<?php echo $base; ?>/items/<?php echo $item->getId(); ?>/delete"
                                      data-confirm="Remove this item from the order?">
                                    <button type="submit" class="btn btn-sm btn-light-danger">
                                        <i class="ti ti-trash" aria-hidden="true"></i> Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Total</th>
                        <th class="text-end"><?php echo $totals['units']; ?></th>
                        <th></th>
                        <th class="text-end"><?php echo $pounds((string) $totals['sold']); ?></th>
                        <th class="text-end"><?php echo $pounds((string) $totals['fees']); ?></th>
                        <th class="text-end"><?php echo $pounds((string) $totals['net']); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" id="add-item">
    <div class="card-header">
        <h2>Add an item</h2>
    </div>
    <div class="card-body">
        <?php $this->insert('orders/_item_form', [
            'action' => $base . '/items',
            'submitLabel' => 'Add item',
            'cancelUrl' => null,
            'values' => $itemValues,
            'errors' => $itemErrors,
            'productGroups' => $productGroups,
        ]) ?>
    </div>
</div>

<?php if ($transactions !== []) : ?>
    <div class="card">
        <div class="card-header">
            <h2>Transactions</h2>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Title</th>
                        <th class="text-end">Income</th>
                        <th class="text-end">Expense</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction) : ?>
                        <tr>
                            <td class="text-nowrap"><?php echo $date($transaction->getTransactionDatetime()); ?></td>
                            <td><?php echo $this->e($transaction->getTransactionTitle() ?? '—'); ?></td>
                            <td class="text-end"><?php echo $pounds($transaction->getIncome()); ?></td>
                            <td class="text-end"><?php echo $pounds($transaction->getExpense()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Delete order</h2>
    </div>
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <p class="mb-0">Removes the order and its items. Not possible while transactions are linked to it.</p>
        <form method="post" action="<?php echo $base; ?>/delete" data-confirm="Delete this order and its items?">
            <button type="submit" class="btn btn-light-danger"><i class="ti ti-trash" aria-hidden="true"></i> Delete</button>
        </form>
    </div>
</div>
