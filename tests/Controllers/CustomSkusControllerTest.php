<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Catalog\CategoryPaths;
use App\Controllers\CustomSkusController;
use App\Entities\Category;
use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\Product;
use App\Framework\Template\PlatesRender;
use DateTimeInterface;
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
 * Tests for every CustomSkusController action, using real views and an
 * in-memory fake of the custom SKU, line, product and category repositories.
 */
#[CoversClass(CustomSkusController::class)]
final class CustomSkusControllerTest extends TestCase
{
    /**
     * Fake tables, keyed by entity class then ID.
     *
     * @var array<class-string, array<int, object>>
     */
    private array $rows = [];

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
    private CustomSkusController $controller;

    /**
     * Seed: categories Sports › Tennis and Electronics; products 470 (regulator),
     * 410 (heatsink) and 300 (socks, SKU "SCK-1"); custom SKU 105 "VOLT-HTSNK"
     * bundling 1 × 470 (£4.00/£0.72) and 1 × 410 (£2.99/£0.48); custom SKU 50
     * "SCK-3P" (inactive) with 3 × 300 (£11.99/£2.05); and empty custom SKU 60 "EMPTY-KIT".
     */
    protected function setUp(): void
    {
        $this->rows = [];
        $this->persisted = [];
        $this->removed = [];
        $this->flushes = 0;

        $this->add($this->withId((new Category())->setTitle('Sports')->setParentId(0), 1));
        $this->add($this->withId((new Category())->setTitle('Tennis')->setParentId(1), 2));
        $this->add($this->withId((new Category())->setTitle('Electronics')->setParentId(0), 3));

        $this->product(470, 'VOLT-L7824', 'Voltage Regulator L7824');
        $this->product(410, 'TO220-HTSNK', 'Heatsink TO-220');
        $this->product(300, 'SCK-1', 'Football Socks');

        $bundle = $this->customSku(105, 'VOLT-HTSNK', CustomSku::STATUS_ACTIVE, 3);
        $this->line(1, $bundle, 470, 1, '4.00', '0.72');
        $this->line(2, $bundle, 410, 1, '2.99', '0.48');

        $socks = $this->customSku(50, 'SCK-3P', CustomSku::STATUS_INACTIVE, 1);
        $this->line(3, $socks, 300, 3, '11.99', '2.05');

        $this->customSku(60, 'EMPTY-KIT', CustomSku::STATUS_ACTIVE, null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => $this->repository($class)
        );
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            $ids = array_keys($this->rows[$entity::class] ?? []);
            $this->add($this->withId($entity, ($ids === [] ? 0 : max($ids)) + 1));
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });

        $factory = new Psr17Factory();
        $this->controller = new CustomSkusController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            new CategoryPaths($em)
        );
    }

    // ---------- index ----------

    /**
     * The list shows every custom SKU (sorted by SKU) with its products, category, price and status.
     */
    public function testIndexListsCustomSkus(): void
    {
        $body = $this->body($this->controller->index($this->get()));

        $this->assertStringContainsString('3 custom SKUs', $body);
        $this->assertLessThan(strpos($body, 'SCK-3P'), strpos($body, 'EMPTY-KIT'), 'Sorted by SKU.');
        $this->assertLessThan(strpos($body, 'VOLT-HTSNK'), strpos($body, 'SCK-3P'));
        $this->assertMatchesRegularExpression('#1 ×\s+Voltage Regulator L7824#', $body);
        $this->assertMatchesRegularExpression('#3 ×\s+Football Socks#', $body);
        $this->assertStringContainsString('£6.99', $body, 'Bundle price is the sum of line prices.');
        $this->assertStringContainsString('£11.99', $body);
        $this->assertStringContainsString('No products</span>', $body);
        $this->assertStringContainsString('tint-danger">Inactive', $this->rowFor($body, 'SCK-3P'));
        $this->assertStringContainsString('Electronics', $this->rowFor($body, 'VOLT-HTSNK'));
        $this->assertStringContainsString('href="/custom-skus/105/edit"', $body);
    }

    /**
     * Searches and the SKUs they should return.
     *
     * @return array<string, array{array<string, string>, list<string>, string}>
     */
    public static function filterProvider(): array
    {
        return [
            'case-insensitive SKU search' => [['q' => 'volt'], ['VOLT-HTSNK'], '1 of 3 custom SKUs'],
            'active only' => [['status' => '1'], ['EMPTY-KIT', 'VOLT-HTSNK'], '2 of 3 custom SKUs'],
            'inactive only' => [['status' => '2'], ['SCK-3P'], '1 of 3 custom SKUs'],
            'invalid status ignored' => [['status' => '7'], ['EMPTY-KIT', 'SCK-3P', 'VOLT-HTSNK'], '3 custom SKUs'],
        ];
    }

    /**
     * The list can be filtered by SKU text and status.
     *
     * @param array<string, string> $query
     * @param list<string> $expected
     */
    #[DataProvider('filterProvider')]
    public function testIndexFilters(array $query, array $expected, string $count): void
    {
        $body = $this->body($this->controller->index($this->get($query)));

        $this->assertStringContainsString($count, $body);

        foreach (['EMPTY-KIT', 'SCK-3P', 'VOLT-HTSNK'] as $sku) {
            in_array($sku, $expected, true)
                ? $this->assertStringContainsString(">$sku</a>", $body)
                : $this->assertStringNotContainsString(">$sku</a>", $body);
        }
    }

    /**
     * Empty states distinguish "none yet" from "no matches".
     */
    public function testIndexEmptyStates(): void
    {
        $this->assertStringContainsString(
            'No custom SKUs match these filters.',
            $this->body($this->controller->index($this->get(['q' => 'nothing'])))
        );

        $this->rows[CustomSku::class] = [];
        $this->assertStringContainsString(
            'No custom SKUs yet.',
            $this->body($this->controller->index($this->get()))
        );
    }

    /**
     * Redirect codes show their message.
     */
    public function testIndexNotice(): void
    {
        $this->assertStringContainsString(
            'Custom SKU deleted.',
            $this->body($this->controller->index($this->get(['done' => 'deleted'])))
        );
    }

    // ---------- show ----------

    /**
     * The show page lists the bundle's products and its totals.
     */
    public function testShowDisplaysLinesAndTotals(): void
    {
        $response = $this->controller->show($this->get(), ['id' => '105']);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>VOLT-HTSNK</h1>', $body);
        $this->assertStringContainsString('<a href="/product/470">Voltage Regulator L7824</a>', $body);
        $this->assertStringContainsString('TO220-HTSNK', $body);
        $this->assertMatchesRegularExpression('#2 products,\s+2 units#', $body);

        // Price 4.00 + 2.99, fees 0.72 + 0.48, postage 1.55 + packaging 0.08.
        $tiles = ['£6.99' => 'Bundle price', '£1.20' => 'Fees', '£5.79' => 'After fees', '£4.16' => 'After postage'];

        foreach ($tiles as $value => $label) {
            $this->assertMatchesRegularExpression(
                '#kpi-value">' . preg_quote($value, '#') . '</p>\s*<p class="kpi-label">' . $label . '#',
                $body
            );
        }

        $this->assertStringContainsString('£3.28', $body, 'Line after fees: 4.00 − 0.72.');
        $this->assertStringContainsString('action="/custom-skus/105/products"', $body);
        $this->assertStringContainsString('href="/custom-skus/105/products/1/edit"', $body);
        $this->assertStringContainsString('action="/custom-skus/105/products/2/delete"', $body);
        $this->assertStringContainsString('action="/custom-skus/105/delete"', $body);
        $this->assertStringContainsString('<option value="410"', $body, 'Add form lists products.');
    }

    /**
     * A custom SKU without products explains that orders for it cannot be imported.
     */
    public function testShowEmptyBundle(): void
    {
        $body = $this->body($this->controller->show($this->get(['done' => 'created']), ['id' => '60']));

        $this->assertStringContainsString("eBay orders for it can't be imported until it has some", $body);
        $this->assertStringContainsString('Custom SKU created. Add its products below.', $body);
    }

    /**
     * Unknown or malformed IDs return the 404 page.
     */
    public function testShowNotFound(): void
    {
        $this->assertNotFound($this->controller->show($this->get(), ['id' => '999']), 'Custom SKU not found.');
        $this->assertNotFound($this->controller->show($this->get(), ['id' => 'abc']), 'Custom SKU not found.');
    }

    // ---------- create & store ----------

    /**
     * The create form starts active with zero costs and shows categories by their full path.
     */
    public function testCreateForm(): void
    {
        $body = $this->body($this->controller->create());

        $this->assertStringContainsString('action="/custom-skus"', $body);
        $this->assertMatchesRegularExpression('#<option value="1"\s+selected>Active</option>#', $body);
        $this->assertStringContainsString('value="0.00"', $body);
        $this->assertMatchesRegularExpression('#<option value="2" >\s*Sports › Tennis\s*</option>#', $body);
        $this->assertMatchesRegularExpression(
            '#>\s*Electronics\s*</option>.*?>\s*Sports\s*</option>.*?>\s*Sports › Tennis\s*</option>#s',
            $body,
            'Categories sorted by path.'
        );
    }

    /**
     * A valid custom SKU is saved with normalised amounts and the user is sent to it.
     */
    public function testStoreCreatesCustomSku(): void
    {
        $response = $this->controller->store($this->post([
            'sku' => ' NEW-KIT ',
            'category' => '2',
            'shipping_service' => '',
            'postage_cost' => '2',
            'package_cost' => '0.5',
            'status' => '2',
        ]));

        $created = $this->persisted[0];
        $this->assertInstanceOf(CustomSku::class, $created);
        $this->assertRedirect('/custom-skus/' . $created->getId() . '?done=created', $response);
        $this->assertSame('NEW-KIT', $created->getSku());
        $this->assertSame('Tennis', $created->getCategory()?->getTitle());
        $this->assertNull($created->getShippingService());
        $this->assertSame('2.00', $created->getPostageCost());
        $this->assertSame('0.50', $created->getPackageCost());
        $this->assertSame(CustomSku::STATUS_INACTIVE, $created->getStatus());
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Invalid custom SKU input and the expected message.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidSkuProvider(): array
    {
        return [
            'missing SKU' => [['sku' => ''], 'SKU is required.'],
            'SKU too long' => [['sku' => str_repeat('A', 192)], 'SKU must be 191 characters or fewer.'],
            'duplicate custom SKU' => [['sku' => 'SCK-3P'], 'Another custom SKU already uses this SKU.'],
            'clashes with a product' => [['sku' => 'SCK-1'], 'A product already uses this SKU'],
            'unknown category' => [['category' => '99'], 'Choose a valid category.'],
            'shipping service too long' => [
                ['shipping_service' => str_repeat('x', 192)],
                'Shipping service must be 191',
            ],
            'non-numeric postage' => [['postage_cost' => 'abc'], 'Postage cost must be an amount like 1.55.'],
            'negative postage' => [['postage_cost' => '-1'], 'Postage cost must be an amount like 1.55.'],
            'three decimals' => [['package_cost' => '0.055'], 'Packaging cost must be an amount like 1.55.'],
            'bad status' => [['status' => '0'], 'Choose a status.'],
        ];
    }

    /**
     * Invalid input redisplays the form with a 422 and saves nothing.
     *
     * @param array<string, string> $override Fields changed from a valid submission.
     */
    #[DataProvider('invalidSkuProvider')]
    public function testStoreRejectsInvalidInput(array $override, string $message): void
    {
        $response = $this->controller->store($this->post($override + $this->validSku()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, html_entity_decode($this->body($response), ENT_QUOTES));
        $this->assertNoWrites();
    }

    // ---------- edit & update ----------

    /**
     * The edit form is prefilled with the custom SKU's values.
     */
    public function testEditForm(): void
    {
        $body = $this->body($this->controller->edit($this->get(), ['id' => '105']));

        $this->assertStringContainsString('action="/custom-skus/105"', $body);
        $this->assertStringContainsString('value="VOLT-HTSNK"', $body);
        $this->assertStringContainsString('value="CRL48"', $body);
        $this->assertStringContainsString('value="1.55"', $body);
        $this->assertMatchesRegularExpression('#<option value="3" selected>\s*Electronics#', $body);
        $this->assertNotFound($this->controller->edit($this->get(), ['id' => '999']), 'Custom SKU not found.');
    }

    /**
     * Saving changes updates the custom SKU (keeping its own SKU is allowed) and stamps `updated`.
     */
    public function testUpdateSavesChanges(): void
    {
        $response = $this->controller->update(
            $this->post(['sku' => 'VOLT-HTSNK', 'postage_cost' => '3.10', 'category' => ''] + $this->validSku()),
            ['id' => '105']
        );

        $customSku = $this->find(CustomSku::class, 105);
        $this->assertRedirect('/custom-skus/105?done=updated', $response);
        $this->assertSame('3.10', $customSku->getPostageCost());
        $this->assertNull($customSku->getCategory());
        $this->assertInstanceOf(DateTimeInterface::class, $customSku->getUpdated());
        $this->assertSame(1, $this->flushes);
        $this->assertSame([], $this->persisted);
    }

    /**
     * Renaming to another custom SKU's SKU is rejected; unknown IDs are 404.
     */
    public function testUpdateRejections(): void
    {
        $response = $this->controller->update($this->post(['sku' => 'SCK-3P'] + $this->validSku()), ['id' => '105']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VOLT-HTSNK', $this->find(CustomSku::class, 105)->getSku());
        $this->assertNotFound(
            $this->controller->update($this->post($this->validSku()), ['id' => '999']),
            'Custom SKU not found.'
        );
        $this->assertNoWrites();
    }

    // ---------- delete ----------

    /**
     * Deleting removes the custom SKU and all its lines.
     */
    public function testDeleteRemovesCustomSkuAndLines(): void
    {
        $response = $this->controller->delete($this->post([]), ['id' => '105']);

        $this->assertRedirect('/custom-skus?done=deleted', $response);
        $this->assertSame(
            [
                $this->find(CustomSkuProduct::class, 1),
                $this->find(CustomSkuProduct::class, 2),
                $this->find(CustomSku::class, 105),
            ],
            $this->removed
        );
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Deleting an unknown custom SKU is 404 and removes nothing.
     */
    public function testDeleteNotFound(): void
    {
        $this->assertNotFound($this->controller->delete($this->post([]), ['id' => '999']), 'Custom SKU not found.');
        $this->assertNoWrites();
    }

    // ---------- product lines ----------

    /**
     * Adding a product creates a line linked to the custom SKU, with normalised amounts.
     */
    public function testAddLine(): void
    {
        $response = $this->controller->addLine(
            $this->post(['product' => '300', 'quantity' => '2', 'price' => '9', 'fees' => '1.6']),
            ['id' => '105']
        );

        $line = $this->persisted[0];
        $this->assertInstanceOf(CustomSkuProduct::class, $line);
        $this->assertRedirect('/custom-skus/105?done=line-added', $response);
        $this->assertSame($this->find(CustomSku::class, 105), $line->getCustomSku());
        $this->assertSame($this->find(Product::class, 300), $line->getProduct());
        $this->assertSame(2, $line->getQuantity());
        $this->assertSame('9.00', $line->getPrice());
        $this->assertSame('1.60', $line->getFees());
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Invalid product line input and the expected message.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidLineProvider(): array
    {
        return [
            'no product' => [['product' => ''], 'Choose a product.'],
            'unknown product' => [['product' => '999'], 'Choose a product.'],
            'product already in bundle' => [['product' => '470'], 'This product is already in the custom SKU'],
            'zero quantity' => [['quantity' => '0'], 'Quantity must be a whole number of 1 or more.'],
            'fractional quantity' => [['quantity' => '1.5'], 'Quantity must be a whole number of 1 or more.'],
            'bad price' => [['price' => '£5'], 'Price must be an amount like 4.99.'],
            'bad fees' => [['fees' => ''], 'Fees must be an amount like 4.99.'],
            'fees above price' => [['price' => '1.00', 'fees' => '1.01'], 'Fees cannot be more than the price.'],
        ];
    }

    /**
     * Invalid lines redisplay the show page with a 422, keep the input and save nothing.
     *
     * @param array<string, string> $override Fields changed from a valid line.
     */
    #[DataProvider('invalidLineProvider')]
    public function testAddLineRejectsInvalidInput(array $override, string $message): void
    {
        $response = $this->controller->addLine($this->post($override + $this->validLine()), ['id' => '105']);
        $body = html_entity_decode($this->body($response), ENT_QUOTES);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, $body);
        $this->assertStringContainsString('<h1>VOLT-HTSNK</h1>', $body);
        $this->assertNoWrites();
    }

    /**
     * Adding to an unknown custom SKU is 404.
     */
    public function testAddLineNotFound(): void
    {
        $this->assertNotFound(
            $this->controller->addLine($this->post($this->validLine()), ['id' => '999']),
            'Custom SKU not found.'
        );
        $this->assertNoWrites();
    }

    /**
     * The add-product picker on the show page leaves out inactive products.
     */
    public function testShowHidesInactiveProducts(): void
    {
        $this->find(Product::class, 410)->setStatus(Product::STATUS_INACTIVE);

        $body = $this->body($this->controller->show($this->get(), ['id' => '105']));

        $this->assertStringNotContainsString('<option value="410"', $body);
        $this->assertStringContainsString('<option value="470"', $body);
    }

    /**
     * The line edit form keeps the line's product selected even when it is inactive.
     */
    public function testEditLineFormKeepsInactiveSelectedProduct(): void
    {
        $this->find(Product::class, 300)->setStatus(Product::STATUS_INACTIVE);
        $this->find(Product::class, 410)->setStatus(Product::STATUS_INACTIVE);

        $body = $this->body($this->controller->editLine($this->get(), ['id' => '50', 'lineId' => '3']));

        $this->assertMatchesRegularExpression('#<option value="300" selected>#', $body);
        $this->assertStringNotContainsString('<option value="410"', $body);
    }

    /**
     * The line edit form is prefilled with the line's values.
     */
    public function testEditLineForm(): void
    {
        $body = $this->body($this->controller->editLine($this->get(), ['id' => '50', 'lineId' => '3']));

        $this->assertStringContainsString('action="/custom-skus/50/products/3"', $body);
        $this->assertMatchesRegularExpression('#<option value="300" selected>#', $body);
        $this->assertStringContainsString('value="3"', $body);
        $this->assertStringContainsString('value="11.99"', $body);
        $this->assertStringContainsString('value="2.05"', $body);
    }

    /**
     * A line can be updated, including switching to another product, and is stamped `updated`.
     */
    public function testUpdateLine(): void
    {
        $response = $this->controller->updateLine(
            $this->post(['product' => '300', 'quantity' => '2', 'price' => '5.00', 'fees' => '0.90']),
            ['id' => '105', 'lineId' => '2']
        );

        $line = $this->find(CustomSkuProduct::class, 2);
        $this->assertRedirect('/custom-skus/105?done=line-updated', $response);
        $this->assertSame(300, $line->getProduct()?->getId());
        $this->assertSame(2, $line->getQuantity());
        $this->assertSame('5.00', $line->getPrice());
        $this->assertInstanceOf(DateTimeInterface::class, $line->getUpdated());
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Keeping a line's own product is allowed; switching to one already in the bundle is not.
     */
    public function testUpdateLineDuplicateRules(): void
    {
        $same = $this->controller->updateLine(
            $this->post(['product' => '410'] + $this->validLine()),
            ['id' => '105', 'lineId' => '2']
        );
        $this->assertSame(302, $same->getStatusCode());

        $duplicate = $this->controller->updateLine(
            $this->post(['product' => '470'] + $this->validLine()),
            ['id' => '105', 'lineId' => '2']
        );
        $this->assertSame(422, $duplicate->getStatusCode());
        $this->assertSame(410, $this->find(CustomSkuProduct::class, 2)->getProduct()?->getId());
    }

    /**
     * Removing a line deletes it and returns to the custom SKU.
     */
    public function testRemoveLine(): void
    {
        $response = $this->controller->removeLine($this->post([]), ['id' => '105', 'lineId' => '1']);

        $this->assertRedirect('/custom-skus/105?done=line-removed', $response);
        $this->assertSame([$this->find(CustomSkuProduct::class, 1)], $this->removed);
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Line routes with an unknown line, or a line that belongs to another custom SKU.
     *
     * @return array<string, array{string, array<string, string>}>
     */
    public static function foreignLineProvider(): array
    {
        return [
            'edit unknown line' => ['editLine', ['id' => '105', 'lineId' => '99']],
            'edit line of another custom SKU' => ['editLine', ['id' => '105', 'lineId' => '3']],
            'update line of another custom SKU' => ['updateLine', ['id' => '105', 'lineId' => '3']],
            'remove line of another custom SKU' => ['removeLine', ['id' => '105', 'lineId' => '3']],
            'remove line under unknown custom SKU' => ['removeLine', ['id' => '999', 'lineId' => '1']],
        ];
    }

    /**
     * A line can only be reached through its own custom SKU; anything else is 404 with no writes.
     *
     * @param array<string, string> $args
     */
    #[DataProvider('foreignLineProvider')]
    public function testLineRoutesRequireMatchingCustomSku(string $action, array $args): void
    {
        $response = $this->controller->$action($this->post($this->validLine()), $args);

        $this->assertNotFound($response, 'Product line not found.');
        $this->assertNoWrites();
    }

    // ---------- helpers ----------

    /**
     * A valid custom SKU submission.
     *
     * @return array<string, string>
     */
    private function validSku(): array
    {
        return [
            'sku' => 'NEW-KIT',
            'category' => '3',
            'shipping_service' => 'CRL48',
            'postage_cost' => '1.55',
            'package_cost' => '0.08',
            'status' => '1',
        ];
    }

    /**
     * A valid product line submission (product 300, not yet in custom SKU 105).
     *
     * @return array<string, string>
     */
    private function validLine(): array
    {
        return ['product' => '300', 'quantity' => '1', 'price' => '4.99', 'fees' => '0.88'];
    }

    /**
     * Add a product to the fake table.
     */
    private function product(int $id, string $sku, string $title): void
    {
        $this->add($this->withId((new Product())->setSku($sku)->setTitle($title), $id));
    }

    /**
     * Add a custom SKU (postage 1.55, packaging 0.08, CRL48) to the fake table.
     */
    private function customSku(int $id, string $sku, int $status, ?int $categoryId): CustomSku
    {
        $customSku = (new CustomSku())
            ->setSku($sku)
            ->setStatus($status)
            ->setShippingService('CRL48')
            ->setPostageCost('1.55')
            ->setPackageCost('0.08')
            ->setCategory($categoryId !== null ? $this->find(Category::class, $categoryId) : null);

        $this->add($this->withId($customSku, $id));

        return $customSku;
    }

    /**
     * Add a product line to a custom SKU (and its collection, as Doctrine would load it).
     */
    private function line(
        int $id,
        CustomSku $customSku,
        int $productId,
        int $quantity,
        string $price,
        string $fees
    ): void {
        $line = (new CustomSkuProduct())
            ->setCustomSku($customSku)
            ->setProduct($this->find(Product::class, $productId))
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setFees($fees);

        $customSku->getCustomSkuProducts()->add($line);
        $this->add($this->withId($line, $id));
    }

    /**
     * Store an entity in its fake table.
     */
    private function add(object $entity): void
    {
        $id = (new ReflectionProperty($entity, 'id'))->getValue($entity);
        $this->rows[$entity::class][$id] = $entity;
    }

    /**
     * Set an entity's private ID.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    /**
     * Fetch an entity from the fake tables, failing the test if missing.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, int $id): object
    {
        $entity = $this->rows[$class][$id] ?? null;
        $this->assertInstanceOf($class, $entity);

        return $entity;
    }

    /**
     * Fake repository over the in-memory table for a class, supporting the
     * find/findAll/findBy/findOneBy calls the controller makes.
     *
     * @return EntityRepository<object>
     */
    private function repository(string $class): EntityRepository
    {
        $matching = function (array $criteria) use ($class): array {
            return array_values(array_filter(
                $this->rows[$class] ?? [],
                static function (object $entity) use ($criteria): bool {
                    foreach ($criteria as $field => $value) {
                        if ($entity->{'get' . ucfirst($field)}() !== $value) {
                            return false;
                        }
                    }

                    return true;
                }
            ));
        };

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (mixed $id): ?object => $this->rows[$class][(int) $id] ?? null
        );
        $repository->method('findAll')->willReturnCallback(fn (): array => array_values($this->rows[$class] ?? []));
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?object => $matching($criteria)[0] ?? null
        );
        $repository->method('findBy')->willReturnCallback(
            static function (array $criteria, ?array $orderBy = null) use ($matching): array {
                $result = $matching($criteria);
                $field = $orderBy !== null ? array_key_first($orderBy) : null;

                if ($field !== null && $field !== 'id') {
                    usort($result, static fn (object $a, object $b): int
                        => strcmp((string) $a->{'get' . ucfirst($field)}(), (string) $b->{'get' . ucfirst($field)}()));
                }

                return $result;
            }
        );

        return $repository;
    }

    /**
     * Find the table row containing a SKU link.
     */
    private function rowFor(string $body, string $sku): string
    {
        $link = strpos($body, ">$sku</a>");
        $this->assertNotFalse($link, "Row for $sku not found.");
        $start = strrpos(substr($body, 0, $link), '<tr>');
        $end = strpos($body, '</tr>', $link);

        return substr($body, (int) $start, (int) $end - (int) $start);
    }

    /**
     * Assert nothing was persisted, removed or flushed.
     */
    private function assertNoWrites(): void
    {
        $this->assertSame([], $this->persisted, 'Nothing should be persisted.');
        $this->assertSame([], $this->removed, 'Nothing should be removed.');
        $this->assertSame(0, $this->flushes, 'Nothing should be flushed.');
    }

    /**
     * Assert the response is the 404 page with the given message.
     */
    private function assertNotFound(ResponseInterface $response, string $message): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString($message, $this->body($response));
    }

    /**
     * Assert the response is a 302 redirect to the given location.
     */
    private function assertRedirect(string $location, ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($location, $response->getHeaderLine('Location'));
    }

    /**
     * Build a GET request with query parameters.
     *
     * @param array<string, string> $query
     */
    private function get(array $query = []): ServerRequest
    {
        return (new ServerRequest('GET', '/custom-skus'))->withQueryParams($query);
    }

    /**
     * Build a POST request with form fields.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): ServerRequest
    {
        return (new ServerRequest('POST', '/custom-skus'))->withParsedBody($fields);
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
