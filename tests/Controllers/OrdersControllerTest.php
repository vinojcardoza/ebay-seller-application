<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Catalog\CategoryPaths;
use App\Controllers\OrdersController;
use App\Ebay\EbayApiException;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\Import\ImportPreview;
use App\Ebay\Import\OrderImportException;
use App\Ebay\Import\PurchaseCostCalculator;
use App\Ebay\Import\RecentOrders;
use App\Ebay\Import\TrackingSynchroniser;
use App\Entities\Category;
use App\Entities\Order;
use App\Entities\OrderItem;
use App\Entities\Product;
use App\Entities\Transaction;
use App\Framework\Template\PlatesRender;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use Tests\Support\FrozenClock;

/**
 * Tests for every OrdersController action, using real views, in-memory fake
 * repositories, a fake DBAL connection for the list, a fake cost calculator
 * and fake eBay services (not connected unless a test says otherwise).
 */
#[CoversClass(OrdersController::class)]
final class OrdersControllerTest extends TestCase
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
     * List queries run: [sql, params] in order (summary first, then rows).
     *
     * @var list<array{string, list<mixed>}>
     */
    private array $queries = [];

    /**
     * Summary row the list's totals query returns.
     *
     * @var array<string, int|string>
     */
    private array $summaryRow = [];

    /**
     * Rows the list query returns.
     *
     * @var list<array<string, mixed>>
     */
    private array $listRows = [];

    /**
     * Item rows (order_id, quantity, item_cost, title, sku) the list's products query returns.
     *
     * @var list<array<string, mixed>>
     */
    private array $productRows = [];

    /**
     * Controller under test (clock: 2026-11-10 12:00).
     */
    private OrdersController $controller;

    /**
     * Recent eBay orders fake (a stub until a test asks for a mock via ebayOrdersMock()).
     */
    private RecentOrders&Stub $ebayOrders;

    /**
     * Tracking synchroniser fake.
     */
    private TrackingSynchroniser&Stub $tracking;

    /**
     * Builds the controller with the current eBay fakes.
     *
     * @var \Closure(): OrdersController
     */
    private \Closure $build;

    /**
     * Seed: category Electronics; products 470 (regulator) and 410 (heatsink);
     * order 5 "12-34567-89012" (eBay purchase, postage 1.55, packaging 0.08) with
     * item 1 (2 × 470, cost 0.45, sold 8.00, fees 1.40, net 6.60) and item 2
     * (0 × 410, refund line: fees 3.27, net −3.27); order 6 "06-old" with
     * transaction 9 and item 3; and list rows for the index.
     */
    protected function setUp(): void
    {
        $this->rows = [];
        $this->persisted = [];
        $this->removed = [];
        $this->flushes = 0;
        $this->queries = [];

        $this->add((new Category())->setTitle('Electronics')->setParentId(0), 1);
        $this->add($this->product('VOLT-L7824', 'Voltage Regulator L7824'), 470);
        $this->add($this->product('TO220-HTSNK', 'Heatsink TO-220'), 410);

        $order = $this->order('12-34567-89012');
        $this->add($order, 5);
        $this->add($this->item($order, 470, 2, '0.45', '8.00', '1.40'), 1);
        $this->add($this->item($order, 410, 0, '0.30', '0.00', '3.27'), 2);

        $old = $this->order('06-old');
        $this->add($old, 6);
        $this->add($this->item($old, 470, 1, '0.45', '4.00', '0.70'), 3);
        $this->add(
            (new Transaction())->setOrder($old)->setTransactionTitle('Payout')->setIncome('3.30')->setExpense('0.00')
                ->setTransactionDatetime(new DateTime('2026-11-02 08:00:00')),
            9
        );

        $this->summaryRow = [
            'orders' => 2,
            'net' => '10.00',
            'cost' => '1.20',
            'postage' => '3.10',
            'packaging' => '0.16',
        ];
        $this->listRows = [
            [
                'id' => 5, 'order_reference' => '12-34567-89012', 'order_date' => '2026-11-09 15:30:00',
                'platform' => 'Ebay', 'order_type' => 'Purchase', 'full_name' => 'Jane Buyer',
                'ebay_user_id' => 'jane_b', 'tracking_code' => 'AB123GB', 'shipping_delivery_datetime' => null,
                'postage_cost' => '1.55', 'packaging_cost' => '0.08', 'items' => 2, 'units' => '2',
                'gross' => '6.60', 'net' => '3.33', 'cost' => '0.90',
            ],
            [
                'id' => 7, 'order_reference' => 'R-1', 'order_date' => '2026-11-08 10:00:00',
                'platform' => 'Ebay', 'order_type' => 'Refund', 'full_name' => null, 'ebay_user_id' => null,
                'tracking_code' => null, 'shipping_delivery_datetime' => '2026-11-09 12:00:00',
                'postage_cost' => '0.00', 'packaging_cost' => '0.00', 'items' => 0, 'units' => '0',
                'gross' => '0.00', 'net' => '-3.27', 'cost' => '0.00',
            ],
        ];

        $this->productRows = [
            [
                'order_id' => 5, 'quantity' => 2, 'item_cost' => '0.45',
                'title' => 'Voltage Regulator L7824', 'sku' => 'VOLT-L7824',
            ],
            [
                'order_id' => 5, 'quantity' => 0, 'item_cost' => null,
                'title' => 'Heatsink TO-220', 'sku' => 'TO220-HTSNK',
            ],
        ];

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params = []): array {
            $this->queries[] = [$sql, $params];

            return $this->summaryRow;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params = []): array {
                $this->queries[] = [$sql, $params];

                return str_contains($sql, 'FROM order_items oi') ? $this->productRows : $this->listRows;
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => $this->repository($class)
        );
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            $ids = array_keys($this->rows[$entity::class] ?? []);
            $this->add($entity, ($ids === [] ? 0 : max($ids)) + 1);
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });

        $costs = $this->createStub(PurchaseCostCalculator::class);
        $costs->method('unitCost')->willReturn('0.45');

        $this->ebayOrders = $this->createStub(RecentOrders::class);
        $this->tracking = $this->createStub(TrackingSynchroniser::class);

        $factory = new Psr17Factory();
        $this->build = fn (): OrdersController => new OrdersController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            new CategoryPaths($em),
            $costs,
            new FrozenClock('2026-11-10 12:00:00'),
            $this->ebayOrders,
            $this->tracking,
        );
        $this->controller = ($this->build)();
    }

    // ---------- index ----------

    /**
     * The list shows totals and each order's reference, buyer, type, units, gross, net, postage, packaging,
     * profit and tracking.
     */
    public function testIndexListsOrders(): void
    {
        $body = $this->body($this->controller->index($this->get()));

        // Profit = 10.00 − 1.20 − (3.10 + 0.16).
        $this->assertKpi($body, '2', 'Orders');
        $this->assertKpi($body, '£10.00', 'Net sales');
        $this->assertKpi($body, '£4.46', 'Item costs + shipping');
        $this->assertKpi($body, '£5.54', 'Estimated profit');

        $first = $this->rowFor($body, '/orders/5"');
        $this->assertStringContainsString('09 Nov 2026 15:30', $first);
        $this->assertStringContainsString('12-34567-89012', $first);
        $this->assertStringContainsString('Jane Buyer', $first);
        $this->assertStringContainsString('href="/orders?q=jane_b"', $first);
        $this->assertStringContainsString('tint-success">Purchase', $first);
        $this->assertStringContainsString('<th class="text-end">Gross</th>', $body);
        $this->assertStringContainsString('£6.60', $first, 'Gross: sold price before fees.');
        $this->assertStringContainsString('£3.33', $first);
        $this->assertStringContainsString('<th class="text-end">Postage</th>', $body);
        $this->assertStringContainsString('<th class="text-end">Packaging</th>', $body);
        $this->assertMatchesRegularExpression(
            '#£3\.33</td>\s*<td[^>]*>£1\.55</td>\s*<td[^>]*>£0\.08</td>\s*<td[^>]*>£0\.80</td>#',
            $first,
            'Net, postage, packaging, then profit: 3.33 − 0.90 − 1.55 − 0.08.'
        );
        $this->assertStringContainsString('AB123GB', $first);

        $refund = $this->rowFor($body, '/orders/7"');
        $this->assertStringContainsString('tint-danger">Refund', $refund);
        $this->assertStringContainsString('−£3.27', $refund);
        $this->assertStringContainsString('£0.00', $refund);
        $this->assertStringContainsString('tint-warning">None', $refund);
        $this->assertStringContainsString('tint-success">Delivered', $refund);
        $this->assertStringContainsString('<span class="text-body-secondary">—</span>', $refund);
    }

    /**
     * Each listed order shows its products with quantities and unit costs, fetched in one query for the page's orders.
     */
    public function testIndexListsProductsAndQuantities(): void
    {
        $body = $this->body($this->controller->index($this->get()));

        [$sql, $params] = $this->queries[2];
        $this->assertStringContainsString('WHERE oi.order_id IN (?, ?)', $sql);
        $this->assertSame([5, 7], $params);

        $first = $this->rowFor($body, '/orders/5"');
        $this->assertMatchesRegularExpression('/2 ×<\/span>\s*Voltage Regulator L7824/', $first);
        $this->assertMatchesRegularExpression('/0 ×<\/span>\s*Heatsink TO-220/', $first);
        $this->assertStringContainsString('title="VOLT-L7824"', $first);
        $this->assertStringContainsString('@ £0.45', $first);
        $this->assertStringContainsString('@ £0.00', $first, 'A missing item cost shows as zero.');
    }

    /**
     * With no orders on the page the products query is skipped.
     */
    public function testIndexSkipsProductsQueryWithoutOrders(): void
    {
        $this->listRows = [];
        $this->summaryRow = ['orders' => 0];

        $this->controller->index($this->get());

        $this->assertCount(2, $this->queries);
    }

    /**
     * Without filters the list has no WHERE clause and shows the first page, newest first.
     */
    public function testIndexQueriesWithoutFilters(): void
    {
        $this->controller->index($this->get());

        [$summarySql, $summaryParams] = $this->queries[0];
        [$listSql] = $this->queries[1];
        $this->assertStringNotContainsString('WHERE', $summarySql);
        $this->assertSame([], $summaryParams);
        $this->assertStringContainsString('ORDER BY o.order_date DESC, o.id DESC LIMIT 50 OFFSET 0', $listSql);
    }

    /**
     * Filters, the SQL condition each adds and its parameters.
     *
     * @return array<string, array{array<string, string>, string, list<string>}>
     */
    public static function filterProvider(): array
    {
        return [
            'search' => [
                ['q' => '50%_off'],
                '(o.order_reference LIKE ? OR o.full_name LIKE ? OR o.ebay_user_id LIKE ? OR o.email LIKE ?'
                . ' OR o.postcode LIKE ? OR o.tracking_code LIKE ?)',
                array_fill(0, 6, '%50\%\_off%'),
            ],
            'platform' => [['platform' => 'Etsy'], 'o.platform = ?', ['Etsy']],
            'type' => [['type' => 'Refund'], 'o.order_type = ?', ['Refund']],
            'from' => [['from' => '2026-09-01'], 'o.order_date >= ?', ['2026-09-01 00:00:00']],
            'to (inclusive)' => [['to' => '2026-09-30'], 'o.order_date < ?', ['2026-10-01 00:00:00']],
            'no tracking' => [['tracking' => 'none'], "(o.tracking_code IS NULL OR o.tracking_code = '')", []],
            'has tracking' => [
                ['tracking' => 'tracked'],
                "(o.tracking_code IS NOT NULL AND o.tracking_code <> '')",
                [],
            ],
            'delivered' => [['tracking' => 'delivered'], 'o.shipping_delivery_datetime IS NOT NULL', []],
        ];
    }

    /**
     * Each filter adds its condition to both queries, with bound parameters.
     *
     * @param array<string, string> $query
     * @param list<string> $params
     */
    #[DataProvider('filterProvider')]
    public function testIndexFilters(array $query, string $condition, array $params): void
    {
        $body = $this->body($this->controller->index($this->get($query)));

        foreach (array_slice($this->queries, 0, 2) as [$sql, $bound]) {
            $this->assertStringContainsString('WHERE ' . $condition, $sql);
            $this->assertSame($params, $bound);
        }

        $this->assertStringContainsString('href="/orders">Clear', $body);
    }

    /**
     * Invalid filter values are ignored.
     */
    public function testIndexIgnoresInvalidFilters(): void
    {
        $this->controller->index($this->get([
            'platform' => 'Amazon',
            'type' => 'Sale',
            'from' => '2026-02-30',
            'to' => 'yesterday',
            'tracking' => 'lost',
        ]));

        $this->assertStringNotContainsString('WHERE', $this->queries[0][0]);
    }

    /**
     * Pages are 50 orders; out-of-range pages are clamped and links keep the filters.
     */
    public function testIndexPagination(): void
    {
        $this->summaryRow['orders'] = 120;

        $body = $this->body($this->controller->index($this->get(['type' => 'Purchase', 'page' => '99'])));

        $this->assertStringContainsString('LIMIT 50 OFFSET 100', $this->queries[1][0]);
        $this->assertStringContainsString('Page 3 of 3', $body);
        $this->assertStringContainsString('href="/orders?type=Purchase&amp;page=2"', $body);
    }

    /**
     * Empty states distinguish "no matches" from "no orders".
     */
    public function testIndexEmptyStates(): void
    {
        $this->listRows = [];
        $this->summaryRow = ['orders' => 0];

        $this->assertStringContainsString('No orders yet.', $this->body($this->controller->index($this->get())));
        $this->assertStringContainsString(
            'No orders match these filters.',
            $this->body($this->controller->index($this->get(['q' => 'nobody'])))
        );
    }

    // ---------- show ----------

    /**
     * The order page shows buyer, items, totals and profit.
     */
    public function testShowDisplaysOrder(): void
    {
        $response = $this->controller->show($this->get(['done' => 'item-added']), ['id' => '5']);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Order 12-34567-89012</h1>', $body);
        $this->assertStringContainsString('Item added.', $body);
        $this->assertStringContainsString('Jane Buyer', $body);
        $this->assertStringContainsString('Leeds<br>LS1 1AA<br>United Kingdom', $body);
        $this->assertStringContainsString('Royal Mail 48', $body, 'Shipping service code shown by name.');

        // Net 6.60 − 3.27; cost 2 × 0.45; profit 3.33 − 0.90 − 1.63.
        $this->assertKpi($body, '£3.33', 'Net (after fees)');
        $this->assertKpi($body, '£0.90', 'Item costs');
        $this->assertKpi($body, '£1.63', 'Postage + packaging');
        $this->assertKpi($body, '£0.80', 'Profit');

        $this->assertStringContainsString('<a href="/product/470">Voltage Regulator L7824</a>', $body);
        $this->assertStringContainsString('−£3.27', $body);
        $this->assertMatchesRegularExpression('#2 lines,\s*2 units#', $body);
        $this->assertStringContainsString('href="/orders/5/items/1/edit"', $body);
        $this->assertStringContainsString('action="/orders/5/items/2/delete"', $body);
        $this->assertStringContainsString('action="/orders/5/items"', $body);
        $this->assertStringContainsString('<optgroup label="Electronics">', $body);
        $this->assertStringNotContainsString('<h2>Transactions</h2>', $body);
    }

    /**
     * Linked transactions are listed.
     */
    public function testShowListsTransactions(): void
    {
        $body = $this->body($this->controller->show($this->get(), ['id' => '6']));

        $this->assertStringContainsString('<h2>Transactions</h2>', $body);
        $this->assertStringContainsString('Payout', $body);
        $this->assertStringContainsString('£3.30', $body);
    }

    /**
     * Unknown or malformed IDs return 404.
     */
    public function testShowNotFound(): void
    {
        $this->assertNotFound($this->controller->show($this->get(), ['id' => '999']), 'Order not found.');
        $this->assertNotFound($this->controller->show($this->get(), ['id' => '5x']), 'Order not found.');
    }

    // ---------- create & store ----------

    /**
     * The form starts as an eBay purchase dated now with the legacy default shipping.
     */
    public function testCreateForm(): void
    {
        $body = $this->body($this->controller->create());

        $this->assertStringContainsString('value="2026-11-10T12:00"', $body);
        $this->assertMatchesRegularExpression('#<option value="Ebay" selected>#', $body);
        $this->assertMatchesRegularExpression('#<option value="Purchase" selected>#', $body);
        $this->assertMatchesRegularExpression('#<option value="CRL48" selected>Royal Mail 48#', $body);
        $this->assertStringContainsString('value="1.55"', $body);
        $this->assertStringContainsString('value="GB"', $body);
    }

    /**
     * A valid order is saved with normalised values and blank fields as NULL.
     */
    public function testStoreCreatesOrder(): void
    {
        $response = $this->controller->store($this->post([
            'order_reference' => ' 12-99999-00001 ',
            'postage_cost' => '2',
            'postcode' => 'ls1 1aa',
            'country' => 'gb',
            'tracking_code' => '',
            'shipping_datetime' => '2026-11-10T09:00',
        ] + $this->validOrder()));

        $order = $this->persisted[0];
        $this->assertInstanceOf(Order::class, $order);
        $this->assertRedirect('/orders/' . $order->getId() . '?done=created', $response);
        $this->assertSame('12-99999-00001', $order->getOrderReference());
        $this->assertSame('2026-11-09 15:30', $order->getOrderDate()->format('Y-m-d H:i'));
        $this->assertSame('2.00', $order->getPostageCost());
        $this->assertSame('LS1 1AA', $order->getPostcode());
        $this->assertSame('GB', $order->getCountry());
        $this->assertNull($order->getTrackingCode());
        $this->assertSame('2026-11-10 09:00', $order->getShippingDatetime()?->format('Y-m-d H:i'));
        $this->assertNull($order->getShippingDeliveryDatetime());
        $this->assertSame(1, $order->getStatus());
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Invalid order input and the expected message.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidOrderProvider(): array
    {
        return [
            'unknown platform' => [['platform' => 'Amazon'], 'Choose a platform.'],
            'unknown type' => [['order_type' => 'Sale'], 'Choose an order type.'],
            'no reference' => [['order_reference' => ''], 'Order reference is required.'],
            'reference too long' => [['order_reference' => str_repeat('R', 192)], 'Must be 191 characters or fewer.'],
            'no date' => [['order_date' => ''], 'Enter the order date and time.'],
            'bad date' => [['order_date' => '2026-13-01T10:00'], 'Enter the order date and time.'],
            'future date' => [['order_date' => '2026-11-10T12:01'], 'The order date cannot be in the future.'],
            'bad postage' => [['postage_cost' => '1,55'], 'Postage cost must be an amount like 1.55.'],
            'negative packaging' => [['packaging_cost' => '-0.08'], 'Packaging cost must be an amount like 1.55.'],
            'shipped before ordered' => [
                ['shipping_datetime' => '2026-11-09T15:00'],
                'The shipped date cannot be before the order date.',
            ],
            'delivered before shipped' => [
                ['shipping_datetime' => '2026-11-10T09:00', 'shipping_delivery_datetime' => '2026-11-10T08:00'],
                'The delivery date cannot be before the shipped date.',
            ],
            'bad delivery date' => [['shipping_delivery_datetime' => 'soon'], 'Enter a valid delivery date and time.'],
            'bad email' => [['email' => 'not-an-email'], 'Enter a valid email address.'],
            'bad country' => [['country' => 'GBR'], 'Use a two-letter country code, e.g. GB.'],
        ];
    }

    /**
     * Invalid input redisplays the form with a 422 and saves nothing.
     *
     * @param array<string, string> $override Fields changed from a valid order.
     */
    #[DataProvider('invalidOrderProvider')]
    public function testStoreRejectsInvalidInput(array $override, string $message): void
    {
        $response = $this->controller->store($this->post($override + $this->validOrder()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, $this->body($response));
        $this->assertNoWrites();
    }

    // ---------- edit, update & delete ----------

    /**
     * The edit form is prefilled, including datetime-local values.
     */
    public function testEditForm(): void
    {
        $body = $this->body($this->controller->edit($this->get(), ['id' => '5']));

        $this->assertStringContainsString('action="/orders/5"', $body);
        $this->assertStringContainsString('value="12-34567-89012"', $body);
        $this->assertStringContainsString('value="2026-11-09T15:30"', $body);
        $this->assertStringContainsString('value="LS1 1AA"', $body);
        $this->assertNotFound($this->controller->edit($this->get(), ['id' => '999']), 'Order not found.');
    }

    /**
     * Saving changes updates the order and stamps `updated`.
     */
    public function testUpdateSavesChanges(): void
    {
        $response = $this->controller->update(
            $this->post(['tracking_code' => 'AB123GB', 'order_type' => 'Replacement'] + $this->validOrder()),
            ['id' => '5']
        );

        $order = $this->find(Order::class, 5);
        $this->assertRedirect('/orders/5?done=updated', $response);
        $this->assertSame('AB123GB', $order->getTrackingCode());
        $this->assertSame('Replacement', $order->getOrderType());
        $this->assertInstanceOf(DateTimeInterface::class, $order->getUpdated());
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Invalid updates change nothing; unknown IDs are 404.
     */
    public function testUpdateRejections(): void
    {
        $response = $this->controller->update(
            $this->post(['order_reference' => ''] + $this->validOrder()),
            ['id' => '5']
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('12-34567-89012', $this->find(Order::class, 5)->getOrderReference());
        $this->assertNotFound(
            $this->controller->update($this->post($this->validOrder()), ['id' => '999']),
            'Order not found.'
        );
        $this->assertNoWrites();
    }

    /**
     * Deleting removes the order's items and the order.
     */
    public function testDeleteRemovesOrderAndItems(): void
    {
        $response = $this->controller->delete($this->post([]), ['id' => '5']);

        $this->assertRedirect('/orders?done=deleted', $response);
        $this->assertSame(
            [$this->find(OrderItem::class, 1), $this->find(OrderItem::class, 2), $this->find(Order::class, 5)],
            $this->removed
        );
        $this->assertSame(1, $this->flushes);
    }

    /**
     * An order with linked transactions is not deleted.
     */
    public function testDeleteRefusedWithTransactions(): void
    {
        $response = $this->controller->delete($this->post([]), ['id' => '6']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString(
            'This order has 1 linked transaction, so it cannot be deleted.',
            $this->body($response)
        );
        $this->assertNoWrites();
    }

    // ---------- items ----------

    /**
     * A blank item cost uses the FIFO purchase cost; the total is sold price minus fees.
     */
    public function testAddItemUsesPurchaseCostWhenBlank(): void
    {
        $response = $this->controller->addItem(
            $this->post([
                'product' => '410',
                'quantity' => '3',
                'item_cost' => '',
                'sold_price' => '9',
                'fees_deductions' => '1.5',
            ]),
            ['id' => '5']
        );

        $item = $this->persisted[0];
        $this->assertInstanceOf(OrderItem::class, $item);
        $this->assertRedirect('/orders/5?done=item-added', $response);
        $this->assertSame($this->find(Order::class, 5), $item->getOrder());
        $this->assertSame($this->find(Product::class, 410), $item->getProduct());
        $this->assertSame(3, $item->getQuantity());
        $this->assertSame('0.45', $item->getItemCost());
        $this->assertSame('9.00', $item->getSoldPrice());
        $this->assertSame('1.50', $item->getFeesDeductions());
        $this->assertSame('7.50', $item->getTotalSoldPrice());
    }

    /**
     * Refund lines can have zero quantity and negative totals; an explicit cost is kept.
     */
    public function testAddRefundItem(): void
    {
        $this->controller->addItem(
            $this->post([
                'product' => '470',
                'quantity' => '0',
                'item_cost' => '0.30',
                'sold_price' => '-1',
                'fees_deductions' => '2.27',
            ]),
            ['id' => '5']
        );

        $item = $this->persisted[0];
        $this->assertInstanceOf(OrderItem::class, $item);
        $this->assertSame(0, $item->getQuantity());
        $this->assertSame('0.30', $item->getItemCost());
        $this->assertSame('-1.00', $item->getSoldPrice());
        $this->assertSame('-3.27', $item->getTotalSoldPrice());
    }

    /**
     * Invalid item input and the expected message.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidItemProvider(): array
    {
        return [
            'no product' => [['product' => ''], 'Choose a product.'],
            'unknown product' => [['product' => '1'], 'Choose a product.'],
            'negative quantity' => [['quantity' => '-1'], 'Quantity must be a whole number'],
            'fractional quantity' => [['quantity' => '1.5'], 'Quantity must be a whole number'],
            'negative cost' => [['item_cost' => '-0.45'], 'Item cost must be an amount like 0.42'],
            'bad sold price' => [['sold_price' => '£4'], 'Sold price must be an amount like 4.99'],
            'missing fees' => [['fees_deductions' => ''], 'Fees must be an amount like 4.99'],
        ];
    }

    /**
     * Invalid items redisplay the order with a 422 and save nothing.
     *
     * @param array<string, string> $override Fields changed from a valid item.
     */
    #[DataProvider('invalidItemProvider')]
    public function testAddItemRejectsInvalidInput(array $override, string $message): void
    {
        $response = $this->controller->addItem($this->post($override + $this->validItem()), ['id' => '5']);
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, $body);
        $this->assertStringContainsString('<h1>Order 12-34567-89012</h1>', $body);
        $this->assertNoWrites();
    }

    /**
     * The add-item picker leaves out inactive products.
     */
    public function testShowHidesInactiveProducts(): void
    {
        $this->find(Product::class, 410)->setStatus(Product::STATUS_INACTIVE);

        $body = $this->body($this->controller->show($this->get(), ['id' => '5']));

        $this->assertStringNotContainsString('<option value="410"', $body);
        $this->assertStringContainsString('<option value="470"', $body);
    }

    /**
     * The item edit form keeps the item's product selected even when it is inactive.
     */
    public function testEditItemFormKeepsInactiveSelectedProduct(): void
    {
        $this->find(Product::class, 470)->setStatus(Product::STATUS_INACTIVE);
        $this->find(Product::class, 410)->setStatus(Product::STATUS_INACTIVE);

        $body = $this->body($this->controller->editItem($this->get(), ['id' => '5', 'itemId' => '1']));

        $this->assertMatchesRegularExpression('#<option value="470" selected>#', $body);
        $this->assertStringNotContainsString('<option value="410"', $body);
    }

    /**
     * The item edit form is prefilled.
     */
    public function testEditItemForm(): void
    {
        $body = $this->body($this->controller->editItem($this->get(), ['id' => '5', 'itemId' => '1']));

        $this->assertStringContainsString('action="/orders/5/items/1"', $body);
        $this->assertMatchesRegularExpression('#<option value="470" selected>#', $body);
        $this->assertStringContainsString('value="8.00"', $body);
        $this->assertStringContainsString('value="1.40"', $body);
    }

    /**
     * Saving an item recalculates its total and stamps `updated`.
     */
    public function testUpdateItem(): void
    {
        $response = $this->controller->updateItem(
            $this->post(['quantity' => '1', 'sold_price' => '4.00', 'fees_deductions' => '0.70'] + $this->validItem()),
            ['id' => '5', 'itemId' => '1']
        );

        $item = $this->find(OrderItem::class, 1);
        $this->assertRedirect('/orders/5?done=item-updated', $response);
        $this->assertSame(1, $item->getQuantity());
        $this->assertSame('3.30', $item->getTotalSoldPrice());
        $this->assertInstanceOf(DateTimeInterface::class, $item->getUpdated());
        $this->assertSame(1, $this->flushes);
    }

    /**
     * Removing an item deletes it.
     */
    public function testRemoveItem(): void
    {
        $response = $this->controller->removeItem($this->post([]), ['id' => '5', 'itemId' => '2']);

        $this->assertRedirect('/orders/5?done=item-removed', $response);
        $this->assertSame([$this->find(OrderItem::class, 2)], $this->removed);
    }

    /**
     * Item routes that must 404: unknown items, or items reached through another order.
     *
     * @return array<string, array{string, array<string, string>}>
     */
    public static function foreignItemProvider(): array
    {
        return [
            'edit unknown item' => ['editItem', ['id' => '5', 'itemId' => '99']],
            'edit item of another order' => ['editItem', ['id' => '5', 'itemId' => '3']],
            'update item of another order' => ['updateItem', ['id' => '5', 'itemId' => '3']],
            'remove item of another order' => ['removeItem', ['id' => '5', 'itemId' => '3']],
            'remove under unknown order' => ['removeItem', ['id' => '999', 'itemId' => '1']],
            'add to unknown order' => ['addItem', ['id' => '999']],
        ];
    }

    /**
     * Items can only be reached through their own order; anything else is 404 with no writes.
     *
     * @param array<string, string> $args
     */
    #[DataProvider('foreignItemProvider')]
    public function testItemRoutesRequireMatchingOrder(string $action, array $args): void
    {
        $response = $this->controller->$action($this->post($this->validItem()), $args);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNoWrites();
    }

    // ---------- eBay orders & tracking ----------

    /**
     * Not connected: the eBay section links to the eBay page, Sync tracking is disabled and eBay is not called.
     */
    public function testIndexWhenEbayNotConnected(): void
    {
        $mock = $this->ebayOrdersMock();
        $mock->method('isConnected')->willReturn(false);
        $mock->expects($this->never())->method('notImported');

        $body = $this->body($this->controller->index($this->get()));

        $this->assertStringContainsString('<a href="/ebay">Connect your eBay account</a>', $body);
        $this->assertMatchesRegularExpression(
            '#action="/orders/sync-tracking"[^>]*>\s*<button[^>]*disabled>#',
            $body
        );
    }

    /**
     * Connected: recent non-imported eBay orders are listed above the orders list, with import forms.
     */
    public function testIndexListsEbayOrdersAboveOrders(): void
    {
        $mock = $this->ebayOrdersMock();
        $mock->method('isConnected')->willReturn(true);
        $mock->expects($this->once())->method('notImported')->with(7)->willReturn([
            $this->preview('12-new'),
            $this->preview('12-bad', null),
        ]);

        $response = $this->controller->index($this->get(['days' => '7', 'platform' => 'Ebay']));
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertLessThan(
            strpos($body, '/orders/5"'),
            strpos($body, 'id="ebay-orders"'),
            'eBay orders come before the orders list.'
        );
        $this->assertMatchesRegularExpression('#<option value="7"\s+selected>#', $body);
        $this->assertStringContainsString('action="/orders/ebay/12-new"', $body);
        $this->assertStringNotContainsString('action="/orders/ebay/12-bad"', $body);
        $this->assertStringContainsString('Unknown SKU: LCD-1602.', $body);
        $this->assertStringContainsString('£6.99', $body);
        $this->assertStringContainsString('<input type="hidden" name="platform" value="Ebay">', $body);
        $this->assertStringContainsString(
            '<input type="hidden" name="days" value="7">',
            substr($body, (int) strpos($body, '<form method="get" action="/orders" ')),
            'The filter form keeps the eBay period.'
        );
        $this->assertMatchesRegularExpression(
            '#action="/orders/sync-tracking"[^>]*>\s*<button type="submit" class="btn btn-light-primary" >#',
            $body
        );
    }

    /**
     * With every recent eBay order imported, the section says so.
     */
    public function testIndexWhenAllEbayOrdersImported(): void
    {
        $this->ebayOrders->method('isConnected')->willReturn(true);
        $this->ebayOrders->method('notImported')->willReturn([]);

        $body = $this->body($this->controller->index($this->get()));

        $this->assertStringContainsString('Every eBay order from the last 3 days is imported.', $body);
    }

    /**
     * An eBay error while loading orders is shown in the section without failing the page.
     */
    public function testIndexShowsEbayError(): void
    {
        $this->ebayOrders->method('isConnected')->willReturn(true);
        $this->ebayOrders->method('notImported')
            ->willThrowException(new EbayApiException('eBay request GET /order failed (HTTP 500).', 500));

        $response = $this->controller->index($this->get());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('eBay request GET /order failed (HTTP 500).', $this->body($response));
    }

    /**
     * Tokens that turn out to be unusable while loading are shown as not connected.
     */
    public function testIndexWhenEbayTokensRejected(): void
    {
        $this->ebayOrders->method('isConnected')->willReturn(true);
        $this->ebayOrders->method('notImported')->willThrowException(new EbayNotConnectedException('expired'));

        $this->assertStringContainsString(
            'Connect your eBay account</a>',
            $this->body($this->controller->index($this->get()))
        );
    }

    /**
     * Query codes and the message shown for them.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function ebayMessageProvider(): array
    {
        return [
            'imported' => [['done' => 'imported', 'ref' => '12-34567-89012'], 'eBay order 12-34567-89012 imported.'],
            'imported with unsafe ref' => [['done' => 'imported', 'ref' => '<script>'], 'eBay order imported.'],
            'tracking' => [
                ['done' => 'tracking', 'checked' => '5', 'updated' => '3', 'delivered' => '2', 'failed' => '1'],
                'Checked 5 eBay order(s): 3 updated, 2 delivered, 1 could not be looked up.',
            ],
            'tracking without delivery status' => [
                ['done' => 'tracking', 'checked' => '5', 'updated' => '1', 'failed' => '0', 'nodelivery' => '1'],
                'Checked 5 eBay order(s): 1 updated, 0 delivered, 0 could not be looked up.'
                . ' Delivery status could not be fetched from eBay.',
            ],
            'not connected' => [['error' => 'not-connected'], 'Connect your eBay account on the eBay page first.'],
        ];
    }

    /**
     * eBay redirect codes show fixed messages built only from known values.
     *
     * @param array<string, string> $query
     */
    #[DataProvider('ebayMessageProvider')]
    public function testIndexShowsEbayMessages(array $query, string $message): void
    {
        $body = $this->body($this->controller->index($this->get($query)));

        $this->assertStringContainsString($message, $body);
        $this->assertStringNotContainsString('script&gt;', $body);
    }

    /**
     * Importing an eBay order passes the promoted flag and redirects back with the reference and period.
     */
    public function testImportEbayOrder(): void
    {
        $this->ebayOrdersMock()->expects($this->once())->method('import')->with('12-34567-89012', true)
            ->willReturn((new Order())->setOrderReference('12-34567-89012'));

        $response = $this->controller->importEbayOrder(
            $this->post(['promoted' => '1', 'days' => '14']),
            ['orderId' => '12-34567-89012']
        );

        $this->assertRedirect('/orders?done=imported&ref=12-34567-89012&days=14', $response);
    }

    /**
     * Without the promoted box ticked the import is not promoted, and an unknown period becomes the default.
     */
    public function testImportEbayOrderNotPromoted(): void
    {
        $this->ebayOrdersMock()->expects($this->once())->method('import')->with('12-1', false)
            ->willReturn((new Order())->setOrderReference('12-1'));

        $response = $this->controller->importEbayOrder($this->post(['days' => '365']), ['orderId' => '12-1']);

        $this->assertRedirect('/orders?done=imported&ref=12-1&days=3', $response);
    }

    /**
     * Import failures, the status of the page shown and the message on it.
     *
     * @return array<string, array{\Throwable, int, string}>
     */
    public static function importFailureProvider(): array
    {
        return [
            'cannot be imported' => [
                new OrderImportException('eBay order 12-1 cannot be imported: Unknown SKU: X.'),
                422,
                'eBay order 12-1 cannot be imported: Unknown SKU: X.',
            ],
            'eBay error' => [new EbayApiException('Order 12-1 was not found.', 404), 502, 'Order 12-1 was not found.'],
        ];
    }

    /**
     * A failed import redisplays the orders page with the reason and the chosen period.
     */
    #[DataProvider('importFailureProvider')]
    public function testImportEbayOrderFails(\Throwable $exception, int $status, string $message): void
    {
        $this->ebayOrders->method('import')->willThrowException($exception);

        $response = $this->controller->importEbayOrder($this->post(['days' => '14']), ['orderId' => '12-1']);
        $body = $this->body($response);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertStringContainsString($message, $body);
        $this->assertMatchesRegularExpression('#<option value="14"\s+selected>#', $body);
    }

    /**
     * When eBay is not connected, importing asks the user to connect.
     */
    public function testImportEbayOrderNotConnected(): void
    {
        $this->ebayOrders->method('import')->willThrowException(new EbayNotConnectedException('x'));

        $this->assertRedirect(
            '/orders?error=not-connected',
            $this->controller->importEbayOrder($this->post([]), ['orderId' => '12-1'])
        );
    }

    /**
     * Tracking sync redirects with the counts, including orders now delivered.
     */
    public function testSyncTracking(): void
    {
        $this->tracking->method('sync')->willReturn(
            ['checked' => 4, 'updated' => 2, 'delivered' => 1, 'failed' => ['06-1'], 'deliveryError' => null]
        );

        $this->assertRedirect(
            '/orders?done=tracking&checked=4&updated=2&delivered=1&failed=1',
            $this->controller->syncTracking($this->post([]))
        );
    }

    /**
     * Tracking sync flags a failed delivery lookup in the redirect.
     */
    public function testSyncTrackingDeliveryLookupFailed(): void
    {
        $this->tracking->method('sync')->willReturn(
            ['checked' => 4, 'updated' => 2, 'delivered' => 0, 'failed' => [], 'deliveryError' => 'eBay is down']
        );

        $this->assertRedirect(
            '/orders?done=tracking&checked=4&updated=2&delivered=0&failed=0&nodelivery=1',
            $this->controller->syncTracking($this->post([]))
        );
    }

    /**
     * Tracking sync asks to connect when eBay is not connected.
     */
    public function testSyncTrackingNotConnected(): void
    {
        $this->tracking->method('sync')->willThrowException(new EbayNotConnectedException('x'));

        $this->assertRedirect('/orders?error=not-connected', $this->controller->syncTracking($this->post([])));
    }

    /**
     * Tracking sync shows eBay errors on the orders page with a 502.
     */
    public function testSyncTrackingApiError(): void
    {
        $this->tracking->method('sync')->willThrowException(new EbayApiException('eBay is down', 503));

        $response = $this->controller->syncTracking($this->post([]));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('eBay is down', $this->body($response));
    }

    // ---------- helpers ----------

    /**
     * Replace the recent eBay orders stub with a mock (rebuilding the controller) so the test can set expectations.
     */
    private function ebayOrdersMock(): RecentOrders&MockObject
    {
        if ($this->ebayOrders instanceof MockObject) {
            return $this->ebayOrders;
        }

        $mock = $this->createMock(RecentOrders::class);
        $this->ebayOrders = $mock;
        $this->controller = ($this->build)();

        return $mock;
    }

    /**
     * Build an eBay order preview with one LCD-1602 line (matched to a product unless `$match` is null).
     */
    private function preview(string $orderId, ?string $match = ImportPreview::MATCH_PRODUCT): ImportPreview
    {
        return new ImportPreview(
            $orderId,
            new DateTimeImmutable('2026-11-09 10:00:00 UTC'),
            'buyer_1',
            '6.99',
            'GBP',
            [['sku' => 'LCD-1602', 'title' => 'LCD Display', 'quantity' => 1, 'match' => $match]],
            false,
            false,
        );
    }

    /**
     * A valid order submission (dated before the clock).
     *
     * @return array<string, string>
     */
    private function validOrder(): array
    {
        return [
            'platform' => 'Ebay',
            'order_type' => 'Purchase',
            'order_reference' => '12-34567-89012',
            'order_date' => '2026-11-09T15:30',
            'shipping_service' => 'CRL48',
            'postage_cost' => '1.55',
            'packaging_cost' => '0.08',
            'shipping_company' => '',
            'tracking_code' => '',
            'shipping_datetime' => '',
            'shipping_delivery_datetime' => '',
            'ebay_user_id' => 'jane_b',
            'full_name' => 'Jane Buyer',
            'email' => 'jane@example.com',
            'phone' => '',
            'street1' => '1 High St',
            'street2' => '',
            'city' => 'Leeds',
            'state' => '',
            'postcode' => 'LS1 1AA',
            'country' => 'GB',
            'country_name' => 'United Kingdom',
            'additional_notes' => '',
        ];
    }

    /**
     * A valid item submission.
     *
     * @return array<string, string>
     */
    private function validItem(): array
    {
        return [
            'product' => '470',
            'quantity' => '1',
            'item_cost' => '0.45',
            'sold_price' => '4.99',
            'fees_deductions' => '0.88',
            'additional_notes' => '',
        ];
    }

    /**
     * Build a product in the Electronics category.
     */
    private function product(string $sku, string $title): Product
    {
        return (new Product())->setSku($sku)->setTitle($title)->setCategory($this->find(Category::class, 1));
    }

    /**
     * Build an eBay purchase to Jane Buyer in Leeds.
     */
    private function order(string $reference): Order
    {
        return (new Order())
            ->setPlatform('Ebay')
            ->setOrderType('Purchase')
            ->setOrderReference($reference)
            ->setOrderDate(new DateTime('2026-11-09 15:30:00'))
            ->setShippingService('CRL48')
            ->setPostageCost('1.55')
            ->setPackagingCost('0.08')
            ->setFullName('Jane Buyer')
            ->setEbayUserId('jane_b')
            ->setStreet1('1 High St')
            ->setCity('Leeds')
            ->setPostcode('LS1 1AA')
            ->setCountry('GB')
            ->setCountryName('United Kingdom')
            ->setStatus(1);
    }

    /**
     * Build an order item; the total is sold price minus fees.
     */
    private function item(
        Order $order,
        int $productId,
        int $quantity,
        string $cost,
        string $sold,
        string $fees
    ): OrderItem {
        return (new OrderItem())
            ->setOrder($order)
            ->setProduct($this->find(Product::class, $productId))
            ->setQuantity($quantity)
            ->setItemCost($cost)
            ->setSoldPrice($sold)
            ->setFeesDeductions($fees)
            ->setTotalSoldPrice(number_format((float) $sold - (float) $fees, 2, '.', ''));
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
        $matching = fn (array $criteria): array => array_values(array_filter(
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

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (mixed $id): ?object => $this->rows[$class][(int) $id] ?? null
        );
        $repository->method('findAll')->willReturnCallback(fn (): array => array_values($this->rows[$class] ?? []));
        $repository->method('findBy')->willReturnCallback(static fn (array $criteria): array => $matching($criteria));
        $repository->method('count')->willReturnCallback(
            static fn (array $criteria): int => count($matching($criteria))
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
     * Assert a KPI tile shows the value with a label starting with the given text.
     */
    private function assertKpi(string $body, string $value, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '#kpi-value">' . preg_quote($value, '#') . '</p>\s*<p class="kpi-label">' . preg_quote($label, '#') . '#',
            $body,
            "KPI '$label' should be $value."
        );
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
        return (new ServerRequest('GET', '/orders'))->withQueryParams($query);
    }

    /**
     * Build a POST request with form fields.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): ServerRequest
    {
        return (new ServerRequest('POST', '/orders'))->withParsedBody($fields);
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
