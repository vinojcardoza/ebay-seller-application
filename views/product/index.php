<?php

/**
 * View: Products list with pricing, shipping, multi-buy and stock, plus search and filters.
 *
 * @var \League\Plates\Template\Template $this
 * @var list<\App\Entities\Product> $products Current page.
 * @var array<int, array{stock: int, pending: int, state: string}> $stock Stock by product ID.
 * @var int $total Products matching the filters.
 * @var int $all All products.
 * @var int $page Current page.
 * @var int $pages Number of pages.
 * @var array{q: string, category: int|null, stock: string, shipping: string, status: string} $filters
 * @var array<int, string> $categories Category path labels keyed by ID.
 * @var list<string> $shippingServices Shipping services in use.
 * @var array<string, int> $stockCounts Products per stock filter.
 * @var array<string, string> $stockFilters Stock filter labels.
 * @var array<string, string> $statusFilters Status filter labels.
 */

use App\Entities\Product;

$this->layout('layout', ['title' => 'Products', 'breadcrumbs' => ['Products' => null]]);

$filtered = $filters['q'] !== '' || $filters['category'] !== null || $filters['stock'] !== ''
    || $filters['shipping'] !== '' || $filters['status'] !== '';

/**
 * URL of this page with the current filters and the given page number.
 */
$pageUrl = static fn (int $number): string => '/products?' . http_build_query(array_filter(
    $filters + ['page' => $number > 1 ? $number : null],
    static fn ($value): bool => $value !== null && $value !== ''
));

/**
 * Format a stored amount as pounds, or a dash when empty.
 */
$money = static fn (?string $amount): string => $amount !== null && $amount !== '' ? '£' . $amount : '—';
?>
<div class="card">
    <div class="card-header">
        <h2>Products</h2>
        <span class="text-body-secondary">
            <?php echo $filtered ? number_format($total) . ' of ' . number_format($all) : number_format($all); ?> products
        </span>
    </div>

    <div class="card-body border-bottom">
        <form method="get" action="/products" class="row g-2 align-items-end">
            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" maxlength="100" placeholder="SKU, title, code or barcode"
                       value="<?php echo $this->e($filters['q']); ?>">
            </div>
            <div class="col-sm-6 col-md-3 col-xl-2">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $id => $label) : ?>
                        <option value="<?php echo $id; ?>" <?php echo $filters['category'] === $id ? 'selected' : ''; ?>>
                            <?php echo $this->e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-3 col-xl-2">
                <label class="form-label" for="stock">Stock</label>
                <select class="form-select" id="stock" name="stock">
                    <option value="">All</option>
                    <?php foreach ($stockFilters as $value => $label) : ?>
                        <option value="<?php echo $value; ?>" <?php echo $filters['stock'] === $value ? 'selected' : ''; ?>>
                            <?php echo $this->e($label); ?> (<?php echo $stockCounts[$value] ?? 0; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-3 col-xl-2">
                <label class="form-label" for="shipping">Shipping service</label>
                <select class="form-select" id="shipping" name="shipping">
                    <option value="">All</option>
                    <?php foreach ($shippingServices as $service) : ?>
                        <option value="<?php echo $this->e($service); ?>" <?php echo $filters['shipping'] === $service ? 'selected' : ''; ?>>
                            <?php echo $this->e($service); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-3 col-xl-1">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach ($statusFilters as $value => $label) : ?>
                        <option value="<?php echo $value; ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>>
                            <?php echo $this->e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-light-primary">
                    <i class="ti ti-filter" aria-hidden="true"></i> Filter
                </button>
                <?php if ($filtered) : ?>
                    <a class="btn btn-light-secondary" href="/products">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($products === []) : ?>
        <div class="empty-state">
            <i class="ti ti-package-off" aria-hidden="true"></i>
            <?php echo $filtered ? 'No products match these filters.' : 'No products yet.'; ?>
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle product-table">
                <thead>
                    <tr>
                        <th class="text-end">ID</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-end">Selling price</th>
                        <th class="text-end">Fees</th>
                        <th class="text-end">Promotion</th>
                        <th>Shipping service</th>
                        <th class="text-end">Postage</th>
                        <th class="text-end">Package</th>
                        <th class="text-end" title="Multi-buy values for 2, 3 and 4+ items">Multi-buy 2 / 3 / 4</th>
                        <th class="text-end">Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product) : ?>
                        <?php
                        $level = $stock[$product->getId()] ?? ['stock' => 0, 'pending' => 0, 'state' => 'out'];
                        $categoryId = $product->getCategory()?->getId();
                        ?>
                        <tr>
                            <td class="text-end text-body-secondary"><?php echo $product->getId(); ?></td>
                            <td class="product-cell">
                                <a href="/product/<?php echo $product->getId(); ?>"><?php echo $this->e($product->getTitle()); ?></a>
                                <div class="small text-body-secondary"><?php echo $this->e($product->getSku()); ?></div>
                            </td>
                            <td><?php echo $categoryId !== null ? $this->e($categories[$categoryId] ?? '—') : '—'; ?></td>
                            <td class="text-end"><?php echo $this->e($money($product->getPrice())); ?></td>
                            <td class="text-end"><?php echo $this->e($money($product->getFees())); ?></td>
                            <td class="text-end"><?php echo $this->e($product->getPromoPercentage()); ?>%</td>
                            <td><?php echo $this->e($product->getShippingService() ?? '—'); ?></td>
                            <td class="text-end"><?php echo $this->e($money($product->getPostageCost())); ?></td>
                            <td class="text-end"><?php echo $this->e($money($product->getPackageCost())); ?></td>
                            <td class="text-end">
                                <?php echo $this->e(implode(' / ', [
                                    $product->getMultiBuy2() ?? '—',
                                    $product->getMultiBuy3() ?? '—',
                                    $product->getMultiBuy4() ?? '—',
                                ])); ?>
                            </td>
                            <td class="text-end">
                                <strong><?php echo number_format($level['stock']); ?></strong>
                                <?php if ($level['state'] === 'out') : ?>
                                    <div><span class="badge tint-danger">Out of stock</span></div>
                                <?php elseif ($level['state'] === 'low') : ?>
                                    <div><span class="badge tint-warning">Low</span></div>
                                <?php endif; ?>
                                <?php if ($level['pending'] > 0) : ?>
                                    <div class="small text-body-secondary">+<?php echo number_format($level['pending']); ?> incoming</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($product->getStatus() === Product::STATUS_ACTIVE) : ?>
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

        <?php if ($pages > 1) : ?>
            <nav class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2" aria-label="Product pages">
                <span class="small text-body-secondary">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item<?php echo $page === 1 ? ' disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $this->e($pageUrl($page - 1)); ?>">Previous</a>
                    </li>
                    <?php foreach (array_unique([1, max(1, $page - 1), $page, min($pages, $page + 1), $pages]) as $number) : ?>
                        <li class="page-item<?php echo $number === $page ? ' active' : ''; ?>">
                            <a class="page-link" href="<?php echo $this->e($pageUrl($number)); ?>"
                                <?php echo $number === $page ? 'aria-current="page"' : ''; ?>><?php echo $number; ?></a>
                        </li>
                    <?php endforeach; ?>
                    <li class="page-item<?php echo $page === $pages ? ' disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $this->e($pageUrl($page + 1)); ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
