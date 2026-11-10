<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Catalog\CategoryPaths;
use App\Controllers\ProductController;
use App\Entities\Category;
use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\PlatesRender;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;

/**
 * Tests for ProductController: the list (columns, stock, search, filters,
 * pagination) and the detail page, using real views and in-memory fakes.
 */
#[CoversClass(ProductController::class)]
#[CoversClass(ControllerAbstract::class)]
#[CoversClass(CategoryPaths::class)]
final class ProductControllerTest extends TestCase
{
    /**
     * Products held by the fake repository, keyed by ID.
     *
     * @var array<int, Product>
     */
    private array $products = [];

    /**
     * Categories held by the fake repository, keyed by ID.
     *
     * @var array<int, Category>
     */
    private array $categories = [];

    /**
     * Rows the stock query returns.
     *
     * @var list<array<string, int|string|null>>
     */
    private array $stockRows = [];

    /**
     * Arguments of the last product findBy() call.
     *
     * @var list<mixed>|null
     */
    private ?array $findByArgs = null;

    /**
     * Controller under test.
     */
    private ProductController $controller;

    /**
     * Seed categories Electronics (1) and Sports (2) › Tennis (3), and three products:
     * 10 LCD (Electronics, CRL48, low stock: 5 left of threshold 10),
     * 11 Socks (no category, inactive, CRL48, out of stock),
     * 12 Grip (Tennis, TOLP48, 90 in stock and 20 incoming).
     */
    protected function setUp(): void
    {
        $this->products = [];
        $this->categories = [];
        $this->findByArgs = null;

        $this->addCategory(1, 'Electronics', 0);
        $this->addCategory(2, 'Sports', 0);
        $this->addCategory(3, 'Tennis', 2);

        $this->addProduct($this->product(10, 'LCD-1602', 'LCD Display 16x2', $this->categories[1]));
        $this->addProduct(
            $this->product(11, 'SCK-WHT', 'White Socks', null)->setStatus(Product::STATUS_INACTIVE)
        );
        $this->addProduct(
            $this->product(12, 'GRIP-TNS', 'Tennis Grip', $this->categories[3])->setShippingService('TOLP48')
        );

        $this->stockRows = [
            ['id' => 10, 'low_stock' => 10, 'delivered' => '50', 'pending' => '0', 'sold' => '45'],
            ['id' => 11, 'low_stock' => 10, 'delivered' => '0', 'pending' => '0', 'sold' => '0'],
            ['id' => 12, 'low_stock' => 10, 'delivered' => '100', 'pending' => '20', 'sold' => '10'],
        ];

        $this->controller = $this->controller();
    }

    // ---------- index: columns ----------

    /**
     * Each row shows ID, product, category path, prices, promotion, shipping, multi-buy, stock and status.
     */
    public function testIndexShowsAllColumns(): void
    {
        $body = $this->body($this->index());

        foreach (
            ['ID', 'Product', 'Category', 'Selling price', 'Fees', 'Promotion', 'Shipping service', 'Postage',
                'Package', 'Multi-buy 2 / 3 / 4', 'Stock', 'Status'] as $heading
        ) {
            $this->assertMatchesRegularExpression('#<th[^>]*>' . preg_quote($heading, '#') . '</th>#', $body);
        }

        $row = $this->rowFor($body, 10);
        $this->assertStringContainsString('>10</td>', $row);
        $this->assertStringContainsString('<a href="/product/10">LCD Display 16x2</a>', $row);
        $this->assertStringContainsString('LCD-1602', $row);
        $this->assertStringContainsString('Electronics', $row);

        foreach (['£4.50', '£0.68', '7.50%', 'CRL48', '£1.55', '£0.12', '2 / 5 / 10'] as $value) {
            $this->assertStringContainsString($value, $row);
        }

        $this->assertStringContainsString('<strong>5</strong>', $row);
        $this->assertStringContainsString('tint-warning">Low', $row);
        $this->assertStringContainsString('tint-success">Active', $row);
        $this->assertStringContainsString('Sports › Tennis', $this->rowFor($body, 12));
    }

