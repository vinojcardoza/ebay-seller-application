<?php

/**
 * View: Products list.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\Product> $products
 */

$this->layout('layout', ['title' => 'Products', 'breadcrumbs' => ['Products' => null]]);
?>
<div class="card">
    <div class="card-header">
        <h2>All products</h2>
        <span class="text-body-secondary"><?= number_format(count($products)) ?> products</span>
    </div>

    <?php if ($products === []) : ?>
        <div class="empty-state">
            <i class="ti ti-package-off" aria-hidden="true"></i>
            No products yet.
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th class="text-end">Price</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product) : ?>
                        <?php $category = $product->getCategory(); ?>
                        <tr>
                            <td class="text-nowrap"><?= $this->e($product->getSku()) ?></td>
                            <td><a href="/product/<?= $product->getId() ?>"><?= $this->e($product->getTitle()) ?></a></td>
                            <td><?= $category !== null ? $this->e($category->getTitle()) : '—' ?></td>
                            <td class="text-end">£<?= $this->e($product->getPrice()) ?></td>
                            <td>
                                <?php if ($product->getStatus() === \App\Entities\Product::STATUS_ACTIVE) : ?>
                                    <span class="badge tint-success">Active</span>
                                <?php else : ?>
                                    <span class="badge tint-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
