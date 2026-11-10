<?php

/**
 * View: Edit one product line of a custom SKU.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\CustomSku $customSku
 * @var \App\Entities\CustomSkuProduct $line
 * @var array{product: string, quantity: string, price: string, fees: string} $values
 * @var array<string, string> $errors
 * @var array<int, string> $products
 */

$base = '/custom-skus/' . $customSku->getId();

$this->layout('layout', [
    'title' => 'Edit product line',
    'breadcrumbs' => [
        'Custom SKUs' => '/custom-skus',
        $customSku->getSku() => $base,
        'Edit product' => null,
    ],
]);
?>
<div class="card">
    <div class="card-body">
        <?php $this->insert('custom-skus/_line_form', [
            'action' => $base . '/products/' . $line->getId(),
            'submitLabel' => 'Save changes',
            'cancelUrl' => $base,
            'values' => $values,
            'errors' => $errors,
            'products' => $products,
        ]) ?>
    </div>
</div>
