<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Catalog\CategoryPaths;
use App\Controllers\PurchasesController;
use App\Entities\Category;
use App\Entities\Product;
use App\Entities\Purchase;
use App\Entities\Supplier;
use App\Framework\Template\PlatesRender;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
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
use Tests\Support\FrozenClock;

/**
 * Tests for every PurchasesController action, using real views and in-memory
 * fakes of the purchase, product, supplier and category repositories.
 */
#[CoversClass(PurchasesController::class)]
#[CoversClass(CategoryPaths::class)]
final class PurchasesControllerTest extends TestCase
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
     * Arguments of the last Purchase findBy() and count() calls.
     *
     * @var array{findBy?: list<mixed>, count?: list<mixed>}
     */
    private array $calls = [];

    /**
     * SQL, parameters and types of the last summary query, or null if none ran.
     *
     * @var array{string, list<mixed>, list<mixed>}|null
     */
    private ?array $summaryQuery = null;

    /**
     * Controller under test (clock: 2026-11-10 12:00).
     */
    private PurchasesController $controller;

    /**
     * Seed: suppliers 1 Ali Express and 2 Amazon; categories Electronics (1) and
     * Sports › Tennis (2, 3); products 410 heatsink (Electronics) and 300 grip
     * (Tennis); purchase 7 pending (10 × 410 for £15.00) and purchase 8 delivered
     * (5 × 300 for £10.00, received 3 Nov).
     */
    protected function setUp(): void
    {
        $this->rows = [];
        $this->persisted = [];
        $this->removed = [];
        $this->flushes = 0;
        $this->calls = [];
        $this->summaryQuery = null;

        $this->add((new Supplier())->setSupplierName('Ali Express'), 1);
        $this->add((new Supplier())->setSupplierName('Amazon'), 2);
        $this->add((new Category())->setTitle('Electronics')->setParentId(0), 1);
        $this->add((new Category())->setTitle('Sports')->setParentId(0), 2);
        $this->add((new Category())->setTitle('Tennis')->setParentId(2), 3);
        $this->add(
            (new Product())
                ->setSku('TO220-HTSNK')
                ->setTitle('Heatsink TO-220')
                ->setCategory($this->find(Category::class, 1)),
            410
        );
        $this->add(
            (new Product())->setSku('GRIP-1')->setTitle('Racket Grip')->setCategory($this->find(Category::class, 3)),
            300
        );

        $this->add($this->purchase(1, 410, '2026-11-01 12:00:00', 10, '15.00', Purchase::STATUS_PENDING, null), 7);
        $this->add(
            $this->purchase(
                2,
                300,
                '2026-10-20 09:30:00',
                5,
                '10.00',
                Purchase::STATUS_DELIVERED,
                '2026-11-03 12:00:00'
            ),
            8
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            function (string $sql, array $params = [], array $types = []): array {
                $this->summaryQuery = [$sql, $params, $types];

                return ['purchases' => 2, 'units' => 15, 'spend' => '25.00', 'pending' => 1];
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => $this->repository($class)
        );
        $em->method('contains')->willReturnCallback(
            fn (object $entity): bool => in_array($entity, $this->rows[$entity::class] ?? [], true)
        );
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });

        $factory = new Psr17Factory();
        $this->controller = new PurchasesController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            new CategoryPaths($em),
            new FrozenClock('2026-11-10 12:00:00')
        );
    }

    // ---------- index ----------

    /**
     * The list shows totals, and each purchase with its unit price, status and actions.
     */
    public function testIndexListsPurchases(): void
    {
        $body = $this->body($this->controller->index($this->get()));

        $kpis = [['£25.00', 'Total spend'], ['2', 'Purchases'], ['15', 'Units bought'], ['1', 'Awaiting delivery']];

        foreach ($kpis as [$value, $label]) {
            $this->assertMatchesRegularExpression(
                '#kpi-value">' . preg_quote($value, '#') . '</p>\s*<p class="kpi-label">' . $label . '<#',
                $body
            );
        }

        $pending = $this->rowFor($body, 'Heatsink TO-220');
        $this->assertStringContainsString('01 Nov 2026', $pending);
        $this->assertStringContainsString('Ali Express', $pending);
        $this->assertStringContainsString('<a href="/product/410">Heatsink TO-220</a>', $pending);
        $this->assertStringContainsString('£1.50', $pending, '£15.00 / 10.');
        $this->assertStringContainsString('tint-warning">Pending', $pending);
        $this->assertStringContainsString('action="/purchases/7/receive"', $pending);

        $delivered = $this->rowFor($body, 'Racket Grip');
        $this->assertStringContainsString('tint-success">Delivered', $delivered);
        $this->assertStringContainsString('03 Nov 2026', $delivered);
        $this->assertStringNotContainsString('/receive', $delivered);
        $this->assertStringContainsString('href="/purchases/8/edit"', $delivered);
    }

    /**
     * Without filters, every purchase is listed newest first, 50 per page, and totals cover everything.
     */
    public function testIndexQueriesNewestFirst(): void
    {
        $this->controller->index($this->get(['page' => '1']));

        $this->assertSame([[], ['purchasedDate' => 'DESC', 'id' => 'DESC'], 50, 0], $this->calls['findBy'] ?? null);
        $this->assertNotNull($this->summaryQuery);
        $this->assertStringNotContainsString('WHERE', $this->summaryQuery[0]);
    }

    /**
     * Status, supplier and product search become repository criteria and summary conditions.
     */
    public function testIndexFilters(): void
    {
        $body = $this->body($this->controller->index($this->get(['status' => '0', 'supplier' => '1', 'q' => 'heat'])));

        $criteria = $this->calls['findBy'][0] ?? null;
        $this->assertSame(
            ['status' => 0, 'supplier' => $this->find(Supplier::class, 1), 'product' => [410]],
            $criteria
        );
        $this->assertSame($criteria, $this->calls['count'][0] ?? null);
        $this->assertNotNull($this->summaryQuery);
        [$sql, $params, $types] = $this->summaryQuery;
        $this->assertStringContainsString('WHERE status = ? AND supplier = ? AND product IN (?)', $sql);
        $this->assertSame([0, 1, [410]], $params);
        $this->assertSame(ArrayParameterType::INTEGER, $types[2]);
        $this->assertStringContainsString('Spend (filtered)', $body);
        $this->assertStringContainsString('value="heat"', $body);
        $this->assertStringContainsString('href="/purchases"', $body, 'Clear link.');
    }

    /**
     * Product search matches SKU or title, case-insensitively.
     */
    public function testIndexSearchMatchesSku(): void
    {
        $this->controller->index($this->get(['q' => 'grip-1']));

        $this->assertSame(['product' => [300]], $this->calls['findBy'][0] ?? null);
    }

    /**
     * A search matching no product shows the empty state without querying purchases.
     */
    public function testIndexSearchWithoutMatches(): void
    {
        $body = $this->body($this->controller->index($this->get(['q' => 'nothing-like-this'])));

        $this->assertStringContainsString('No purchases match these filters.', $body);
        $this->assertSame([], $this->calls);
        $this->assertNull($this->summaryQuery);
        $this->assertMatchesRegularExpression('#kpi-value">£0.00<#', $body);
    }

    /**
     * Invalid filter values are ignored.
     */
    public function testIndexIgnoresInvalidFilters(): void
    {
        $this->controller->index($this->get(['status' => '5', 'supplier' => '99', 'page' => 'x']));

        $this->assertSame([[], ['purchasedDate' => 'DESC', 'id' => 'DESC'], 50, 0], $this->calls['findBy'] ?? null);
    }

    /**
     * Redirect codes show their message.
     */
    public function testIndexNotice(): void
    {
        $this->assertStringContainsString(
            'Purchase marked as delivered.',
            $this->body($this->controller->index($this->get(['done' => 'received'])))
        );
    }

    // ---------- create & store ----------

    /**
     * The form starts pending, dated today, with products grouped by category path.
     */
    public function testCreateForm(): void
    {
        $body = $this->body($this->controller->create());

        $this->assertStringContainsString('action="/purchases"', $body);
        $this->assertStringContainsString('value="2026-11-10"', $body);
        $this->assertMatchesRegularExpression('#<option value="0" selected>\s*Pending#', $body);
        $this->assertMatchesRegularExpression(
            '#<optgroup label="Electronics">\s*<option value="410" >\s*Heatsink TO-220 \(TO220-HTSNK\)#',
            $body
        );
        $this->assertStringContainsString('<optgroup label="Sports › Tennis">', $body);
    }

    /**
     * A valid purchase is saved with dates at midday and a normalised price.
     */
    public function testStoreCreatesPurchase(): void
    {
        $response = $this->controller->store($this->post([
            'supplier' => '2',
            'product' => '300',
            'purchased_date' => '2026-11-05',
            'quantity' => '20',
            'total_price' => '10.7',
            'status' => '1',
            'received_date' => '2026-11-09',
        ]));

        $this->assertRedirect('/purchases?done=created', $response);
        $purchase = $this->persisted[0];
        $this->assertInstanceOf(Purchase::class, $purchase);
        $this->assertSame($this->find(Supplier::class, 2), $purchase->getSupplier());
        $this->assertSame($this->find(Product::class, 300), $purchase->getProduct());
        $this->assertSame('2026-11-05 12:00:00', $purchase->getPurchasedDate()->format('Y-m-d H:i:s'));
        $this->assertSame(20, $purchase->getQuantity());
        $this->assertSame('10.70', $purchase->getTotalPrice());
        $this->assertSame(Purchase::STATUS_DELIVERED, $purchase->getStatus());
        $this->assertSame('2026-11-09 12:00:00', $purchase->getReceivedDate()?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Invalid purchase input and the expected message.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPurchaseProvider(): array
    {
        return [
            'no supplier' => [['supplier' => ''], 'Choose a supplier.'],
            'unknown supplier' => [['supplier' => '99'], 'Choose a supplier.'],
            'no product' => [['product' => ''], 'Choose a product.'],
            'unknown product' => [['product' => '1'], 'Choose a product.'],
            'missing date' => [['purchased_date' => ''], 'Enter the purchase date.'],
            'impossible date' => [['purchased_date' => '2026-02-30'], 'Enter the purchase date.'],
            'future date' => [['purchased_date' => '2026-11-11'], 'The purchase date cannot be in the future.'],
            'zero quantity' => [['quantity' => '0'], 'Quantity must be a whole number of 1 or more.'],
            'bad price' => [['total_price' => '12,50'], 'Total price must be an amount like 29.45.'],
            'bad status' => [['status' => '2'], 'Choose a status.'],
            'delivered without date' => [
                ['status' => '1', 'received_date' => ''],
                'Enter the date the delivery arrived.',
            ],
            'pending with date' => [
                ['status' => '0', 'received_date' => '2026-11-09'],
                'A pending purchase has no received date',
            ],
            'received before purchase' => [
                ['status' => '1', 'received_date' => '2026-11-01'],
                'The received date cannot be before the purchase date.',
            ],
            'invalid received date' => [['status' => '1', 'received_date' => 'soon'], 'Enter a valid received date.'],
        ];
    }

    /**
     * Invalid input redisplays the form with a 422, keeps the input and saves nothing.
     *
     * @param array<string, string> $override Fields changed from a valid submission.
     */
    #[DataProvider('invalidPurchaseProvider')]
    public function testStoreRejectsInvalidInput(array $override, string $message): void
    {
        $submitted = $override + $this->validPurchase();
        $response = $this->controller->store($this->post($submitted));
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, $body);
        $this->assertStringContainsString('value="' . $submitted['quantity'] . '"', $body, 'Input is kept.');
        $this->assertNoWrites();
    }

    // ---------- edit & update ----------

    /**
     * The edit form is prefilled, including the received date and delete button.
     */
    public function testEditForm(): void
    {
        $body = $this->body($this->controller->edit($this->get(), ['id' => '8']));

        $this->assertStringContainsString('action="/purchases/8"', $body);
        $this->assertStringContainsString('value="2026-10-20"', $body);
        $this->assertStringContainsString('value="2026-11-03"', $body);
        $this->assertStringContainsString('value="10.00"', $body);
        $this->assertMatchesRegularExpression('#<option value="300" selected>#', $body);
        $this->assertMatchesRegularExpression('#<option value="2" selected>\s*Amazon#', $body);
        $this->assertStringContainsString('action="/purchases/8/delete"', $body);
        $this->assertNotFound($this->controller->edit($this->get(), ['id' => '99']));
    }

    /**
     * Saving with the same purchase date keeps its original time of day.
     */
    public function testUpdateKeepsTimeWhenDateUnchanged(): void
    {
        $response = $this->controller->update(
            $this->post(['purchased_date' => '2026-10-20', 'total_price' => '11.00'] + $this->validPurchase()),
            ['id' => '8']
        );

        $purchase = $this->find(Purchase::class, 8);
        $this->assertRedirect('/purchases?done=updated', $response);
        $this->assertSame('2026-10-20 09:30:00', $purchase->getPurchasedDate()->format('Y-m-d H:i:s'));
        $this->assertSame('11.00', $purchase->getTotalPrice());
        $this->assertInstanceOf(DateTimeInterface::class, $purchase->getUpdated());
        $this->assertSame(1, $this->flushes);
        $this->assertSame([], $this->persisted);
    }

    /**
     * Changing the date stores it at midday.
     */
    public function testUpdateChangesDate(): void
    {
        $this->controller->update(
            $this->post(['purchased_date' => '2026-10-22'] + $this->validPurchase()),
            ['id' => '8']
        );

        $this->assertSame(
            '2026-10-22 12:00:00',
            $this->find(Purchase::class, 8)->getPurchasedDate()->format('Y-m-d H:i:s')
        );
    }

    /**
     * Invalid updates change nothing; unknown IDs are 404.
     */
    public function testUpdateRejections(): void
    {
        $response = $this->controller->update(
            $this->post(['quantity' => '-1'] + $this->validPurchase()),
            ['id' => '8']
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(5, $this->find(Purchase::class, 8)->getQuantity());
        $this->assertNotFound($this->controller->update($this->post($this->validPurchase()), ['id' => '99']));
        $this->assertNoWrites();
    }

    // ---------- receive ----------

    /**
     * Marking a pending purchase delivered stamps today's date at midday.
     */
    public function testReceiveMarksPendingPurchaseDelivered(): void
    {
        $response = $this->controller->receive($this->post([]), ['id' => '7']);

        $purchase = $this->find(Purchase::class, 7);
        $this->assertRedirect('/purchases?done=received', $response);
        $this->assertSame(Purchase::STATUS_DELIVERED, $purchase->getStatus());
        $this->assertSame('2026-11-10 12:00:00', $purchase->getReceivedDate()?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->flushes);
    }

    /**
     * An already delivered purchase keeps its received date.
     */
    public function testReceiveLeavesDeliveredPurchaseAlone(): void
    {
        $response = $this->controller->receive($this->post([]), ['id' => '8']);

        $this->assertRedirect('/purchases?done=received', $response);
        $this->assertSame('2026-11-03', $this->find(Purchase::class, 8)->getReceivedDate()?->format('Y-m-d'));
        $this->assertNoWrites();
    }

    // ---------- receive selected ----------

    /**
     * The list has a bulk delivery form dated today, keeping the filters, with checkboxes only on pending rows.
     */
    public function testIndexShowsBulkDeliveryForm(): void
    {
        $body = $this->body($this->controller->index($this->get(['q' => '', 'page' => '1'])));

        $this->assertStringContainsString('action="/purchases/receive?page=1"', $body, 'Empty filters are dropped.');
        $this->assertMatchesRegularExpression(
            '#name="received_date" max="2026-11-10" required\s+value="2026-11-10"#',
            $body
        );
        $this->assertStringContainsString('data-check-all="receive"', $body);
        $this->assertStringContainsString('Mark selected delivered', $body);

        $this->assertStringContainsString('name="ids[]"', $this->rowFor($body, 'Heatsink TO-220'));
        $this->assertStringContainsString('value="7"', $this->rowFor($body, 'Heatsink TO-220'));
        $this->assertStringNotContainsString('name="ids[]"', $this->rowFor($body, 'Racket Grip'));
    }

    /**
     * Without pending purchases on the page there is no bulk delivery form.
     */
    public function testIndexHidesBulkFormWithoutPendingPurchases(): void
    {
        $this->find(Purchase::class, 7)->setStatus(Purchase::STATUS_DELIVERED);

        $body = $this->body($this->controller->index($this->get()));

        $this->assertStringNotContainsString('bulk-receive', $body);
        $this->assertStringNotContainsString('data-check-all', $body);
    }

    /**
     * Selected pending purchases are marked delivered at midday on the chosen date in one flush,
     * delivered ones are skipped, and the redirect keeps the filters and reports the count.
     */
    public function testReceiveSelectedMarksPurchasesDelivered(): void
    {
        $this->add($this->purchase(2, 300, '2026-11-02 08:00:00', 3, '6.00', Purchase::STATUS_PENDING, null), 9);

        $response = $this->controller->receiveSelected(
            $this->post(['ids' => ['7', '9', '8', '7'], 'received_date' => '2026-11-06'])
                ->withQueryParams(['status' => '0', 'q' => 'heat', 'done' => 'x', 'page' => '2'])
        );

        $this->assertRedirect('/purchases?status=0&q=heat&page=2&done=received-selected&count=2', $response);
        $this->assertSame(1, $this->flushes);

        foreach ([7, 9] as $id) {
            $purchase = $this->find(Purchase::class, $id);
            $this->assertSame(Purchase::STATUS_DELIVERED, $purchase->getStatus());
            $this->assertSame('2026-11-06 12:00:00', $purchase->getReceivedDate()?->format('Y-m-d H:i:s'));
            $this->assertNotNull($purchase->getUpdated());
        }

        $this->assertSame('2026-11-03', $this->find(Purchase::class, 8)->getReceivedDate()?->format('Y-m-d'));
    }

    /**
     * Today is an allowed delivery date.
     */
    public function testReceiveSelectedAcceptsToday(): void
    {
        $response = $this->controller->receiveSelected($this->post(['ids' => ['7'], 'received_date' => '2026-11-10']));

        $this->assertRedirect('/purchases?done=received-selected&count=1', $response);
        $this->assertSame('2026-11-10', $this->find(Purchase::class, 7)->getReceivedDate()?->format('Y-m-d'));
    }

    /**
     * Selecting only delivered purchases changes nothing.
     */
    public function testReceiveSelectedSkipsDeliveredPurchases(): void
    {
        $response = $this->controller->receiveSelected($this->post(['ids' => ['8'], 'received_date' => '2026-11-06']));

        $this->assertRedirect('/purchases?done=received-selected&count=0', $response);
        $this->assertNoWrites();
        $this->assertSame('2026-11-03', $this->find(Purchase::class, 8)->getReceivedDate()?->format('Y-m-d'));
    }

    /**
     * Invalid bulk delivery submissions and the error each shows.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidReceiveSelectedProvider(): array
    {
        return [
            'nothing selected' => [['received_date' => '2026-11-06'], 'Select at least one pending purchase.'],
            'malformed IDs only' => [
                ['ids' => ['7a', ''], 'received_date' => '2026-11-06'],
                'Select at least one pending purchase.',
            ],
            'too many' => [
                ['ids' => array_fill(0, 201, '7'), 'received_date' => '2026-11-06'],
                'Select at most 200 purchases at a time.',
            ],
            'missing date' => [['ids' => ['7'], 'received_date' => ''], 'Enter the delivery date.'],
            'invalid date' => [['ids' => ['7'], 'received_date' => '2026-02-30'], 'Enter the delivery date.'],
            'future date' => [
                ['ids' => ['7'], 'received_date' => '2026-11-11'],
                'The delivery date cannot be in the future.',
            ],
            'before purchase' => [
                ['ids' => ['7'], 'received_date' => '2026-10-31'],
                'The delivery date cannot be before the purchase date (01 Nov 2026 – Heatsink TO-220).',
            ],
        ];
    }

    /**
     * Invalid bulk delivery submissions re-render the list with a 422, the error and no writes.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidReceiveSelectedProvider')]
    public function testReceiveSelectedRejectsInvalidInput(array $fields, string $message): void
    {
        $response = $this->controller->receiveSelected($this->post($fields));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($this->escape($message), $this->body($response));
        $this->assertNoWrites();
        $this->assertSame(Purchase::STATUS_PENDING, $this->find(Purchase::class, 7)->getStatus());
    }

    /**
     * After a validation error the form keeps the selection, the date and the filters.
     */
    public function testReceiveSelectedErrorKeepsInput(): void
    {
        $body = $this->body($this->controller->receiveSelected(
            $this->post(['ids' => ['7'], 'received_date' => '2026-11-11'])->withQueryParams(['supplier' => '1'])
        ));

        $this->assertMatchesRegularExpression('#value="7" data-check-group="receive"[^>]*\s+checked>#', $body);
        $this->assertStringContainsString('value="2026-11-11"', $body);
        $this->assertStringContainsString('is-invalid', $body);
        $this->assertStringContainsString('action="/purchases/receive?supplier=1"', $body);
    }

    /**
     * An unknown selected purchase is a 404 with no writes.
     */
    public function testReceiveSelectedUnknownPurchase(): void
    {
        $this->assertNotFound(
            $this->controller->receiveSelected($this->post(['ids' => ['7', '99'], 'received_date' => '2026-11-06']))
        );
        $this->assertNoWrites();
        $this->assertSame(Purchase::STATUS_PENDING, $this->find(Purchase::class, 7)->getStatus());
    }

    /**
     * The bulk delivery notice reports how many purchases were marked delivered.
     *
     * @return array<string, array{string, string}>
     */
    public static function receiveSelectedNoticeProvider(): array
    {
        return [
            'several' => ['3', '3 purchases marked as delivered.'],
            'one' => ['1', '1 purchase marked as delivered.'],
            'none' => ['0', 'The selected purchases were already delivered.'],
            'malformed count' => ['x', 'The selected purchases were already delivered.'],
        ];
    }

    /**
     * The `received-selected` notice shows the count from the redirect.
     */
    #[DataProvider('receiveSelectedNoticeProvider')]
    public function testReceiveSelectedNotice(string $count, string $message): void
    {
        $body = $this->body($this->controller->index($this->get(['done' => 'received-selected', 'count' => $count])));

        $this->assertStringContainsString($message, $body);
    }

        // ---------- delete ----------

    /**
     * Deleting removes the purchase.
     */
    public function testDelete(): void
    {
        $response = $this->controller->delete($this->post([]), ['id' => '7']);

        $this->assertRedirect('/purchases?done=deleted', $response);
        $this->assertSame([$this->find(Purchase::class, 7)], $this->removed);
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Actions on unknown or malformed IDs return 404 and write nothing.
     *
     * @return array<string, array{string, string}>
     */
    public static function notFoundProvider(): array
    {
        return [
            'receive unknown' => ['receive', '99'],
            'delete unknown' => ['delete', '99'],
            'delete malformed' => ['delete', '7a'],
        ];
    }

    /**
     * Unknown purchases are 404 with no writes.
     */
    #[DataProvider('notFoundProvider')]
    public function testNotFound(string $action, string $id): void
    {
        $this->assertNotFound($this->controller->$action($this->post([]), ['id' => $id]));
        $this->assertNoWrites();
    }

    // ---------- helpers ----------

    /**
     * A valid purchase submission (pending, 5 units, before the clock's date).
     *
     * @return array<string, string>
     */
    private function validPurchase(): array
    {
        return [
            'supplier' => '2',
            'product' => '300',
            'purchased_date' => '2026-11-05',
            'quantity' => '5',
            'total_price' => '10.00',
            'status' => '0',
            'received_date' => '',
        ];
    }

    /**
     * Build a purchase.
     */
    private function purchase(
        int $supplierId,
        int $productId,
        string $date,
        int $quantity,
        string $total,
        int $status,
        ?string $received
    ): Purchase {
        return (new Purchase())
            ->setSupplier($this->find(Supplier::class, $supplierId))
            ->setProduct($this->find(Product::class, $productId))
            ->setPurchasedDate(new DateTime($date))
            ->setQuantity($quantity)
            ->setTotalPrice($total)
            ->setStatus($status)
            ->setReceivedDate($received !== null ? new DateTime($received) : null);
    }

    /**
     * Store an entity with a fixed ID in its fake table.
     */
    private function add(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        $this->rows[$entity::class][$id] = $entity;
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
     * Fake repository over the in-memory table for a class.
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
                        $actual = $entity->{'get' . ucfirst($field)}();

                        if (is_array($value) ? !in_array($actual?->getId(), $value, true) : $actual !== $value) {
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
        $repository->method('count')->willReturnCallback(function (array $criteria) use ($class, $matching): int {
            if ($class === Purchase::class) {
                $this->calls['count'] = [$criteria];
            }

            return count($matching($criteria));
        });
        $repository->method('findBy')->willReturnCallback(
            function (
                array $criteria,
                ?array $orderBy = null,
                ?int $limit = null,
                ?int $offset = null
            ) use (
                $class,
                $matching
            ): array {
                if ($class === Purchase::class) {
                    $this->calls['findBy'] = [$criteria, $orderBy, $limit, $offset];
                }

                return $matching($criteria);
            }
        );

        return $repository;
    }

    /**
     * Find the table row containing the given text.
     */
    private function rowFor(string $body, string $text): string
    {
        $at = strpos($body, $text);
        $this->assertNotFalse($at, "Row with $text not found.");
        $start = strrpos(substr($body, 0, $at), '<tr>');
        $end = strpos($body, '</tr>', $at);

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
     * Assert the response is the "Purchase not found." 404 page.
     */
    private function assertNotFound(ResponseInterface $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Purchase not found.', $this->body($response));
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
        return (new ServerRequest('GET', '/purchases'))->withQueryParams($query);
    }

    /**
     * Build a POST request with form fields.
     *
     * @param array<string, mixed> $fields
     */
    private function post(array $fields): ServerRequest
    {
        return (new ServerRequest('POST', '/purchases'))->withParsedBody($fields);
    }

    /**
     * HTML-escape text as the views do.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
