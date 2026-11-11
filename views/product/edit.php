<?php

/**
 * View: Edit product form.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Product $product
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<int, string> $categories
 * @var list<string> $shippingServices
 */

$this->layout('layout', [
    'title' => 'Edit product',
    'breadcrumbs' => [
        'Products' => '/products',
        $product->getSku() => '/product/' . $product->getId(),
        'Edit' => null,
    ],
]);
?>
<div class="row">
    <div class="col-xl-9">
        <?php $this->insert('product/_form', [
            'action' => '/product/' . $product->getId(),
            'submitLabel' => 'Save changes',
            'cancelUrl' => '/product/' . $product->getId(),
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories,
            'shippingServices' => $shippingServices,
        ]) ?>
    </div>
</div>