    /**
     * Stock is delivered minus sold; out-of-stock and incoming units are flagged.
     */
    public function testIndexStockStates(): void
    {
        $body = $this->body($this->index());

        $socks = $this->rowFor($body, 11);
        $this->assertStringContainsString('<strong>0</strong>', $socks);
        $this->assertStringContainsString('tint-danger">Out of stock', $socks);
        $this->assertStringContainsString('tint-danger">Inactive', $socks);

        $grip = $this->rowFor($body, 12);
        $this->assertStringContainsString('<strong>90</strong>', $grip);
        $this->assertStringNotContainsString('Low', $grip);
        $this->assertStringContainsString('+20 incoming', $grip);

        foreach (['Out of stock (1)', 'Low stock (1)', 'In stock (1)', 'Awaiting delivery (1)'] as $option) {
            $this->assertMatchesRegularExpression('#>\s*' . preg_quote($option, '#') . '\s*</option>#', $body);
        }
    }

    /**
     * Products are loaded once, ordered by title.
     */
    public function testIndexLoadsProductsByTitle(): void
    {
        $body = $this->body($this->index());

        $this->assertSame([[], ['title' => 'ASC']], $this->findByArgs);
        $this->assertLessThan(strpos($body, 'Tennis Grip'), strpos($body, 'LCD Display'));
        $this->assertLessThan(strpos($body, 'White Socks'), strpos($body, 'Tennis Grip'));
        $this->assertStringContainsString('3 products', $body);
    }

