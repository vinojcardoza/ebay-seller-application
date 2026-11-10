<?php

/**
 * View: Add purchase form.
 *
 * @var \League\Plates\Template\Template $this
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<int, string> $suppliers
 * @var array<string, array<int, string>> $productGroups
 * @var array<int, string> $statuses
 */

$this->layout('layout', ['title' => 'Add purchase', 'breadcrumbs' => ['Purchases' => '/purchases', 'Add' => null]]);
?>
<div class="row">
    <div class="col-xl-8">
        <?php $this->insert('purchases/_form', [
            'action' => '/purchases',
            'submitLabel' => 'Add purchase',
            'values' => $values,
            'errors' => $errors,
            'suppliers' => $suppliers,
            'productGroups' => $productGroups,
            'statuses' => $statuses,
        ]) ?>
    </div>
</div>
