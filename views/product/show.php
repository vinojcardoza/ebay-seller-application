<?php

/**
 * View: Single product details.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Product $product
 */

$this->layout('layout', [
    'title' => $product->getTitle(),
    'breadcrumbs' => ['Products' => '/products', $product->getSku() => null],
]);

$category = $product->getCategory();
$updated = $product->getUpdated();

$pricing = [
    'Price' => $product->getPrice(),
    'Fees' => $product->getFees(),
    'Package cost' => $product->getPackageCost(),
    'Postage cost' => $product->getPostageCost(),
];

$details = [
    'SKU' => $product->getSku(),
    'Code' => $product->getCode(),
    'Barcode / UPC' => $product->getBarcodeUpc(),
    'Category' => $category !== null ? $category->getTitle() : '—',
    'Shipping service' => $product->getShippingService() ?? '—',
    'Low stock threshold' => (string) ($product->getLowStock() ?? '—'),
    'Promo percentage' => $product->getPromoPercentage() . '%',
    'Multi-buy tiers' => implode(' / ', [
        $product->getMultiBuy2() ?? '—',
        $product->getMultiBuy3() ?? '—',
        $product->getMultiBuy4() ?? '—',
    ]),
    'Created' => $product->getCreated()->format('d M Y H:i'),
    'Updated' => $updated !== null ? $updated->format('d M Y H:i') : '—',
];
?>
<?php $this->start('actions') ?>
    <a class="btn btn-light-secondary" href="/products"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
<?php $this->stop() ?>

<div class="row">
    <?php foreach ($pricing as $label => $amount) : ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <div>
                        <p class="kpi-value">£<?php echo $this->e($amount); ?></p>
                        <p class="kpi-label"><?php echo $this->e($label); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2>Details</h2>
        <?php if ($product->getStatus() === \App\Entities\Product::STATUS_ACTIVE) : ?>
            <span class="badge tint-success">Active</span>
        <?php else : ?>
            <span class="badge tint-danger">Inactive</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <dl class="row detail-list mb-0">
            <?php foreach ($details as $label => $value) : ?>
                <dt class="col-sm-4 col-lg-3"><?php echo $this->e($label); ?></dt>
                <dd class="col-sm-8 col-lg-9"><?php echo $this->e((string) $value); ?></dd>
            <?php endforeach; ?>
        </dl>
    </div>
</div>
