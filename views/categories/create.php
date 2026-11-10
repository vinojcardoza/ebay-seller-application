<?php

/**
 * View: Create category form.
 *
 * @var \League\Plates\Template\Template $this
 * @var array{title: string, parent_id: string, menu_order: string} $values
 * @var array<string, string> $errors
 * @var list<\App\Entities\Category> $parents
 */

$this->layout('layout', [
    'title' => 'Add category',
    'breadcrumbs' => ['Categories' => '/categories', 'Add' => null],
]);
?>
<div class="row">
    <div class="col-xl-8">
        <?php $this->insert('categories/_form', [
            'action' => '/categories',
            'submitLabel' => 'Create category',
            'cancelUrl' => '/categories',
            'values' => $values,
            'errors' => $errors,
            'parents' => $parents,
        ]) ?>
    </div>
</div>
