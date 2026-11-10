<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\CategoryPaths;
use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Product list (with pricing, shipping, multi-buy and stock, searchable and
 * filterable) and product detail pages.
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
     * Status filter options: value => label.
     */
    public const STATUS_FILTERS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
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
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * List products with their pricing, shipping, multi-buy settings and stock,
     * filtered by search text, category (including subcategories), stock state,
     * shipping service and status.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $categories = $this->categoryPaths->labels();
        $products = $this->em->getRepository(Product::class)->findBy([], ['title' => 'ASC']);
        $stock = $this->stockLevels();

        $filters = [
            'q' => is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '',
            'category' => is_string($query['category'] ?? null) && ctype_digit($query['category'])
                && isset($categories[(int) $query['category']]) ? (int) $query['category'] : null,
            'stock' => $this->option($query['stock'] ?? null, self::STOCK_FILTERS),
            'shipping' => is_string($query['shipping'] ?? null) ? trim($query['shipping']) : '',
            'status' => $this->option($query['status'] ?? null, self::STATUS_FILTERS),
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
        ]);
    }

    /**
     * Show a single product.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function show(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $product = $this->em->getRepository(Product::class)->find((int) ($args['id'] ?? 0));

        if ($product === null) {
            return $this->notFound('Product not found.');
        }

        return $this->render('product/show', [
            'product' => $product,
        ]);
    }

    /**
     * Stock per product: delivered purchases minus units sold (the legacy
     * calculation), plus units ordered but not yet delivered.
     *
     * @return array<int, array{stock: int, pending: int, state: string}> Keyed by product ID, for every product.
     */
    private function stockLevels(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT p.id, p.low_stock,'
            . ' COALESCE(delivered.qty, 0) AS delivered, COALESCE(pending.qty, 0) AS pending,'
            . ' COALESCE(sold.qty, 0) AS sold'
            . ' FROM products p'
            . ' LEFT JOIN (SELECT product, SUM(quantity) AS qty FROM purchases WHERE status = 1 GROUP BY product)'
            . ' delivered ON delivered.product = p.id'
            . ' LEFT JOIN (SELECT product, SUM(quantity) AS qty FROM purchases WHERE status = 0 GROUP BY product)'
            . ' pending ON pending.product = p.id'
            . ' LEFT JOIN (SELECT product, SUM(quantity) AS qty FROM order_items GROUP BY product)'
            . ' sold ON sold.product = p.id'
        );

        $levels = [];

        foreach ($rows as $row) {
            $stock = (int) $row['delivered'] - (int) $row['sold'];
            $levels[(int) $row['id']] = [
                'stock' => $stock,
                'pending' => (int) $row['pending'],
                'state' => $stock <= 0 ? 'out' : ($stock <= (int) ($row['low_stock'] ?? 0) ? 'low' : 'in'),
            ];
        }

        return $levels;
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

        return $filters['status'] === '' || ($filters['status'] === 'active') === $active;
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
