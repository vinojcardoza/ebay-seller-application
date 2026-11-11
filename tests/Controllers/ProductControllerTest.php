<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Catalog\CategoryPaths;
use App\Catalog\StockLevels;
use App\Controllers\ProductController;
use App\Entities\Category;
use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\OrderItem;
use App\Entities\Product;
use App\Entities\Purchase;
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
 * pagination), the detail page, and creating, editing and deleting products,
 * using real views and in-memory fakes.
 */
#[CoversClass(ProductController::class)]
#[CoversClass(ControllerAbstract::class)]
#[CoversClass(CategoryPaths::class)]
#[CoversClass(StockLevels::class)]
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
     * Custom SKU codes that exist, for the SKU clash check.
     *
     * @var list<string>
     */
    private array $customSkuCodes = [];

    /**
     * How many purchases, order items and custom SKU lines use each product, keyed by entity class then product ID.
     *
     * @var array<class-string, array<int, int>>
     */
    private array $usage = [];

    /**
     * Entities passed to persist().
     *
     * @var list<object>
     */
    private array $persisted = [];

    /**
     * Entities passed to remove().
     *
     * @var list<object>
     */
    private array $removed = [];

    /**
     * Number of flush() calls.
     */
    private int $flushes = 0;

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
        $this->customSkuCodes = ['VOLT-HTSNK'];
        $this->usage = [];
        $this->persisted = [];
        $this->removed = [];
        $this->flushes = 0;

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
     * Each row shows ID, product, prices, promotion, shipping, stock and status, but no category or multi-buy.
     */
    public function testIndexShowsAllColumns(): void
    {
        $body = $this->body($this->index());

        foreach (
            ['ID', 'Product', 'Selling price', 'Fees', 'Promotion', 'Shipping service', 'Postage',
                'Package', 'Stock', 'Status'] as $heading
        ) {
            $this->assertMatchesRegularExpression('#<th[^>]*>' . preg_quote($heading, '#') . '</th>#', $body);
        }

        $row = $this->rowFor($body, 10);
        $this->assertStringContainsString('>10</td>', $row);
        $this->assertStringContainsString('<a href="/product/10">LCD Display 16x2</a>', $row);
        $this->assertStringContainsString('LCD-1602', $row);
        $this->assertStringNotContainsString('<th>Category</th>', $body);
        $this->assertStringNotContainsString('Electronics', $row);

        foreach (['£4.50', '£0.68', '7.50%', 'CRL48', '£1.55', '£0.12'] as $value) {
            $this->assertStringContainsString($value, $row);
        }

        $this->assertStringNotContainsString('Multi-buy', $body);
        $this->assertStringNotContainsString('2 / 5 / 10', $row);

        $this->assertStringContainsString('<strong>5</strong>', $row);
        $this->assertStringContainsString('tint-warning">Low', $row);
        $this->assertStringContainsString('tint-success">Active', $row);
        $this->assertStringNotContainsString('Sports › Tennis', $this->rowFor($body, 12));
        $this->assertMatchesRegularExpression(
            '#<option value="3"[^>]*>\s*Sports › Tennis#',
            $body,
            'Still a filter option.'
        );
    }

    /**
     * Stock is delivered minus sold; out-of-stock and incoming units are flagged.
     */
    public function testIndexStockStates(): void
    {
        $body = $this->body($this->index(['status' => 'all']));

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
        $body = $this->body($this->index(['status' => 'all']));

        $this->assertSame([[], ['title' => 'ASC']], $this->findByArgs);
        $this->assertLessThan(strpos($body, 'Tennis Grip'), strpos($body, 'LCD Display'));
        $this->assertLessThan(strpos($body, 'White Socks'), strpos($body, 'Tennis Grip'));
        $this->assertStringContainsString('3 of 3 products', $body);
    }

    /**
     * By default only active products are listed, Active is selected and the count is not "x of y".
     */
    public function testIndexHidesInactiveByDefault(): void
    {
        $body = $this->body($this->index());

        $this->assertStringContainsString('href="/product/10"', $body);
        $this->assertStringContainsString('href="/product/12"', $body);
        $this->assertStringNotContainsString('href="/product/11"', $body);
        $this->assertMatchesRegularExpression('#<option value="active" selected>#', $body);
        $this->assertMatchesRegularExpression('#<option value="inactive"\s*>\s*Inactive#', $body);
        $this->assertStringContainsString('2 products', $body);
        $this->assertStringNotContainsString(' of 3 products', $body);
        $this->assertStringNotContainsString('href="/products">Clear', $body);
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
            'search code' => [['q' => 'CODE-11'], []],
            'search code, all statuses' => [['q' => 'CODE-11', 'status' => 'all'], [11]],
            'search barcode' => [['q' => '0123456789012'], [12]],
            'category' => [['category' => '1'], [10]],
            'category includes subcategories' => [['category' => '2'], [12]],
            'out of stock' => [['stock' => 'out', 'status' => 'all'], [11]],
            'low stock' => [['stock' => 'low'], [10]],
            'in stock' => [['stock' => 'in'], [12]],
            'awaiting delivery' => [['stock' => 'pending'], [12]],
            'shipping service' => [['shipping' => 'TOLP48'], [12]],
            'active' => [['status' => 'active'], [10, 12]],
            'inactive' => [['status' => 'inactive'], [11]],
            'all statuses' => [['status' => 'all'], [10, 11, 12]],
            'combined' => [['status' => 'active', 'shipping' => 'CRL48', 'stock' => 'low'], [10]],
            'invalid values ignored' => [
                ['category' => '99', 'stock' => 'bogus', 'shipping' => 'NOPE', 'status' => 'x'],
                [10, 12],
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
     * Empty states distinguish "no matches", "no active products" and "no products".
     */
    public function testIndexEmptyStates(): void
    {
        $this->assertStringContainsString(
            'No products match these filters.',
            $this->body($this->index(['q' => 'zzz']))
        );

        $this->products[10]->setStatus(Product::STATUS_INACTIVE);
        $this->products[12]->setStatus(Product::STATUS_INACTIVE);
        $this->assertStringContainsString('No active products.', $this->body($this->index()));

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
        $this->assertStringNotContainsString('Multi-buy', $body);

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

    // ---------- create / store ----------

    /**
     * The create form is prefilled with column defaults and lists categories and shipping services.
     */
    public function testCreateForm(): void
    {
        $response = $this->controller->create();
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Add product</h1>', $body);
        $this->assertStringContainsString('action="/products"', $body);
        $this->assertMatchesRegularExpression('#id="promo_percentage"[^>]*value="7.50"#', $body);
        $this->assertMatchesRegularExpression('#id="low_stock"[^>]*value="10"#', $body);
        $this->assertMatchesRegularExpression('#<option value="1"\s+selected>Active</option>#', $body);
        $this->assertMatchesRegularExpression('#<option value="3" >\s*Sports › Tennis#', $body);
        $this->assertStringContainsString('<option value="TOLP48"></option>', $body);
        $this->assertSame([], $this->persisted);
    }

    /**
     * A valid submission creates the product with normalised amounts and redirects to it.
     */
    public function testStoreCreatesProduct(): void
    {
        $response = $this->controller->store($this->post($this->form()));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/product/500?done=created', $response->getHeaderLine('Location'));
        $this->assertCount(1, $this->persisted);
        $this->assertSame(1, $this->flushes);

        $product = $this->persisted[0];
        $this->assertInstanceOf(Product::class, $product);
        $this->assertSame('NEW-1', $product->getSku());
        $this->assertSame('New Product', $product->getTitle());
        $this->assertSame('NP1', $product->getCode());
        $this->assertSame('5012345678900', $product->getBarcodeUpc());
        $this->assertSame($this->categories[3], $product->getCategory());
        $this->assertSame('9.99', $product->getPrice());
        $this->assertSame('1.50', $product->getFees());
        $this->assertSame('5.00', $product->getPromoPercentage());
        $this->assertSame('CRL48', $product->getShippingService());
        $this->assertSame('1.55', $product->getPostageCost());
        $this->assertSame('0.12', $product->getPackageCost());
        $this->assertSame(4, $product->getLowStock());
        $this->assertSame(Product::STATUS_ACTIVE, $product->getStatus());
    }

    /**
     * Optional fields left blank are stored as empty text or null.
     */
    public function testStoreAcceptsBlankOptionalFields(): void
    {
        $this->controller->store($this->post($this->form([
            'code' => '', 'barcode_upc' => '', 'category' => '', 'shipping_service' => '', 'low_stock' => '',
            'status' => '2',
        ])));

        $product = $this->persisted[0];
        $this->assertInstanceOf(Product::class, $product);
        $this->assertSame('', $product->getCode());
        $this->assertSame('', $product->getBarcodeUpc());
        $this->assertNull($product->getCategory());
        $this->assertNull($product->getShippingService());
        $this->assertNull($product->getLowStock());
        $this->assertSame(Product::STATUS_INACTIVE, $product->getStatus());
    }

    /**
     * Invalid submissions and the error message each should show.
     *
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidProductProvider(): array
    {
        return [
            'missing SKU' => [['sku' => ''], 'sku', 'SKU is required.'],
            'missing title' => [['title' => ''], 'title', 'Title is required.'],
            'long title' => [['title' => str_repeat('x', 192)], 'title', 'Title must be 191 characters or fewer.'],
            'long code' => [['code' => str_repeat('x', 192)], 'code', 'Code must be 191 characters or fewer.'],
            'duplicate SKU' => [['sku' => 'LCD-1602'], 'sku', 'Another product already uses this SKU.'],
            'custom SKU clash' => [['sku' => 'VOLT-HTSNK'], 'sku', 'A custom SKU already uses this SKU'],
            'unknown category' => [['category' => '99'], 'category', 'Choose a valid category.'],
            'bad category' => [['category' => 'abc'], 'category', 'Choose a valid category.'],
            'bad price' => [['price' => '9.999'], 'price', 'Selling price must be an amount like 4.99.'],
            'missing fees' => [['fees' => ''], 'fees', 'Fees must be an amount like 4.99.'],
            'fees above price' => [['fees' => '10'], 'fees', 'Fees cannot be more than the selling price.'],
            'bad postage' => [['postage_cost' => '-1'], 'postage_cost', 'Postage cost must be an amount'],
            'bad packaging' => [['package_cost' => 'x'], 'package_cost', 'Packaging cost must be an amount'],
            'promotion over 100' => [['promo_percentage' => '100.5'], 'promo_percentage', 'from 0 to 100'],
            'bad promotion' => [['promo_percentage' => 'ten'], 'promo_percentage', 'from 0 to 100'],
            'negative low stock' => [['low_stock' => '-1'], 'low_stock', 'whole number of 0 or more'],
            'fractional low stock' => [['low_stock' => '2.5'], 'low_stock', 'whole number of 0 or more'],
            'bad status' => [['status' => '0'], 'status', 'Choose a status.'],
        ];
    }

    /**
     * Invalid input redisplays the form with 422, the field marked invalid, and nothing saved.
     *
     * @param array<string, string> $overrides Form fields to change.
     */
    #[DataProvider('invalidProductProvider')]
    public function testStoreRejectsInvalidInput(array $overrides, string $field, string $message): void
    {
        $response = $this->controller->store($this->post($this->form($overrides)));
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Please fix the highlighted fields.', $body);
        $this->assertMatchesRegularExpression(
            '#id="' . $field . '-error">[^<]*' . preg_quote(htmlspecialchars($message), '#') . '#',
            $body
        );
        $this->assertStringContainsString('aria-describedby="' . $field . '-error"', $body);
        $this->assertStringContainsString('value="5012345678900"', $body, 'Submitted values are kept.');
        $this->assertSame([], $this->persisted);
        $this->assertSame(0, $this->flushes);
    }

    // ---------- edit / update ----------

    /**
     * The edit form is prefilled with the product's values.
     */
    public function testEditForm(): void
    {
        $response = $this->controller->edit($this->request(), ['id' => '12']);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Edit product</h1>', $body);
        $this->assertStringContainsString('action="/product/12"', $body);
        $this->assertStringContainsString('href="/product/12">GRIP-TNS</a>', $body);

        foreach (['GRIP-TNS', 'Tennis Grip', 'CODE-12', '4.50', '0.68', '7.50', 'TOLP48', '1.55', '0.12'] as $value) {
            $this->assertStringContainsString('value="' . $value . '"', $body);
        }

        $this->assertMatchesRegularExpression('#<option value="3" selected>\s*Sports › Tennis#', $body);
        $this->assertMatchesRegularExpression('#<option value="1"\s+selected>Active</option>#', $body);
    }

    /**
     * A product with a legacy or missing status is edited as Inactive.
     */
    public function testEditFormShowsLegacyStatusAsInactive(): void
    {
        $this->products[10]->setStatus(0);

        $body = $this->body($this->controller->edit($this->request(), ['id' => '10']));

        $this->assertMatchesRegularExpression('#<option value="2"\s+selected>Inactive</option>#', $body);
    }

    /**
     * A valid update changes the product, sets the updated time and redirects to it.
     */
    public function testUpdateSavesChanges(): void
    {
        $response = $this->controller->update(
            $this->post($this->form(['sku' => 'LCD-1602', 'title' => 'LCD 16x2 Blue', 'status' => '2'])),
            ['id' => '10']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/product/10?done=updated', $response->getHeaderLine('Location'));
        $this->assertSame(1, $this->flushes);
        $this->assertSame([], $this->persisted);

        $product = $this->products[10];
        $this->assertSame('LCD-1602', $product->getSku(), 'Keeping its own SKU is allowed.');
        $this->assertSame('LCD 16x2 Blue', $product->getTitle());
        $this->assertSame('9.99', $product->getPrice());
        $this->assertSame(Product::STATUS_INACTIVE, $product->getStatus());
        $this->assertNotNull($product->getUpdated());
    }

    /**
     * Updating to another product's SKU is refused with 422 and nothing saved.
     */
    public function testUpdateRejectsDuplicateSku(): void
    {
        $response = $this->controller->update($this->post($this->form(['sku' => 'GRIP-TNS'])), ['id' => '10']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Another product already uses this SKU.', $this->body($response));
        $this->assertSame('LCD Display 16x2', $this->products[10]->getTitle());
        $this->assertSame(0, $this->flushes);
    }

    // ---------- show notices ----------

    /**
     * The show page has Edit and Delete controls and shows `?done=` notices.
     */
    public function testShowHasEditDeleteAndNotice(): void
    {
        $request = $this->request()->withQueryParams(['done' => 'created']);
        $body = $this->body($this->controller->show($request, ['id' => '10']));

        $this->assertStringContainsString('href="/product/10/edit"', $body);
        $this->assertStringContainsString('action="/product/10/delete"', $body);
        $this->assertStringContainsString('data-confirm="Delete this product?"', $body);
        $this->assertStringContainsString('Product created.', $body);
    }

    /**
     * The list has an Add product button and shows the deleted notice.
     */
    public function testIndexHasAddButtonAndNotice(): void
    {
        $body = $this->body($this->index(['done' => 'deleted']));

        $this->assertStringContainsString('href="/products/create"', $body);
        $this->assertStringContainsString('Product deleted.', $body);
        $this->assertStringNotContainsString('alert-success', $this->body($this->index(['done' => 'bogus'])));
    }

    // ---------- delete ----------

    /**
     * An unused product is removed and the list shows a notice.
     */
    public function testDeleteRemovesUnusedProduct(): void
    {
        $response = $this->controller->delete($this->post([]), ['id' => '12']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/products?done=deleted', $response->getHeaderLine('Location'));
        $this->assertSame([$this->products[12]], $this->removed);
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Records that block deleting a product, and the message each gives.
     *
     * @return array<string, array{class-string, int, string}>
     */
    public static function deleteBlockerProvider(): array
    {
        return [
            'purchases' => [Purchase::class, 2, 'It is used by 2 purchases.'],
            'order item' => [OrderItem::class, 1, 'It is used by 1 order item.'],
            'custom SKU lines' => [CustomSkuProduct::class, 3, 'It is used by 3 custom SKU lines.'],
        ];
    }

    /**
     * A product still in use is not deleted: 409 with the reason and the Inactive hint.
     *
     * @param class-string $class Entity that references the product.
     */
    #[DataProvider('deleteBlockerProvider')]
    public function testDeleteRefusesProductInUse(string $class, int $count, string $message): void
    {
        $this->usage[$class][12] = $count;

        $response = $this->controller->delete($this->post([]), ['id' => '12']);
        $body = $this->body($response);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString("This product can't be deleted.", $body);
        $this->assertStringContainsString($message, $body);
        $this->assertStringContainsString('Set its status to Inactive', $body);
        $this->assertSame([], $this->removed);
        $this->assertSame(0, $this->flushes);
    }

    // ---------- not found ----------

    /**
     * Actions on a product, called with the given route arguments.
     *
     * @return array<string, array{string}>
     */
    public static function productActionProvider(): array
    {
        return [
            'edit' => ['edit'],
            'update' => ['update'],
            'delete' => ['delete'],
        ];
    }

    /**
     * Edit, update and delete return 404 for an unknown or non-numeric ID and change nothing.
     */
    #[DataProvider('productActionProvider')]
    public function testActionsReturn404ForUnknownProduct(string $action): void
    {
        foreach ([['id' => '999'], ['id' => 'abc'], []] as $args) {
            $response = $this->controller->$action($this->post($this->form()), $args);

            $this->assertSame(404, $response->getStatusCode());
            $this->assertStringContainsString('Product not found.', $this->body($response));
        }

        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->removed);
        $this->assertSame(0, $this->flushes);
    }

    // ---------- helpers ----------

    /**
     * Build the controller with fake repositories and stock query; the entity
     * manager records what is persisted, removed and flushed.
     */
    private function controller(): ProductController
    {
        $products = $this->createStub(EntityRepository::class);
        $products->method('find')->willReturnCallback(fn (mixed $id): ?Product => $this->products[(int) $id] ?? null);
        $products->method('findOneBy')->willReturnCallback(function (array $criteria): ?Product {
            foreach ($this->products as $product) {
                if ($product->getSku() === $criteria['sku']) {
                    return $product;
                }
            }

            return null;
        });
        $products->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null): array {
            $this->findByArgs = [$criteria, $orderBy];
            $sorted = array_values($this->products);
            usort($sorted, static fn (Product $a, Product $b): int => strcmp($a->getTitle(), $b->getTitle()));

            return $sorted;
        });

        $categories = $this->createStub(EntityRepository::class);
        $categories->method('findAll')->willReturnCallback(fn (): array => array_values($this->categories));
        $categories->method('find')->willReturnCallback(
            fn (mixed $id): ?Category => $this->categories[(int) $id] ?? null
        );

        $customSkus = $this->createStub(EntityRepository::class);
        $customSkus->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?CustomSku => in_array($criteria['sku'], $this->customSkuCodes, true)
                ? (new CustomSku())->setSku($criteria['sku']) : null
        );

        $repositories = [Category::class => $categories, CustomSku::class => $customSkus, Product::class => $products];

        foreach ([Purchase::class, OrderItem::class, CustomSkuProduct::class] as $class) {
            $repositories[$class] = $this->createStub(EntityRepository::class);
            $repositories[$class]->method('count')->willReturnCallback(
                fn (array $criteria): int => $this->usage[$class][$criteria['product']->getId()] ?? 0
            );
        }

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(fn (): array => $this->stockRows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => $repositories[$class]
        );
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;

            foreach ($this->persisted as $entity) {
                if (!(new ReflectionProperty($entity, 'id'))->isInitialized($entity)) {
                    $this->setId($entity, 500);
                }
            }
        });

        $factory = new Psr17Factory();

        return new ProductController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            new CategoryPaths($em),
            new StockLevels($em)
        );
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
     * Build a POST request with the given form fields.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): ServerRequest
    {
        return (new ServerRequest('POST', '/products'))->withParsedBody($fields);
    }

    /**
     * A valid product form submission, with fields overridden as given.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'sku' => 'NEW-1',
            'title' => 'New Product',
            'code' => 'NP1',
            'barcode_upc' => '5012345678900',
            'category' => '3',
            'price' => '9.99',
            'fees' => '1.5',
            'promo_percentage' => '5',
            'shipping_service' => 'CRL48',
            'postage_cost' => '1.55',
            'package_cost' => '0.12',
            'low_stock' => '4',
            'status' => '1',
        ];
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
