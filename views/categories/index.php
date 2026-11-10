<?php

/**
 * View: Categories list.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\Category> $categories
 * @var array<int, string> $titles Category titles keyed by ID, for parent names.
 */

$this->layout('layout', ['title' => 'Categories']);
?>
<h1>Categories</h1>

<p><a href="/categories/create">Add category</a></p>

<?php if ($categories === []) : ?>
    <p>No categories yet.</p>
<?php else : ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Parent</th>
                <th>Menu order</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $category) : ?>
                <?php $parentId = $category->getParentId() ?? 0; ?>
                <tr>
                    <td><?= $category->getId() ?></td>
                    <td><a href="/categories/<?= $category->getId() ?>"><?= $this->e($category->getTitle()) ?></a></td>
                    <td><?= $parentId > 0 ? $this->e($titles[$parentId] ?? '#' . $parentId) : '—' ?></td>
                    <td><?= $category->getMenuOrder() ?? 0 ?></td>
                    <td><a href="/categories/<?= $category->getId() ?>/edit">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
