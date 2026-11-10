<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\CategoriesController;
use App\Entities\Category;
use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\PlatesRender;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;

/**
 * Tests for every CategoriesController action, using real views and an
 * in-memory fake of the category repository instead of the database.
 */
#[CoversClass(CategoriesController::class)]
#[CoversClass(ControllerAbstract::class)]
final class CategoriesControllerTest extends TestCase
{
    /**
     * Categories held by the fake repository, keyed by ID.
     *
     * @var array<int, Category>
     */
    private array $categories = [];

    /**
     * Number of products the fake product repository reports for any category.
     */
    private int $productCount = 0;

    /**
     * Number of custom SKUs the fake DBAL connection reports for any category.
     */
    private int $customSkuCount = 0;

    /**
     * Entity manager mock; tests set write expectations on it.
     */
    private EntityManagerInterface&MockObject $em;

    /**
     * Controller under test.
     */
    private CategoriesController $controller;

    /**
     * Build the controller with a fresh seeded data set:
     * Electronics (1) and Sports (2) at top level, Tennis (3) under Sports,
     * and Racket Grip (4) under Tennis.
     */
    protected function setUp(): void
    {
        $this->categories = [];
        $this->productCount = 0;
        $this->customSkuCount = 0;

        $this->addCategory(1, 'Electronics', 0, 1);
        $this->addCategory(2, 'Sports', 0, 2);
        $this->addCategory(3, 'Tennis', 2, 0);
        $this->addCategory(4, 'Racket Grip', 3, 0);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(fn (): int => $this->customSkuCount);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => $class === Product::class
                ? $this->productRepository()
                : $this->categoryRepository()
        );
        $this->em->method('getConnection')->willReturn($connection);