    /**
     * Titles are HTML-escaped.
     */
    public function testIndexEscapesTitles(): void
    {
        $this->addProduct($this->product(13, 'XSS-1', '<script>alert(1)</script>', null));

        $body = $this->body($this->index());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    // ---------- index: search & filters ----------

    /**
     * Filters and the product IDs they should leave.
     *
     * @return array<string, array{array<string, string>, list<int>}>
     */
    public static function filterProvider(): array
    {
        return [
            'search title' => [['q' => 'lcd'], [10]],
            'search SKU' => [['q' => 'grip-tns'], [12]],
            'search code' => [['q' => 'CODE-11'], [11]],
            'search barcode' => [['q' => '0123456789012'], [12]],
            'category' => [['category' => '1'], [10]],
            'category includes subcategories' => [['category' => '2'], [12]],
            'out of stock' => [['stock' => 'out'], [11]],
            'low stock' => [['stock' => 'low'], [10]],
            'in stock' => [['stock' => 'in'], [12]],
            'awaiting delivery' => [['stock' => 'pending'], [12]],
            'shipping service' => [['shipping' => 'TOLP48'], [12]],
            'active' => [['status' => 'active'], [10, 12]],
            'inactive' => [['status' => 'inactive'], [11]],
            'combined' => [['status' => 'active', 'shipping' => 'CRL48', 'stock' => 'low'], [10]],
            'invalid values ignored' => [
                ['category' => '99', 'stock' => 'bogus', 'shipping' => 'NOPE', 'status' => 'x'],
                [10, 11, 12],
            ],
        ];
    }

    /**
     * Search and filters narrow the list and are kept in the form.
     *
     * @param array<string, string> $query
     * @param list<int> $expected Product IDs that should be listed.
     */
    #[DataProvider('filterProvider')]
    public function testIndexFilters(array $query, array $expected): void
    {
        $body = $this->body($this->index($query));

        foreach ([10, 11, 12] as $id) {
            in_array($id, $expected, true)
                ? $this->assertStringContainsString('href="/product/' . $id . '"', $body)
                : $this->assertStringNotContainsString('href="/product/' . $id . '"', $body);
        }
    }

    /**
     * Active filters are shown as selected, the count says "x of y", and Clear appears.
     */
    public function testIndexKeepsFilterState(): void
    {
        $body = $this->body($this->index(['q' => 'grip', 'category' => '2', 'stock' => 'in', 'shipping' => 'TOLP48']));

        $this->assertStringContainsString('1 of 3 products', $body);
        $this->assertStringContainsString('value="grip"', $body);
        $this->assertMatchesRegularExpression('#<option value="2" selected>\s*Sports\s*</option>#', $body);
        $this->assertMatchesRegularExpression('#<option value="in" selected>#', $body);
        $this->assertMatchesRegularExpression('#<option value="TOLP48" selected>#', $body);
        $this->assertStringContainsString('href="/products">Clear', $body);
    }

    /**
     * Empty states distinguish "no matches" from "no products".
     */
    public function testIndexEmptyStates(): void
    {
        $this->assertStringContainsString(
            'No products match these filters.',
            $this->body($this->index(['q' => 'zzz']))
        );

        $this->products = [];
        $this->stockRows = [];
        $this->assertStringContainsString('No products yet.', $this->body($this->index()));
    }

    // ---------- index: pagination ----------

    /**
     * 50 products per page; links keep the filters; out-of-range pages are clamped.
     */
    public function testIndexPaginates(): void
    {
        for ($id = 100; $id < 160; $id++) {
            $this->addProduct($this->product($id, "BULK-$id", "Bulk item $id", $this->categories[1]));
        }

        $first = $this->body($this->index(['category' => '1']));
        $this->assertSame(50, substr_count($first, '<td class="product-cell">'));
        $this->assertStringContainsString('Page 1 of 2', $first);
        $this->assertStringContainsString('href="/products?category=1&amp;page=2"', $first);

        $last = $this->body($this->index(['category' => '1', 'page' => '99']));
        $this->assertSame(11, substr_count($last, '<td class="product-cell">'));
        $this->assertStringContainsString('Page 2 of 2', $last);
        $this->assertStringNotContainsString('No products', $last);
    }

    // ---------- show ----------

    /**
     * The show page displays pricing, identifiers, category and status.
     */
    public function testShowDisplaysProductDetails(): void
    {
        $response = $this->controller->show($this->request(), ['id' => '10']);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>LCD Display 16x2</h1>', $body);
        $this->assertStringContainsString('<a href="/products">Products</a>', $body);

        foreach (['£4.50', '£0.68', '£0.12', '£1.55'] as $amount) {
            $this->assertStringContainsString($amount, $body);
        }

        foreach (
            [
                'SKU' => 'LCD-1602',
                'Code' => 'CODE-10',
                'Barcode / UPC' => '0123456789010',
                'Category' => 'Electronics',
                'Shipping service' => 'CRL48',
                'Promo percentage' => '7.50%',
                'Multi-buy tiers' => '2 / 5 / 10',
                'Updated' => '—',
            ] as $label => $value
        ) {
            $this->assertMatchesRegularExpression(
                '#<dt[^>]*>' . preg_quote($label, '#') . '</dt>\s*<dd[^>]*>' . preg_quote($value, '#') . '</dd>#',
                $body,
                "Detail row '$label' should show '$value'."
            );
        }

        $this->assertStringContainsString('tint-success">Active</span>', $body);
    }

    /**
     * A product without a category or shipping service shows dashes for them.
     */
    public function testShowDisplaysDashesForMissingValues(): void
    {
        $this->products[11]->setShippingService(null);

        $body = $this->body($this->controller->show($this->request(), ['id' => '11']));

        $this->assertMatchesRegularExpression('#<dt[^>]*>Category</dt>\s*<dd[^>]*>—</dd>#', $body);
        $this->assertMatchesRegularExpression('#<dt[^>]*>Shipping service</dt>\s*<dd[^>]*>—</dd>#', $body);
    }

    /**
     * Status values and the badge each one should show.
     *
     * @return array<string, array{int|null, string}>
     */
    public static function statusBadgeProvider(): array
    {
        return [
            'active' => [Product::STATUS_ACTIVE, 'tint-success">Active'],
            'inactive' => [Product::STATUS_INACTIVE, 'tint-danger">Inactive'],
            'legacy zero' => [0, 'tint-danger">Inactive'],
            'missing' => [null, 'tint-danger">Inactive'],
        ];
    }

    /**
     * Only status 1 counts as active; every other value shows as inactive.
     */
    #[DataProvider('statusBadgeProvider')]
    public function testShowStatusBadge(?int $status, string $badge): void
    {
        $this->products[10]->setStatus($status);

        $body = $this->body($this->controller->show($this->request(), ['id' => '10']));

        $this->assertStringContainsString($badge, $body);
    }

    /**
     * Product titles are HTML-escaped on the show page.
     */
    public function testShowEscapesTitle(): void
    {
        $this->addProduct($this->product(13, 'XSS-1', '<b>Bold</b>', null));

        $body = $this->body($this->controller->show($this->request(), ['id' => '13']));

        $this->assertStringNotContainsString('<b>Bold</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $body);
    }

    /**
     * Route IDs that do not match a product.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function unknownProductProvider(): array
    {
        return [
            'unknown id' => [['id' => '999']],
            'missing id' => [[]],
        ];
    }

    /**
     * An unknown or missing ID returns the 404 page.
     *
     * @param array<string, string> $args Route placeholders.
     */
    #[DataProvider('unknownProductProvider')]
    public function testShowReturns404ForUnknownProduct(array $args): void
    {
        $response = $this->controller->show($this->request(), $args);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Product not found.', $this->body($response));
    }

    // ---------- helpers ----------

    /**
     * Build the controller with fake repositories and stock query; the entity
     * manager fails the test if anything is persisted, flushed or removed.
     */
    private function controller(): ProductController
    {
        $products = $this->createStub(EntityRepository::class);
        $products->method('find')->willReturnCallback(fn (mixed $id): ?Product => $this->products[(int) $id] ?? null);
        $products->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null): array {
            $this->findByArgs = [$criteria, $orderBy];
            $sorted = array_values($this->products);
            usort($sorted, static fn (Product $a, Product $b): int => strcmp($a->getTitle(), $b->getTitle()));

            return $sorted;
        });

        $categories = $this->createStub(EntityRepository::class);
        $categories->method('findAll')->willReturnCallback(fn (): array => array_values($this->categories));

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(fn (): array => $this->stockRows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => $class === Category::class ? $categories : $products
        );
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $em->expects($this->never())->method('remove');

        $factory = new Psr17Factory();

        return new ProductController(new PlatesRender(), $factory, $factory, $em, new CategoryPaths($em));
    }

