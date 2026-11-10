<?php

/**
 * View: Edit custom SKU form.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\CustomSku $customSku
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<int, string> $categories
 */

$this->layout('layout', [
    'title' => 'Edit custom SKU',
    'breadcrumbs' => [
        'Custom SKUs' => '/custom-skus',
        $customSku->getSku() => '/custom-skus/' . $customSku->getId(),
        'Edit' => null,
    ],
]);
?>
<div class="row">
    <div class="col-xl-8">
        <?php $this->insert('custom-skus/_form', [
            'action' => '/custom-skus/' . $customSku->getId(),
            'submitLabel' => 'Save changes',
            'cancelUrl' => '/custom-skus/' . $customSku->getId(),
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories,
        ]) ?>
    </div>
</div>
