<?php

/**
 * View: Add order form.
 *
 * @var \League\Plates\Template\Template $this
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<string> $platforms
 * @var list<string> $types
 * @var array<string, string> $shippingServices
 */

$this->layout('layout', ['title' => 'Add order', 'breadcrumbs' => ['Orders' => '/orders', 'Add' => null]]);
?>
<div class="row">
    <div class="col-xl-9">
        <?php $this->insert('orders/_form', [
            'action' => '/orders',
            'submitLabel' => 'Create order',
            'cancelUrl' => '/orders',
            'values' => $values,
            'errors' => $errors,
            'platforms' => $platforms,
            'types' => $types,
            'shippingServices' => $shippingServices,
        ]) ?>
    </div>
</div>