    /**
     * Call index() with the given query.
     *
     * @param array<string, string> $query
     */
    private function index(array $query = []): ResponseInterface
    {
        return $this->controller->index($this->request()->withQueryParams($query));
    }

    /**
     * Build a GET request for a product page.
     */
    private function request(): ServerRequest
    {
        return new ServerRequest('GET', '/products');
    }

    /**
     * Build a fully populated product with a fixed ID.
     */
    private function product(int $id, string $sku, string $title, ?Category $category): Product
    {
        $product = (new Product())
            ->setSku($sku)
            ->setTitle($title)
            ->setCode('CODE-' . $id)
            ->setBarcodeUpc('01234567890' . $id)
            ->setCategory($category)
            ->setPrice('4.50')
            ->setFees('0.68')
            ->setPackageCost('0.12')
            ->setPostageCost('1.55')
            ->setShippingService('CRL48')
            ->setCreated(new DateTime('2026-01-15 09:30:00'));

        $this->setId($product, $id);

        return $product;
    }

    /**
     * Add a product to the fake repository.
     */
    private function addProduct(Product $product): void
    {
        $this->products[$product->getId()] = $product;
    }

    /**
     * Add a category to the fake repository.
     */
    private function addCategory(int $id, string $title, int $parentId): void
    {
        $category = (new Category())->setTitle($title)->setParentId($parentId);
        $this->setId($category, $id);
        $this->categories[$id] = $category;
    }

    /**
     * Set an entity's private ID, as Doctrine would after loading it.
     */
    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    /**
     * The table row for a product, found by its link.
     */
    private function rowFor(string $body, int $id): string
    {
        $link = strpos($body, 'href="/product/' . $id . '"');
        $this->assertNotFalse($link, "Row for product $id not found.");
        $start = strrpos(substr($body, 0, $link), '<tr>');
        $end = strpos($body, '</tr>', $link);

        return substr($body, (int) $start, (int) $end - (int) $start);
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
