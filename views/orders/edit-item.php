<?php

/**
 * View: Edit one order item.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Order $order
 * @var \App\Entities\OrderItem $item
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, array<int, string>> $productGroups
 */

$base = '/orders/' . $order->getId();

$this->layout('layout', [
    'title' => 'Edit item',
    'breadcrumbs' => ['Orders' => '/orders', $order->getOrderReference() => $base, 'Edit item' => null],
]);
?>
<div class="card">
    <div class="card-body">
        <?php $this->insert('orders/_item_form', [
            'action' => $base . '/items/' . $item->getId(),
            'submitLabel' => 'Save changes',
            'cancelUrl' => $base,
            'values' => $values,
            'errors' => $errors,
            'productGroups' => $productGroups,
        ]) ?>
    </div>
</div>
