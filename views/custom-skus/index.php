<?php

/**
 * View: Custom SKUs list with their product lines.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\CustomSku> $customSkus Custom SKUs matching the filters.
 * @var int $total All custom SKUs.
 * @var string $search SKU search text.
 * @var int|null $status Status filter (1 active, 2 inactive) or null for all.
 * @var string|null $notice Success message.
 */

use App\Entities\CustomSku;

$this->layout('layout', ['title' => 'Custom SKUs', 'breadcrumbs' => ['Custom SKUs' => null]]);
?>
<?php $this->start('actions') ?>
    <a class="btn btn-primary" href="/custom-skus/create">
        <i class="ti ti-plus" aria-hidden="true"></i> Add custom SKU
    </a>
<?php $this->stop() ?>

<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>All custom SKUs</h2>
        <span class="text-body-secondary">
            <?php echo count($customSkus) === $total ? $total : count($customSkus) . ' of ' . $total; ?> custom SKUs
        </span>
    </div>
    <div class="card-body border-bottom">
        <form method="get" action="/custom-skus" class="row g-2 align-items-end">
            <div class="col-sm-5 col-lg-4">
                <label class="form-label" for="q">SKU contains</label>
                <input class="form-control" id="q" name="q" value="<?php echo $this->e($search); ?>">
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <option value="<?php echo CustomSku::STATUS_ACTIVE; ?>"
                        <?php echo $status === CustomSku::STATUS_ACTIVE ? 'selected' : ''; ?>>Active</option>
                    <option value="<?php echo CustomSku::STATUS_INACTIVE; ?>"
                        <?php echo $status === CustomSku::STATUS_INACTIVE ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-light-primary">
                    <i class="ti ti-search" aria-hidden="true"></i> Search
                </button>
                <?php if ($search !== '' || $status !== null) : ?>
                    <a class="btn btn-light-secondary" href="/custom-skus">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($customSkus === []) : ?>
        <div class="empty-state">
            <i class="ti ti-packages" aria-hidden="true"></i>
            <?php if ($total === 0) : ?>
                No custom SKUs yet. <a href="/custom-skus/create">Add the first one</a>.
            <?php else : ?>
                No custom SKUs match these filters.
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Products</th>
                        <th>Category</th>
                        <th class="text-end">Price</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customSkus as $customSku) : ?>
                        <?php
                        $lines = $customSku->getCustomSkuProducts()->toArray();
                        $price = array_sum(array_map(static fn ($line): float => (float) $line->getPrice(), $lines));
                        ?>
                        <tr>
                            <td class="text-nowrap">
                                <a href="/custom-skus/<?php echo $customSku->getId(); ?>"><?php echo $this->e($customSku->getSku()); ?></a>
                            </td>
                            <td>
                                <?php if ($lines === []) : ?>
                                    <span class="badge tint-warning">No products</span>
                                <?php endif; ?>
                                <?php foreach ($lines as $line) : ?>
                                    <div class="small">
                                        <?php echo (int) $line->getQuantity(); ?> ×
                                        <?php echo $this->e($line->getProduct()?->getTitle() ?? 'Unknown product'); ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo $this->e($customSku->getCategory()?->getTitle() ?? '—'); ?></td>
                            <td class="text-end text-nowrap">£<?php echo number_format($price, 2); ?></td>
                            <td>
                                <?php if (($customSku->getStatus() ?? CustomSku::STATUS_ACTIVE) === CustomSku::STATUS_ACTIVE) : ?>
                                    <span class="badge tint-success">Active</span>
                                <?php else : ?>
                                    <span class="badge tint-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-light-primary" href="/custom-skus/<?php echo $customSku->getId(); ?>">
                                    <i class="ti ti-box-multiple" aria-hidden="true"></i> Products
                                </a>
                                <a class="btn btn-sm btn-light-secondary" href="/custom-skus/<?php echo $customSku->getId(); ?>/edit">
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
