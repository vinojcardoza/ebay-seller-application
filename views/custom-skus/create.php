<?php

/**
 * View: Create custom SKU form.
 *
 * @var \League\Plates\Template\Template $this
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<int, string> $categories
 */

$this->layout('layout', [
    'title' => 'Add custom SKU',
    'breadcrumbs' => ['Custom SKUs' => '/custom-skus', 'Add' => null],
]);
?>
<div class="row">
    <div class="col-xl-8">
        <?php $this->insert('custom-skus/_form', [
            'action' => '/custom-skus',
            'submitLabel' => 'Create custom SKU',
            'cancelUrl' => '/custom-skus',
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories,
        ]) ?>
    </div>
</div>
