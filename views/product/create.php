<?php

/**
 * View: Create product form.
 *
 * @var \League\Plates\Template\Template $this
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<int, string> $categories
 * @var list<string> $shippingServices
 */

$this->layout('layout', [
    'title' => 'Add product',
    'breadcrumbs' => ['Products' => '/products', 'Add' => null],
]);
?>
<div class="row">
    <div class="col-xl-9">
        <?php $this->insert('product/_form', [
            'action' => '/products',
            'submitLabel' => 'Create product',
            'cancelUrl' => '/products',
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories,
            'shippingServices' => $shippingServices,
        ]) ?>
    </div>
</div>
