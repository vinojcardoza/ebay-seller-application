<?php

/**
 * View: Categories list.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\Category> $categories
 * @var array<int, string> $titles Category titles keyed by ID, for parent names.
 */

$this->layout('layout', ['title' => 'Categories', 'breadcrumbs' => ['Categories' => null]]);
?>
<?php $this->start('actions') ?>
    <a class="btn btn-primary" href="/categories/create"><i class="ti ti-plus" aria-hidden="true"></i> Add category</a>
<?php $this->stop() ?>

<div class="card">
    <div class="card-header">
        <h2>All categories</h2>
        <span class="text-body-secondary"><?php echo count($categories); ?> categories</span>
    </div>

    <?php if ($categories === []) : ?>
        <div class="empty-state">
            <i class="ti ti-category" aria-hidden="true"></i>
            No categories yet. <a href="/categories/create">Add the first one</a>.
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Parent</th>
                        <th>Menu order</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category) : ?>
                        <?php $parentId = $category->getParentId() ?? 0; ?>
                        <tr>
                            <td><?php echo $category->getId(); ?></td>
                            <td><a href="/categories/<?php echo $category->getId(); ?>"><?php echo $this->e($category->getTitle()); ?></a></td>
                            <td><?php echo $parentId > 0 ? $this->e($titles[$parentId] ?? '#' . $parentId) : '—'; ?></td>
                            <td><?php echo $category->getMenuOrder() ?? 0; ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light-primary" href="/categories/<?php echo $category->getId(); ?>/edit">
                                    <i class="ti ti-edit" aria-hidden="true"></i> Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
