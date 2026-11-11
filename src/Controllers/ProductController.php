<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\CategoryPaths;
use App\Catalog\StockLevels;
use App\Entities\Category;
use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\OrderItem;
use App\Entities\Product;
use App\Entities\Purchase;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Product list (with pricing, shipping and stock, searchable and
 * filterable), product detail pages, and creating, editing and deleting products.
 *
 * HTML forms can only send GET and POST, so updates and deletes are POST
 * routes (`/product/{id}` and `/product/{id}/delete`).
 */
class ProductController extends ControllerAbstract
{
    /**
     * Products per page.
     */
    public const PER_PAGE = 50;

    /**
     * Stock filter options: value => label.
     */
    public const STOCK_FILTERS = [
        'out' => 'Out of stock',
        'low' => 'Low stock',
        'in' => 'In stock',
        'pending' => 'Awaiting delivery',
    ];

    /**
     * Status filter options: value => label. Inactive products are only listed
     * when the filter asks for them.
     */
    public const STATUS_FILTERS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'all' => 'All',
    ];

    /**
     * Status filter applied when none (or an unknown one) is given.
     */
    public const DEFAULT_STATUS = 'active';

    /**
     * Longest text the varchar(191) columns hold.
     */
    private const MAX_LENGTH = 191;

    /**
     * Amounts accepted for prices and costs: up to 8 digits and 2 decimals.
     */
    private const MONEY_PATTERN = '/^\d{1,8}(\.\d{1,2})?$/';

    /**
     * Promotion percentages accepted: up to 3 digits and 2 decimals (range checked separately).
     */
    private const PERCENT_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    /**
     * Success messages for `?done=` codes set by redirects.
     */
    private const NOTICES = [
        'created' => 'Product created.',
        'updated' => 'Product updated.',
        'deleted' => 'Product deleted.',
    ];

    /**
     * Create the controller.
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly CategoryPaths $categoryPaths,
        private readonly StockLevels $stockLevels,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * List products with their pricing, shipping and stock,
     * filtered by search text, category (including subcategories), stock state,
     * shipping service and status (active only unless the status filter says otherwise).
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $categories = $this->categoryPaths->labels();
        $products = $this->em->getRepository(Product::class)->findBy([], ['title' => 'ASC']);
        $stock = $this->stockLevels->all();

        $filters = [
            'q' => is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '',
            'category' => is_string($query['category'] ?? null) && ctype_digit($query['category'])
                && isset($categories[(int) $query['category']]) ? (int) $query['category'] : null,
            'stock' => $this->option($query['stock'] ?? null, self::STOCK_FILTERS),
            'shipping' => is_string($query['shipping'] ?? null) ? trim($query['shipping']) : '',
            'status' => $this->option($query['status'] ?? null, self::STATUS_FILTERS) ?: self::DEFAULT_STATUS,
        ];

        $shippingServices = array_values(array_unique(array_filter(array_map(
            static fn (Product $product): string => (string) $product->getShippingService(),
            $products
        ))));
        sort($shippingServices, SORT_NATURAL | SORT_FLAG_CASE);

        if (!in_array($filters['shipping'], $shippingServices, true)) {
            $filters['shipping'] = '';
        }

        $categoryIds = $filters['category'] !== null
            ? $this->categoryPaths->withDescendants($filters['category'])
            : null;
        $matches = array_values(array_filter(
            $products,
            fn (Product $product): bool => $this->matches(
                $product,
                $filters,
                $categoryIds,
                $stock[$product->getId()] ?? null
            )
        ));

        $total = count($matches);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1));

        return $this->render('product/index', [
            'products' => array_slice($matches, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'stock' => $stock,
            'total' => $total,
            'all' => count($products),
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'categories' => $categories,
            'shippingServices' => $shippingServices,
            'stockCounts' => $this->stockCounts($stock),
            'stockFilters' => self::STOCK_FILTERS,
            'statusFilters' => self::STATUS_FILTERS,
            'notice' => $this->notice($query),
        ]);
    }

    /**
     * Show a single product.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function show(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $product = $this->findProduct($args);

        if ($product === null) {
            return $this->notFound('Product not found.');
        }

        return $this->renderShow($product, $this->notice($request->getQueryParams()));
    }

    /**
     * Show the form for a new product, prefilled with the table's column defaults.
     */
    public function create(): ResponseInterface
    {
        $defaults = new Product();

        return $this->renderForm('product/create', null, [
            'sku' => '',
            'title' => '',
            'code' => '',
            'barcode_upc' => '',
            'category' => '',
            'price' => '',
            'fees' => '',
            'promo_percentage' => $defaults->getPromoPercentage(),
            'shipping_service' => '',
            'postage_cost' => '',
            'package_cost' => '',
            'low_stock' => (string) $defaults->getLowStock(),
            'status' => (string) Product::STATUS_ACTIVE,
        ]);
    }

    /**
     * Validate and save a new product, then show it.
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $values = $this->input($request);
        $errors = $this->validate($values, null);

        if ($errors !== []) {
            return $this->renderForm('product/create', null, $values, $errors);
        }

        $product = new Product();
        $this->fill($product, $values);
        $this->em->persist($product);
        $this->em->flush();

        return $this->redirect('/product/' . $product->getId() . '?done=created');
    }

    /**
     * Show the form for editing a product.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $product = $this->findProduct($args);

        if ($product === null) {
            return $this->notFound('Product not found.');
        }

        return $this->renderForm('product/edit', $product, [
            'sku' => $product->getSku(),
            'title' => $product->getTitle(),
            'code' => $product->getCode(),
            'barcode_upc' => $product->getBarcodeUpc(),
            'category' => (string) ($product->getCategory()?->getId() ?? ''),
            'price' => $product->getPrice(),
            'fees' => $product->getFees(),
            'promo_percentage' => $product->getPromoPercentage(),
            'shipping_service' => $product->getShippingService() ?? '',
            'postage_cost' => $product->getPostageCost(),
            'package_cost' => $product->getPackageCost(),
            'low_stock' => (string) ($product->getLowStock() ?? ''),
            'status' => $product->getStatus() === Product::STATUS_ACTIVE
                ? (string) Product::STATUS_ACTIVE : (string) Product::STATUS_INACTIVE,
        ]);
    }

    /**
     * Validate and save changes to a product.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $product = $this->findProduct($args);

        if ($product === null) {
            return $this->notFound('Product not found.');
        }

        $values = $this->input($request);
        $errors = $this->validate($values, $product);

        if ($errors !== []) {
            return $this->renderForm('product/edit', $product, $values, $errors);
        }

        $this->fill($product, $values);
        $product->setUpdated(new DateTime());
        $this->em->flush();

        return $this->redirect('/product/' . $product->getId() . '?done=updated');
    }

    /**
     * Delete a product, unless purchases, order items or custom SKUs still use it
     * (those keep stock and profit history; mark the product inactive instead).
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $product = $this->findProduct($args);

        if ($product === null) {
            return $this->notFound('Product not found.');
        }

        $blockers = $this->deleteBlockers($product);

        if ($blockers !== []) {
            return $this->renderShow($product, null, $blockers, 409);
        }

        $this->em->remove($product);
        $this->em->flush();

        return $this->redirect('/products?done=deleted');
    }

    /**
     * Render the product page, optionally with a notice, delete errors and a status code.
     *
     * @param list<string> $errors Reasons a delete was refused.
     */
    private function renderShow(
        Product $product,
        ?string $notice,
        array $errors = [],
        int $status = 200
    ): ResponseInterface {
        return $this->render('product/show', [
            'product' => $product,
            'notice' => $notice,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Render the create or edit form; 422 when there are validation errors.
     *
     * @param array<string, string> $values Current form values.
     * @param array<string, string> $errors Validation messages keyed by field.
     */
    private function renderForm(
        string $template,
        ?Product $product,
        array $values,
        array $errors = []
    ): ResponseInterface {
        return $this->render($template, [
            'product' => $product,
            'values' => $values,
            'errors' => $errors,
            'categories' => $this->categoryPaths->labels(),
            'shippingServices' => $this->shippingServices(),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Read the product form fields as trimmed strings ('' when missing).
     *
     * @return array<string, string>
     */
    private function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = [];

        foreach (
            ['sku', 'title', 'code', 'barcode_upc', 'category', 'price', 'fees', 'promo_percentage',
                'shipping_service', 'postage_cost', 'package_cost', 'low_stock', 'status'] as $field
        ) {
            $value = $body[$field] ?? '';
            $values[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $values;
    }

    /**
     * Validate product fields, returning messages keyed by field.
     *
     * @param array<string, string> $values Submitted form values.
     * @param Product|null $current The product being edited, or null when creating.
     *
     * @return array<string, string>
     */
    private function validate(array $values, ?Product $current): array
    {
        $errors = [];

        foreach (['sku' => 'SKU', 'title' => 'Title'] as $field => $label) {
            if ($values[$field] === '') {
                $errors[$field] = "$label is required.";
            }
        }

        $lengths = ['sku' => 'SKU', 'title' => 'Title', 'code' => 'Code', 'barcode_upc' => 'Barcode / UPC',
            'shipping_service' => 'Shipping service'];

        foreach ($lengths as $field => $label) {
            if (mb_strlen($values[$field]) > self::MAX_LENGTH) {
                $errors[$field] = "$label must be " . self::MAX_LENGTH . ' characters or fewer.';
            }
        }

        if (!isset($errors['sku'])) {
            $existing = $this->products()->findOneBy(['sku' => $values['sku']]);

            if ($existing !== null && $existing !== $current) {
                $errors['sku'] = 'Another product already uses this SKU.';
            } elseif ($this->em->getRepository(CustomSku::class)->findOneBy(['sku' => $values['sku']]) !== null) {
                $errors['sku'] = 'A custom SKU already uses this SKU; eBay orders could not tell them apart.';
            }
        }

        if ($values['category'] !== '' && $this->findCategory($values['category']) === null) {
            $errors['category'] = 'Choose a valid category.';
        }

        $amounts = ['price' => 'Selling price', 'fees' => 'Fees', 'postage_cost' => 'Postage cost',
            'package_cost' => 'Packaging cost'];

        foreach ($amounts as $field => $label) {
            if (!preg_match(self::MONEY_PATTERN, $values[$field])) {
                $errors[$field] = "$label must be an amount like 4.99.";
            }
        }

        if (!isset($errors['price'], $errors['fees']) && (float) $values['fees'] > (float) $values['price']) {
            $errors['fees'] = 'Fees cannot be more than the selling price.';
        }

        if (
            !preg_match(self::PERCENT_PATTERN, $values['promo_percentage'])
            || (float) $values['promo_percentage'] > 100
        ) {
            $errors['promo_percentage'] = 'Promotion must be a percentage from 0 to 100, like 7.5.';
        }

        if (
            $values['low_stock'] !== ''
            && filter_var($values['low_stock'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            $errors['low_stock'] = 'Low stock threshold must be a whole number of 0 or more.';
        }

        if (!in_array($values['status'], [(string) Product::STATUS_ACTIVE, (string) Product::STATUS_INACTIVE], true)) {
            $errors['status'] = 'Choose a status.';
        }

        return $errors;
    }

    /**
     * Copy validated values onto the product.
     *
     * @param array<string, string> $values Validated form values.
     */
    private function fill(Product $product, array $values): void
    {
        $product
            ->setSku($values['sku'])
            ->setTitle($values['title'])
            ->setCode($values['code'])
            ->setBarcodeUpc($values['barcode_upc'])
            ->setCategory($values['category'] !== '' ? $this->findCategory($values['category']) : null)
            ->setPrice($this->money($values['price']))
            ->setFees($this->money($values['fees']))
            ->setPromoPercentage($this->money($values['promo_percentage']))
            ->setShippingService($values['shipping_service'] !== '' ? $values['shipping_service'] : null)
            ->setPostageCost($this->money($values['postage_cost']))
            ->setPackageCost($this->money($values['package_cost']))
            ->setLowStock($values['low_stock'] !== '' ? (int) $values['low_stock'] : null)
            ->setStatus((int) $values['status']);
    }

    /**
     * Reasons the product cannot be deleted: records that still reference it.
     *
     * @return list<string>
     */
    private function deleteBlockers(Product $product): array
    {
        $counts = [
            'purchase' => $this->em->getRepository(Purchase::class)->count(['product' => $product]),
            'order item' => $this->em->getRepository(OrderItem::class)->count(['product' => $product]),
            'custom SKU line' => $this->em->getRepository(CustomSkuProduct::class)->count(['product' => $product]),
        ];

        $blockers = [];

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $blockers[] = sprintf('It is used by %d %s%s.', $count, $label, $count === 1 ? '' : 's');
            }
        }

        if ($blockers !== []) {
            $blockers[] = 'Set its status to Inactive to hide it from lists instead.';
        }

        return $blockers;
    }

    /**
     * Shipping services already used by products, for the form's suggestions.
     *
     * @return list<string>
     */
    private function shippingServices(): array
    {
        $services = array_values(array_unique(array_filter(array_map(
            static fn (Product $product): string => (string) $product->getShippingService(),
            $this->products()->findBy([], ['title' => 'ASC'])
        ))));
        sort($services, SORT_NATURAL | SORT_FLAG_CASE);

        return $services;
    }

    /**
     * Find the product named by the `id` route placeholder.
     *
     * @param array<string, string> $args
     */
    private function findProduct(array $args): ?Product
    {
        $id = $args['id'] ?? '';

        return ctype_digit($id) ? $this->products()->find((int) $id) : null;
    }

    /**
     * Find a category by a numeric ID string, or null for invalid or unknown IDs.
     */
    private function findCategory(string $id): ?Category
    {
        return ctype_digit($id) ? $this->em->getRepository(Category::class)->find((int) $id) : null;
    }

    /**
     * Product repository.
     *
     * @return EntityRepository<Product>
     */
    private function products(): EntityRepository
    {
        return $this->em->getRepository(Product::class);
    }

    /**
     * A validated amount as a 2-decimal string, e.g. "4" → "4.00".
     */
    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Success message for a `?done=` code.
     *
     * @param array<string, mixed> $query
     */
    private function notice(array $query): ?string
    {
        return is_string($query['done'] ?? null) ? self::NOTICES[$query['done']] ?? null : null;
    }


    /**
     * Whether a product passes every active filter.
     *
     * @param array{q: string, category: int|null, stock: string, shipping: string, status: string} $filters
     * @param list<int>|null $categoryIds Category and subcategory IDs, or null for any category.
     * @param array{stock: int, pending: int, state: string}|null $stock
     */
    private function matches(Product $product, array $filters, ?array $categoryIds, ?array $stock): bool
    {
        if ($filters['q'] !== '') {
            $haystack = implode(' ', [
                $product->getSku(),
                $product->getTitle(),
                $product->getCode(),
                $product->getBarcodeUpc(),
            ]);

            if (mb_stripos($haystack, $filters['q']) === false) {
                return false;
            }
        }

        if ($categoryIds !== null && !in_array($product->getCategory()?->getId(), $categoryIds, true)) {
            return false;
        }

        if ($filters['stock'] === 'pending') {
            if (($stock['pending'] ?? 0) <= 0) {
                return false;
            }
        } elseif ($filters['stock'] !== '' && ($stock['state'] ?? 'out') !== $filters['stock']) {
            return false;
        }

        if ($filters['shipping'] !== '' && $product->getShippingService() !== $filters['shipping']) {
            return false;
        }

        $active = $product->getStatus() === Product::STATUS_ACTIVE;

        return $filters['status'] === 'all' || ($filters['status'] === 'active') === $active;
    }

    /**
     * Number of products in each stock state, for the filter labels.
     *
     * @param array<int, array{stock: int, pending: int, state: string}> $stock
     *
     * @return array<string, int>
     */
    private function stockCounts(array $stock): array
    {
        $counts = array_fill_keys(array_keys(self::STOCK_FILTERS), 0);

        foreach ($stock as $level) {
            $counts[$level['state']]++;

            if ($level['pending'] > 0) {
                $counts['pending']++;
            }
        }

        return $counts;
    }

    /**
     * A query value if it is one of the allowed option keys, otherwise ''.
     *
     * @param array<string, string> $options
     */
    private function option(mixed $value, array $options): string
    {
        return is_string($value) && isset($options[$value]) ? $value : '';
    }
}
