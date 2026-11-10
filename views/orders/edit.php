<?php

/**
 * View: Edit order form.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Order $order
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<string> $platforms
 * @var list<string> $types
 * @var array<string, string> $shippingServices
 */

$base = '/orders/' . $order->getId();

$this->layout('layout', [
    'title' => 'Edit order',
    'breadcrumbs' => ['Orders' => '/orders', $order->getOrderReference() => $base, 'Edit' => null],
]);
?>
<div class="row">
    <div class="col-xl-9">
        <?php $this->insert('orders/_form', [
            'action' => $base,
            'submitLabel' => 'Save changes',
            'cancelUrl' => $base,
            'values' => $values,
            'errors' => $errors,
            'platforms' => $platforms,
            'types' => $types,
            'shippingServices' => $shippingServices,
        ]) ?>
    </div>
</div>
