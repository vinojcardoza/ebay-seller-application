<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\ProductController;
use App\Entities\Category;
use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\PlatesRender;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for every ProductController action, using real views and an
 * in-memory fake of the product repository instead of the database.
 */
#[CoversClass(ProductController::class)]
#[CoversClass(ControllerAbstract::class)]
final class ProductControllerTest extends TestCase
{
    /**
     * Products held by the fake repository, keyed by ID.
     *
     * @var array<int, Product>
     */
    private array $products = [];

    /**
     * Fake product repository; `find()` reads from $this->products.
     *
     * @var EntityRepository<Product>&Stub
     */
    private EntityRepository&Stub $repository;

    /**
     * Controller under test.
     */
    private ProductController $controller;

    /**
     * Build the controller with two seeded products: an active one in the
     * Electronics category and an inactive one with no category.
     */
    protected function setUp(): void
    {
        $electronics = (new Category())->setTitle('Electronics');
        $this->setId($electronics, 1);

        $this->products = [];
        $this->addProduct($this->product(10, 'LCD-1602', 'LCD Display 16x2', $electronics));
        $this->addProduct(
            $this->product(11, 'SCK-WHT', 'White Socks', null)->setStatus(Product::STATUS_INACTIVE)
        );

        $this->repository = $this->createStub(EntityRepository::class);
        $this->repository->method('find')->willReturnCallback(
            fn (mixed $id): ?Product => $this->products[(int) $id] ?? null
        );

        $this->controller = $this->controllerWith($this->repository);
    }

    // ---------- index ----------

    /**
     * The list asks for all products ordered by title.
     */
    public function testIndexLoadsProductsOrderedByTitle(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with([], ['title' => 'ASC'])
            ->willReturn(array_values($this->products));

        $this->assertSame(200, $this->controllerWith($repository)->index()->getStatusCode());
    }

    /**
     * Each row shows the SKU, a link to the product, its category, price and status.
     */
    public function testIndexListsProductDetails(): void
    {
        $this->repository->method('findBy')->willReturn(array_values($this->products));

        $body = (string) $this->controller->index()->getBody();

        $this->assertStringContainsString('2 products', $body);
        $this->assertStringContainsString('LCD-1602', $body);
        $this->assertStringContainsString('<a href="/product/10">LCD Display 16x2</a>', $body);
        $this->assertStringContainsString('<td>Electronics</td>', $body);
        $this->assertStringContainsString('£4.50', $body);
        $this->assertSame(1, substr_count($body, 'tint-success">Active</span>'));
        $this->assertSame(1, substr_count($body, 'tint-danger">Inactive</span>'));
    }

    /**
     * A product without a category shows a dash in the category column.
     */
    public function testIndexShowsDashForProductWithoutCategory(): void
    {
        $this->repository->method('findBy')->willReturn([$this->products[11]]);

        $body = (string) $this->controller->index()->getBody();

        $this->assertStringContainsString('<td>—</td>', $body);
    }

    /**
     * Product titles are HTML-escaped in the list.
     */
    public function testIndexEscapesTitles(): void
    {
        $this->repository->method('findBy')->willReturn([
            $this->product(12, 'XSS-1', '<script>alert(1)</script>', null),
        ]);

        $body = (string) $this->controller->index()->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    /**
     * With no products the list shows an empty state instead of a table.
     */
    public function testIndexShowsEmptyStateWhenThereAreNoProducts(): void
    {
        $this->repository->method('findBy')->willReturn([]);

        $body = (string) $this->controller->index()->getBody();

        $this->assertStringContainsString('No products yet.', $body);
        $this->assertStringNotContainsString('<table', $body);
    }

    // ---------- show ----------

    /**
     * The show page displays pricing, identifiers, category and status.
     */
    public function testShowDisplaysProductDetails(): void
    {
        $response = $this->controller->show($this->request(), ['id' => '10']);
        $body = (string) $response->getBody();

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
                'Shipping service' => 'Royal Mail 2nd Class',
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

        $body = (string) $this->controller->show($this->request(), ['id' => '11'])->getBody();

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

        $body = (string) $this->controller->show($this->request(), ['id' => '10'])->getBody();

        $this->assertStringContainsString($badge, $body);
    }

    /**
     * Product titles are HTML-escaped on the show page.
     */
    public function testShowEscapesTitle(): void
    {
        $this->addProduct($this->product(12, 'XSS-1', '<b>Bold</b>', null));

        $body = (string) $this->controller->show($this->request(), ['id' => '12'])->getBody();

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
        $this->assertStringContainsString('Product not found.', (string) $response->getBody());
    }

    // ---------- helpers ----------

    /**
     * Build the controller around the given product repository, with an entity
     * manager that fails the test if anything is persisted, flushed or removed.
     *
     * @param EntityRepository<Product> $repository
     */
    private function controllerWith(EntityRepository $repository): ProductController
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            function (string $class) use ($repository): EntityRepository {
                $this->assertSame(Product::class, $class);

                return $repository;
            }
        );
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $em->expects($this->never())->method('remove');

        $factory = new Psr17Factory();

        return new ProductController(new PlatesRender(), $factory, $factory, $em);
    }

    /**
     * Build a GET request for a product page.
     */
    private function request(): ServerRequest
    {
        return new ServerRequest('GET', '/product');
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
            ->setShippingService('Royal Mail 2nd Class')
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
     * Set an entity's private ID, as Doctrine would after loading it.
     */
    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
