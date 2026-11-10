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

$this->layout('layout', ['title' => 'Edit ' . $category->getTitle()]);
?>
<h1>Edit category</h1>

<?php $this->insert('categories/_form', [
    'action' => '/categories/' . $category->getId(),
    'submitLabel' => 'Save changes',
    'values' => $values,
    'errors' => $errors,
    'parents' => $parents,
]) ?>

<p><a href="/categories/<?= $category->getId() ?>">Cancel</a></p>
