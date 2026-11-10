<?php

/**
 * View: Edit category form.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Category $category
 * @var array{title: string, parent_id: string, menu_order: string} $values
 * @var array<string, string> $errors
 * @var list<\App\Entities\Category> $parents
 */

$this->layout('layout', [
    'title' => 'Edit category',
    'breadcrumbs' => [
        'Categories' => '/categories',
        $category->getTitle() => '/categories/' . $category->getId(),
        'Edit' => null,
    ],
]);
?>
<div class="row">
    <div class="col-xl-8">
        <?php $this->insert('categories/_form', [
            'action' => '/categories/' . $category->getId(),
            'submitLabel' => 'Save changes',
            'cancelUrl' => '/categories/' . $category->getId(),
            'values' => $values,
            'errors' => $errors,
            'parents' => $parents,
        ]) ?>
    </div>
</div>
