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

$this->layout('layout', [
    'title' => $category->getTitle(),
    'breadcrumbs' => ['Categories' => '/categories', $category->getTitle() => null],
]);
$updated = $category->getUpdated();
?>
<?php $this->start('actions') ?>
    <a class="btn btn-light-secondary" href="/categories"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <a class="btn btn-primary" href="/categories/<?php echo $category->getId(); ?>/edit">
        <i class="ti ti-edit" aria-hidden="true"></i> Edit
    </a>
<?php $this->stop() ?>

<?php if ($errors !== []) : ?>
    <div class="alert alert-danger" role="alert">
        <strong>This category can't be deleted.</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $error) : ?>
                <li><?php echo $this->e($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h2>Details</h2>
            </div>
            <div class="card-body">
                <dl class="row detail-list mb-0">
                    <dt class="col-sm-4">ID</dt>
                    <dd class="col-sm-8"><?php echo $category->getId(); ?></dd>

                    <dt class="col-sm-4">Parent</dt>
                    <dd class="col-sm-8">
                        <?php if ($parent !== null) : ?>
                            <a href="/categories/<?php echo $parent->getId(); ?>"><?php echo $this->e($parent->getTitle()); ?></a>
                        <?php else : ?>
                            None (top level)
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4">Menu order</dt>
                    <dd class="col-sm-8"><?php echo $category->getMenuOrder() ?? 0; ?></dd>

                    <dt class="col-sm-4">Created</dt>
                    <dd class="col-sm-8"><?php echo $category->getCreated()->format('d M Y H:i'); ?></dd>

                    <dt class="col-sm-4">Updated</dt>
                    <dd class="col-sm-8 mb-0"><?php echo $updated !== null ? $updated->format('d M Y H:i') : '—'; ?></dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Delete category</h2>
            </div>
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <p class="mb-0">Only possible when no subcategories, products or custom SKUs use it.</p>
                <form method="post" action="/categories/<?php echo $category->getId(); ?>/delete"
                      data-confirm="Delete this category?">
                    <button type="submit" class="btn btn-light-danger">
                        <i class="ti ti-trash" aria-hidden="true"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h2>Subcategories</h2>
                <span class="badge tint-primary"><?php echo count($children); ?></span>
            </div>
            <?php if ($children === []) : ?>
                <div class="empty-state">No subcategories.</div>
            <?php else : ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($children as $child) : ?>
                        <li class="list-group-item bg-transparent px-4">
                            <a href="/categories/<?php echo $child->getId(); ?>"><?php echo $this->e($child->getTitle()); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
