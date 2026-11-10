<?php

/**
 * View: Create category form.
 *
 * @var \League\Plates\Template\Template $this
 * @var array{title: string, parent_id: string, menu_order: string} $values
 * @var array<string, string> $errors
 * @var list<\App\Entities\Category> $parents
 */

$this->layout('layout', ['title' => 'Add category']);
?>
<h1>Add category</h1>

<?php $this->insert('categories/_form', [
    'action' => '/categories',
    'submitLabel' => 'Create category',
    'values' => $values,
    'errors' => $errors,
    'parents' => $parents,
]) ?>

<p><a href="/categories">Cancel</a></p>