        $factory = new Psr17Factory();
        $this->controller = new CategoriesController(new PlatesRender(), $factory, $factory, $this->em);
    }

    // ---------- index ----------

    /**
     * The list shows every category with its parent's title and an edit link.
     */
    public function testIndexListsCategoriesWithParentTitles(): void
    {
        $this->expectNoWrites();

        $response = $this->controller->index();
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('4 categories', $body);
        $this->assertStringContainsString('<a href="/categories/3">Tennis</a>', $body);
        $this->assertStringContainsString('<td>Sports</td>', $body);
        $this->assertStringContainsString('href="/categories/4/edit"', $body);
    }

    /**
     * With no categories the list shows an empty state instead of a table.
     */
    public function testIndexShowsEmptyStateWhenThereAreNoCategories(): void
    {
        $this->expectNoWrites();
        $this->categories = [];

        $body = (string) $this->controller->index()->getBody();

        $this->assertStringContainsString('No categories yet.', $body);
        $this->assertStringNotContainsString('<table', $body);
    }

    // ---------- show ----------

    /**
     * The show page links to the parent and lists direct subcategories.
     */
    public function testShowDisplaysParentAndChildren(): void
    {
        $this->expectNoWrites();

        $response = $this->controller->show($this->request('GET'), ['id' => '3']);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<a href="/categories/2">Sports</a>', $body);
        $this->assertStringContainsString('<a href="/categories/4">Racket Grip</a>', $body);
        $this->assertStringContainsString('action="/categories/3/delete"', $body);
    }

    /**
     * Category titles are HTML-escaped on the show page.
     */
    public function testShowEscapesTitle(): void
    {
        $this->expectNoWrites();
        $this->addCategory(9, '<script>alert(1)</script>', 0, 0);

        $body = (string) $this->controller->show($this->request('GET'), ['id' => '9'])->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    /**
     * An unknown ID returns the 404 page.
     */
    public function testShowReturns404ForUnknownCategory(): void
    {
        $this->expectNoWrites();

        $response = $this->controller->show($this->request('GET'), ['id' => '999']);

        $this->assertNotFound($response);
    }

    // ---------- create ----------

    /**
     * The create form posts to /categories, starts empty and offers every category as parent.
     */
    public function testCreateShowsEmptyForm(): void
    {
        $this->expectNoWrites();

        $response = $this->controller->create();
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('action="/categories"', $body);
        $this->assertStringContainsString('Create category', $body);
        $this->assertMatchesRegularExpression('/name="title"[^>]*value=""/s', $body);

        foreach ([1, 2, 3, 4] as $id) {
            $this->assertStringContainsString('<option value="' . $id . '"', $body);
        }
    }

    // ---------- store ----------

    /**
     * Valid input persists a new category with trimmed values and redirects to it.
     */
    public function testStoreCreatesCategoryAndRedirects(): void
    {
        $saved = null;

        $this->em->expects($this->once())->method('persist')->willReturnCallback(
            function (object $entity) use (&$saved): void {
                $this->assertInstanceOf(Category::class, $entity);
                $this->setId($entity, 10);
                $saved = $entity;
            }
        );
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->never())->method('remove');

        $response = $this->controller->store($this->request('POST', [
            'title' => '  Badminton  ',
            'parent_id' => '2',
            'menu_order' => '3',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/categories/10', $response->getHeaderLine('Location'));
        $this->assertInstanceOf(Category::class, $saved);
        $this->assertSame('Badminton', $saved->getTitle());
        $this->assertSame(2, $saved->getParentId());
        $this->assertSame(3, $saved->getMenuOrder());
    }

    /**
     * Invalid submissions for store, each with the expected field error message.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidStoreProvider(): array
    {
        return [
            'missing title' => [['title' => '', 'parent_id' => '0', 'menu_order' => '0'], 'Title is required.'],
            'whitespace title' => [['title' => '   ', 'parent_id' => '0', 'menu_order' => '0'], 'Title is required.'],
            'title as array' => [['title' => ['x'], 'parent_id' => '0', 'menu_order' => '0'], 'Title is required.'],
            'title too long' => [
                ['title' => str_repeat('a', 192), 'parent_id' => '0', 'menu_order' => '0'],
                'Title must be 191 characters or fewer.',
            ],
            'negative parent' => [
                ['title' => 'X', 'parent_id' => '-1', 'menu_order' => '0'],
                'Choose a valid parent category.',
            ],
            'non-numeric parent' => [
                ['title' => 'X', 'parent_id' => 'abc', 'menu_order' => '0'],
                'Choose a valid parent category.',
            ],
            'unknown parent' => [
                ['title' => 'X', 'parent_id' => '999', 'menu_order' => '0'],
                'The selected parent category does not exist.',
            ],
            'non-numeric menu order' => [
                ['title' => 'X', 'parent_id' => '0', 'menu_order' => 'first'],
                'Menu order must be a whole number.',
            ],
            'decimal menu order' => [
                ['title' => 'X', 'parent_id' => '0', 'menu_order' => '1.5'],
                'Menu order must be a whole number.',
            ],
        ];
    }

    /**
     * Invalid input redisplays the form with a 422 and the error, and saves nothing.
     *
     * @param array<string, mixed> $input Submitted form fields.
     */
    #[DataProvider('invalidStoreProvider')]
    public function testStoreRejectsInvalidInput(array $input, string $message): void
    {
        $this->expectNoWrites();

        $response = $this->controller->store($this->request('POST', $input));
        $body = (string) $response->getBody();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, $body);
        $this->assertStringContainsString('is-invalid', $body);
        $this->assertStringContainsString('action="/categories"', $body);
    }

    /**
     * Submitted values are kept in the redisplayed form after a validation error.
     */
    public function testStoreKeepsSubmittedValuesOnError(): void
    {
        $this->expectNoWrites();

        $body = (string) $this->controller->store($this->request('POST', [
            'title' => 'Kept "title"',
            'parent_id' => '2',
            'menu_order' => 'oops',
        ]))->getBody();

        $this->assertStringContainsString('value="Kept &quot;title&quot;"', $body);
        $this->assertMatchesRegularExpression('/<option value="2"\s+selected>/', $body);
        $this->assertStringContainsString('value="oops"', $body);
    }

    // ---------- edit ----------

    /**
     * The edit form is prefilled with the category's values and posts to its URL.
     */
    public function testEditShowsPrefilledForm(): void
    {
        $this->expectNoWrites();

        $response = $this->controller->edit($this->request('GET'), ['id' => '3']);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('action="/categories/3"', $body);
        $this->assertStringContainsString('value="Tennis"', $body);
        $this->assertMatchesRegularExpression('/<option value="2"\s+selected>/', $body);
    }

    /**
     * The parent dropdown leaves out the category itself and its descendants.
     */
    public function testEditExcludesSelfAndDescendantsFromParentOptions(): void
    {
        $this->expectNoWrites();

        $body = (string) $this->controller->edit($this->request('GET'), ['id' => '2'])->getBody();

        $this->assertStringContainsString('<option value="1"', $body);
        $this->assertStringNotContainsString('<option value="2"', $body);
        $this->assertStringNotContainsString('<option value="3"', $body);
        $this->assertStringNotContainsString('<option value="4"', $body);
    }

    /**
     * Editing an unknown ID returns the 404 page.
     */
    public function testEditReturns404ForUnknownCategory(): void
    {
        $this->expectNoWrites();

        $this->assertNotFound($this->controller->edit($this->request('GET'), ['id' => '999']));
    }

    // ---------- update ----------

    /**
     * Valid input updates the category, stamps `updated`, flushes and redirects.
     */
    public function testUpdateSavesChangesAndRedirects(): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->never())->method('remove');

        $response = $this->controller->update($this->request('POST', [
            'title' => 'Tennis & Squash',
            'parent_id' => '1',
            'menu_order' => '7',
        ]), ['id' => '3']);

        $category = $this->categories[3];

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/categories/3', $response->getHeaderLine('Location'));
        $this->assertSame('Tennis & Squash', $category->getTitle());
        $this->assertSame(1, $category->getParentId());
        $this->assertSame(7, $category->getMenuOrder());
        $this->assertInstanceOf(DateTimeInterface::class, $category->getUpdated());
    }

    /**
     * Parent choices that would create a cycle in the category tree.
     *
     * @return array<string, array{string, string}>
     */
    public static function cyclicParentProvider(): array
    {
        return [
            'itself' => ['2', '2'],
            'direct child' => ['2', '3'],
            'grandchild' => ['2', '4'],
        ];
    }

    /**
     * Moving a category under itself or a descendant is rejected with 422 and changes nothing.
     */
    #[DataProvider('cyclicParentProvider')]
    public function testUpdateRejectsCyclicParent(string $id, string $parentId): void
    {
        $this->expectNoWrites();

        $response = $this->controller->update($this->request('POST', [
            'title' => 'Renamed',
            'parent_id' => $parentId,
            'menu_order' => '0',
        ]), ['id' => $id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'A category cannot be its own parent or a child of its own subcategory.',
            (string) $response->getBody()
        );
        $this->assertSame('Sports', $this->categories[(int) $id]->getTitle());
        $this->assertSame(0, $this->categories[(int) $id]->getParentId());
        $this->assertNull($this->categories[(int) $id]->getUpdated());
    }

    /**
     * Updating an unknown ID returns the 404 page and saves nothing.
     */
    public function testUpdateReturns404ForUnknownCategory(): void
    {
        $this->expectNoWrites();

        $response = $this->controller->update(
            $this->request('POST', ['title' => 'X', 'parent_id' => '0', 'menu_order' => '0']),
            ['id' => '999']
        );

        $this->assertNotFound($response);
    }

    // ---------- delete ----------

    /**
     * A category nothing depends on is removed, flushed, and the user is sent to the list.
     */
    public function testDeleteRemovesUnusedCategory(): void
    {
        $this->em->expects($this->once())->method('remove')->with($this->identicalTo($this->categories[4]));
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->never())->method('persist');

        $response = $this->controller->delete($this->request('POST'), ['id' => '4']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/categories', $response->getHeaderLine('Location'));
    }

    /**
     * Records that block a delete, with the expected message.
     *
     * @return array<string, array{int, int, int, string}>
     */
    public static function deleteBlockerProvider(): array
    {
        return [
            'has a subcategory' => [3, 0, 0, 'It still has 1 subcategory. Move or delete it first.'],
            'has products' => [4, 5, 0, 'It still has 5 products. Move or delete them first.'],
            'has one product' => [4, 1, 0, 'It still has 1 product. Move or delete it first.'],
            'has custom SKUs' => [4, 0, 2, 'It still has 2 custom SKUs. Move or delete them first.'],
        ];
    }

    /**
     * A category still in use is not deleted; the show page returns 409 with the reason.
     */
    #[DataProvider('deleteBlockerProvider')]
    public function testDeleteRefusesCategoryInUse(
        int $id,
        int $products,
        int $customSkus,
        string $message
    ): void {
        $this->expectNoWrites();
        $this->productCount = $products;
        $this->customSkuCount = $customSkus;

        $response = $this->controller->delete($this->request('POST'), ['id' => (string) $id]);
        $body = (string) $response->getBody();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString("This category can't be deleted.", $body);
        $this->assertStringContainsString($message, $body);
    }

    /**
     * Deleting an unknown ID returns the 404 page and removes nothing.
     */
    public function testDeleteReturns404ForUnknownCategory(): void
    {
        $this->expectNoWrites();

        $this->assertNotFound($this->controller->delete($this->request('POST'), ['id' => '999']));
    }

    // ---------- helpers ----------

    /**
     * Assert the entity manager is never asked to persist, flush or remove.
     */
    private function expectNoWrites(): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->em->expects($this->never())->method('remove');
    }

    /**
     * Assert the response is the "Category not found." 404 page.
     */
    private function assertNotFound(ResponseInterface $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Category not found.', (string) $response->getBody());
    }

    /**
     * Build a request, with a parsed form body for POST requests.
     *
     * @param array<string, mixed> $body Form fields.
     */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        return (new ServerRequest($method, '/categories'))->withParsedBody($body);
    }

    /**
     * Add a category with a fixed ID to the fake repository.
     */
    private function addCategory(int $id, string $title, int $parentId, int $menuOrder): void
    {
        $category = (new Category())
            ->setTitle($title)
            ->setParentId($parentId)
            ->setMenuOrder($menuOrder);

        $this->setId($category, $id);
        $this->categories[$id] = $category;
    }

    /**
     * Set an entity's private ID, as Doctrine would after loading or inserting it.
     */
    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    /**
     * Fake category repository backed by $this->categories, supporting the
     * find/findAll/findBy/count calls the controller makes.
     *
     * @return EntityRepository<Category>
     */
    private function categoryRepository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);

        $repository->method('find')->willReturnCallback(
            fn (mixed $id): ?Category => $this->categories[(int) $id] ?? null
        );
        $repository->method('findAll')->willReturnCallback(fn (): array => array_values($this->categories));
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->matching($criteria)
        );
        $repository->method('count')->willReturnCallback(
            fn (array $criteria = []): int => count($this->matching($criteria))
        );

        return $repository;
    }

    /**
     * Fake product repository whose count() returns $this->productCount.
     *
     * @return EntityRepository<Product>
     */
    private function productRepository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('count')->willReturnCallback(fn (): int => $this->productCount);

        return $repository;
    }

    /**
     * Categories matching findBy-style criteria (only `parentId` is used by the controller).
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<Category>
     */
    private function matching(array $criteria): array
    {
        return array_values(array_filter(
            $this->categories,
            static fn (Category $category): bool => !array_key_exists('parentId', $criteria)
                || $category->getParentId() === $criteria['parentId']
        ));
    }
}
