<?php

/**
 * View: One custom SKU with its product lines, totals and the add-product form.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\CustomSku $customSku
 * @var list<\App\Entities\CustomSkuProduct> $lines
 * @var array{price: string, fees: string, net: string, afterShipping: string, units: int} $totals
 * @var array<int, string> $products Product labels keyed by ID, for the add form.
 * @var array{product: string, quantity: string, price: string, fees: string} $lineValues
 * @var array<string, string> $lineErrors
 * @var string|null $notice Success message.
 */

use App\Entities\CustomSku;

$this->layout('layout', [
    'title' => $customSku->getSku(),
    'breadcrumbs' => ['Custom SKUs' => '/custom-skus', $customSku->getSku() => null],
]);

$base = '/custom-skus/' . $customSku->getId();
$active = ($customSku->getStatus() ?? CustomSku::STATUS_ACTIVE) === CustomSku::STATUS_ACTIVE;
?>
<?php $this->start('actions') ?>
    <a class="btn btn-light-secondary" href="/custom-skus"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <a class="btn btn-primary" href="<?php echo $base; ?>/edit"><i class="ti ti-edit" aria-hidden="true"></i> Edit</a>
<?php $this->stop() ?>

<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">
                <h2>Details</h2>
                <span class="badge <?php echo $active ? 'tint-success' : 'tint-danger'; ?>">
                    <?php echo $active ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
            <div class="card-body">
                <dl class="row detail-list mb-0">
                    <dt class="col-6">Category</dt>
                    <dd class="col-6"><?php echo $this->e($customSku->getCategory()?->getTitle() ?? '—'); ?></dd>
                    <dt class="col-6">Shipping service</dt>
                    <dd class="col-6"><?php echo $this->e($customSku->getShippingService() ?? '—'); ?></dd>
                    <dt class="col-6">Postage</dt>
                    <dd class="col-6">£<?php echo $this->e($customSku->getPostageCost()); ?></dd>
                    <dt class="col-6">Packaging</dt>
                    <dd class="col-6">£<?php echo $this->e($customSku->getPackageCost()); ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="row">
            <?php
            $tiles = [
                ['label' => 'Bundle price', 'value' => '£' . $totals['price']],
                ['label' => 'Fees', 'value' => '£' . $totals['fees']],
                ['label' => 'After fees', 'value' => '£' . $totals['net']],
                ['label' => 'After postage & packaging', 'value' => '£' . $totals['afterShipping']],
            ];
            ?>
            <?php foreach ($tiles as $tile) : ?>
                <div class="col-sm-6">
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
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Products in this custom SKU</h2>
        <span class="text-body-secondary">
            <?php echo count($lines); ?> product<?php echo count($lines) === 1 ? '' : 's'; ?>,
            <?php echo $totals['units']; ?> unit<?php echo $totals['units'] === 1 ? '' : 's'; ?>
        </span>
    </div>

    <?php if ($lines === []) : ?>
        <div class="empty-state">
            <i class="ti ti-box-multiple" aria-hidden="true"></i>
            No products yet. Add the products this listing ships below; eBay orders for it can't be imported until it has some.
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-end">Quantity</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Fees</th>
                        <th class="text-end">After fees</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line) : ?>
                        <?php $product = $line->getProduct(); ?>
                        <tr>
                            <td>
                                <?php if ($product !== null) : ?>
                                    <a href="/product/<?php echo $product->getId(); ?>"><?php echo $this->e($product->getTitle()); ?></a>
                                <?php else : ?>
                                    Unknown product
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?php echo $this->e($product?->getSku() ?? '—'); ?></td>
                            <td class="text-end"><?php echo (int) $line->getQuantity(); ?></td>
                            <td class="text-end">£<?php echo $this->e($line->getPrice()); ?></td>
                            <td class="text-end">£<?php echo $this->e($line->getFees()); ?></td>
                            <td class="text-end">
                                £<?php echo number_format((float) $line->getPrice() - (float) $line->getFees(), 2); ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-light-primary"
                                   href="<?php echo $base; ?>/products/<?php echo $line->getId(); ?>/edit">
                                    <i class="ti ti-edit" aria-hidden="true"></i> Edit
                                </a>
                                <form method="post" class="d-inline"
                                      action="<?php echo $base; ?>/products/<?php echo $line->getId(); ?>/delete"
                                      data-confirm="Remove this product from the custom SKU?">
                                    <button type="submit" class="btn btn-sm btn-light-danger">
                                        <i class="ti ti-trash" aria-hidden="true"></i> Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" id="add-product">
    <div class="card-header">
        <h2>Add a product</h2>
    </div>
    <div class="card-body">
        <?php $this->insert('custom-skus/_line_form', [
            'action' => $base . '/products',
            'submitLabel' => 'Add product',
            'cancelUrl' => null,
            'values' => $lineValues,
            'errors' => $lineErrors,
            'products' => $products,
        ]) ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Delete custom SKU</h2>
    </div>
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <p class="mb-0">Removes the custom SKU and its product lines. Past orders are not affected.</p>
        <form method="post" action="<?php echo $base; ?>/delete"
              data-confirm="Delete this custom SKU and its product lines?">
            <button type="submit" class="btn btn-light-danger">
                <i class="ti ti-trash" aria-hidden="true"></i> Delete
            </button>
        </form>
    </div>
</div>
