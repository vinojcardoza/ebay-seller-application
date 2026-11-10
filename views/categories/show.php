<?php

/**
 * View: Single category, with its parent, subcategories and a delete button.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Category $category
 * @var \App\Entities\Category|null $parent
 * @var list<\App\Entities\Category> $children
 * @var list<string> $errors Reasons a delete was refused.
 */

$this->layout('layout', ['title' => $category->getTitle()]);
$updated = $category->getUpdated();
?>
<h1><?= $this->e($category->getTitle()) ?></h1>

<?php if ($errors !== []) : ?>
    <article>
        <strong>This category can't be deleted.</strong>
        <ul>
            <?php foreach ($errors as $error) : ?>
                <li><?= $this->e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </article>
<?php endif; ?>

<dl>
    <dt>ID</dt>
    <dd><?= $category->getId() ?></dd>

    <dt>Parent</dt>
    <dd>
        <?php if ($parent !== null) : ?>
            <a href="/categories/<?= $parent->getId() ?>"><?= $this->e($parent->getTitle()) ?></a>
        <?php else : ?>
            None (top level)
        <?php endif; ?>
    </dd>

    <dt>Menu order</dt>
    <dd><?= $category->getMenuOrder() ?? 0 ?></dd>

    <dt>Created</dt>
    <dd><?= $category->getCreated()->format('Y-m-d H:i') ?></dd>

    <dt>Updated</dt>
    <dd><?= $updated !== null ? $updated->format('Y-m-d H:i') : '—' ?></dd>
</dl>

<?php if ($children !== []) : ?>
    <h2>Subcategories</h2>
    <ul>
        <?php foreach ($children as $child) : ?>
            <li><a href="/categories/<?= $child->getId() ?>"><?= $this->e($child->getTitle()) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p>
    <a href="/categories/<?= $category->getId() ?>/edit">Edit</a> ·
    <a href="/categories">Back to categories</a>
</p>

<form method="post" action="/categories/<?= $category->getId() ?>/delete"
      onsubmit="return confirm('Delete this category?');">
    <button type="submit">Delete category</button>
</form>
