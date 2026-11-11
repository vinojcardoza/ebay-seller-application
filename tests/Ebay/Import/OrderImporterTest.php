<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\Import\ImportPreview;
use App\Ebay\Import\OrderImporter;
use App\Ebay\Import\OrderImportException;
use App\Ebay\Import\PurchaseCostCalculator;
use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\Order;
use App\Entities\OrderItem;
use App\Entities\Product;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for turning Fulfillment API orders into Order and OrderItem records.
 */
#[CoversClass(OrderImporter::class)]
final class OrderImporterTest extends TestCase
{
    /**
     * Products by SKU known to the fake repository.
     *
     * @var array<string, Product>
     */
    private array $products = [];

    /**
     * Custom SKUs by SKU known to the fake repository.
     *
     * @var array<string, CustomSku>
     */
    private array $customSkus = [];

    /**
     * References of orders already in the database.
     *
     * @var list<string>
     */
    private array $existingReferences = [];

    /**
     * Entities passed to persist().
     *
     * @var list<object>
     */
    private array $persisted = [];

    /**
     * Entity manager mock; tests set expectations on flush().
     */
    private EntityManagerInterface&MockObject $em;

    /**
     * Importer under test.
     */
    private OrderImporter $importer;

    /**
     * Seed one product (LCD-1602) and build the importer with fakes.
     * Every product's FIFO unit cost is "0.42".
     */
    protected function setUp(): void
    {
        $this->products = [];
        $this->customSkus = [];
        $this->existingReferences = [];
        $this->persisted = [];

        $this->addProduct('LCD-1602', 'BPL2', '1.55', '0.08');

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params): string|false => $params === ['GB'] ? 'United Kingdom' : false
        );

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($connection);
        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => $this->repository($class)
        );
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $costs = $this->createStub(PurchaseCostCalculator::class);
        $costs->method('unitCost')->willReturn('0.42');

        $this->importer = new OrderImporter($this->em, $costs);
    }

    // ---------- import: order fields ----------

    /**
     * The order gets the eBay reference, date, buyer, address and the product's shipping costs.
     */
    public function testImportMapsOrderFields(): void
    {
        $this->em->expects($this->once())->method('flush');

        $order = $this->importer->import($this->ebayOrder());

        $this->assertSame($order, $this->persisted[0]);
        $this->assertSame('Ebay', $order->getPlatform());
        $this->assertSame('Purchase', $order->getOrderType());
        $this->assertSame('12-34567-89012', $order->getOrderReference());
        $this->assertSame(
            '2026-11-09 15:30:00',
            $this->utc($order->getOrderDate())
        );
        $this->assertSame(date_default_timezone_get(), $order->getOrderDate()->getTimezone()->getName());
        $this->assertSame('buyer_1', $order->getEbayUserId());
        $this->assertSame('Jane Buyer', $order->getFullName());
        $this->assertSame('jane@example.com', $order->getEmail());
        $this->assertSame('07700900000', $order->getPhone());
        $this->assertSame('1 High St', $order->getStreet1());
        $this->assertSame('Flat 2', $order->getStreet2());
        $this->assertSame('Leeds', $order->getCity());
        $this->assertSame('West Yorkshire', $order->getState());
        $this->assertSame('LS1 1AA', $order->getPostcode());
        $this->assertSame('GB', $order->getCountry());
        $this->assertSame('United Kingdom', $order->getCountryName());
        $this->assertSame('BPL2', $order->getShippingService());
        $this->assertSame('1.55', $order->getPostageCost());
        $this->assertSame('0.08', $order->getPackagingCost());
        $this->assertSame(1, $order->getStatus());
    }

    /**
     * Missing contact details fall back to the registration email and are otherwise stored as NULL.
     */
    public function testImportHandlesMissingContactDetails(): void
    {
        $this->em->expects($this->once())->method('flush');

        $ebayOrder = $this->ebayOrder();
        $ebayOrder['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] = [
            'fullName' => 'Jane Buyer',
            'contactAddress' => ['countryCode' => 'FR'],
        ];

        $order = $this->importer->import($ebayOrder);

        $this->assertSame('reg@example.com', $order->getEmail());
        $this->assertNull($order->getPhone());
        $this->assertNull($order->getStreet1());
        $this->assertNull($order->getPostcode());
        $this->assertSame('FR', $order->getCountry());
        $this->assertSame('FR', $order->getCountryName(), 'Unknown country names fall back to the code.');
    }

    /**
     * Emoji in the buyer's name and address (which the utf8mb3 columns cannot store) are removed.
     */
    public function testImportRemovesEmojiFromContactDetails(): void
    {
        $this->em->expects($this->once())->method('flush');
        $ebayOrder = $this->ebayOrder();
        $shipTo = &$ebayOrder['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'];
        $shipTo['fullName'] = "Jane \u{1F338} Buyer";
        $shipTo['contactAddress']['addressLine2'] = "Flat 2 \u{1F3E0}";
        unset($shipTo);

        $order = $this->importer->import($ebayOrder);

        $this->assertSame('Jane Buyer', $order->getFullName());
        $this->assertSame('Flat 2', $order->getStreet2());
    }

    /**
     * A product without shipping settings uses the legacy defaults.
     */
    public function testImportUsesShippingDefaults(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->products = [];
        $this->addProduct('LCD-1602', null, '0.00', '0.00');

        $order = $this->importer->import($this->ebayOrder());

        $this->assertSame(OrderImporter::DEFAULT_SHIPPING_SERVICE, $order->getShippingService());
        $this->assertSame(OrderImporter::DEFAULT_POSTAGE_COST, $order->getPostageCost());
        $this->assertSame('0.00', $order->getPackagingCost());
    }

    // ---------- import: items ----------

    /**
     * A product line becomes one item with eBay's price and fee and the FIFO cost.
     */
    public function testImportCreatesItemWithEbayAmounts(): void
    {
        $this->em->expects($this->once())->method('flush');

        $order = $this->importer->import($this->ebayOrder());
        $items = $this->items();

        $this->assertCount(1, $items);
        $this->assertSame($order, $items[0]->getOrder());
        $this->assertSame($this->products['LCD-1602'], $items[0]->getProduct());
        $this->assertSame(1, $items[0]->getQuantity());
        $this->assertSame('0.42', $items[0]->getItemCost());
        $this->assertSame('6.99', $items[0]->getSoldPrice());
        $this->assertSame('1.83', $items[0]->getFeesDeductions());
        $this->assertSame('5.16', $items[0]->getTotalSoldPrice());
    }

    /**
     * The order's marketplace fee is shared across lines by value, and a discounted price is used when given.
     */
    public function testImportSplitsFeesAcrossLinesAndUsesDiscounts(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->addProduct('SCK-WHT', 'CRL48', '1.55', '0.05');
        $ebayOrder = $this->ebayOrder();
        $ebayOrder['totalMarketplaceFee'] = ['value' => '1.00', 'currency' => 'GBP'];
        $ebayOrder['lineItems'] = [
            $this->line('LCD-1602', 2, '6.00'),
            $this->line('SCK-WHT', 3, '5.00') + ['discountedLineItemCost' => ['value' => '4.00', 'currency' => 'GBP']],
        ];

        $this->importer->import($ebayOrder);
        $items = $this->items();

        $this->assertSame(
            [[2, '6.00', '0.60', '5.40'], [3, '4.00', '0.40', '3.60']],
            array_map(
                static fn (OrderItem $i): array => [
                    $i->getQuantity(),
                    $i->getSoldPrice(),
                    $i->getFeesDeductions(),
                    $i->getTotalSoldPrice(),
                ],
                $items
            )
        );
    }

    /**
     * A promoted sale adds the product's promo percentage plus 20% VAT to the fees (legacy rule).
     */
    public function testImportAddsPromotedListingFee(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->importer->import($this->ebayOrder(), promoted: true);

        // 7.50% of £6.99 = 52p, + 20% VAT = 62p, on top of the £1.83 marketplace fee.
        $this->assertSame('2.45', $this->items()[0]->getFeesDeductions());
        $this->assertSame('4.54', $this->items()[0]->getTotalSoldPrice());
    }

    /**
     * A custom SKU expands into its products, sharing the sale and fee by each line's price
     * (a line's price covers all of its units).
     */
    public function testImportExpandsCustomSkuBundle(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->addProduct('RES-10K', 'CRL48', '1.55', '0.05');
        $bundle = (new CustomSku())->setSku('KIT-1')->setShippingService('TOLP48')->setPostageCost('2.10')
            ->setPackageCost('0.12');
        $this->addComponent($bundle, 'LCD-1602', '4.00', 1);
        $this->addComponent($bundle, 'RES-10K', '2.00', 2);
        $this->customSkus['KIT-1'] = $bundle;

        $ebayOrder = $this->ebayOrder();
        $ebayOrder['totalMarketplaceFee'] = ['value' => '1.50', 'currency' => 'GBP'];
        $ebayOrder['lineItems'] = [$this->line('KIT-1', 2, '9.00')];

        $order = $this->importer->import($ebayOrder);
        $items = $this->items();

        $this->assertSame('TOLP48', $order->getShippingService());
        $this->assertSame('2.10', $order->getPostageCost());
        $this->assertSame('0.12', $order->getPackagingCost());
        $this->assertSame(
            [['LCD-1602', 2, '6.00', '1.00'], ['RES-10K', 4, '3.00', '0.50']],
            array_map(
                static fn (OrderItem $i): array => [
                    $i->getProduct()?->getSku(),
                    $i->getQuantity(),
                    $i->getSoldPrice(),
                    $i->getFeesDeductions(),
                ],
                $items
            )
        );
    }

    // ---------- import: rejections ----------

    /**
     * Orders that cannot be imported, with the expected reason.
     *
     * @return array<string, array{string, string}>
     */
    public static function rejectedOrderProvider(): array
    {
        return [
            'already imported' => ['imported', 'Already imported.'],
            'cancelled' => ['cancelled', 'Cancelled on eBay.'],
            'unknown SKU' => ['unknown', 'Unknown SKU: NOPE.'],
            'no line items' => ['empty', 'Has no line items.'],
            'empty bundle' => ['empty-bundle', 'custom SKU KIT-0 has no products.'],
        ];
    }

    /**
     * An order that cannot be imported throws with the reason and saves nothing.
     */
    #[DataProvider('rejectedOrderProvider')]
    public function testImportRejects(string $case, string $reason): void
    {
        $ebayOrder = $this->ebayOrder();

        match ($case) {
            'imported' => $this->existingReferences = ['12-34567-89012'],
            'cancelled' => $ebayOrder['cancelStatus'] = ['cancelState' => 'CANCELED'],
            'unknown' => $ebayOrder['lineItems'] = [$this->line('NOPE', 1, '1.00')],
            'empty' => $ebayOrder['lineItems'] = [],
            'empty-bundle' => [
                $this->customSkus['KIT-0'] = (new CustomSku())->setSku('KIT-0'),
                $ebayOrder['lineItems'] = [$this->line('KIT-0', 1, '1.00')],
            ],
            default => throw new \LogicException("Unknown case $case"),
        };

        $this->em->expects($this->never())->method('flush');

        try {
            $this->importer->import($ebayOrder);
            $this->fail('Expected an OrderImportException.');
        } catch (OrderImportException $e) {
            $this->assertStringContainsString('eBay order 12-34567-89012 cannot be imported', $e->getMessage());
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame([], $this->persisted);
    }

    // ---------- preview ----------

    /**
     * Previews summarise each order and how its SKUs match.
     */
    public function testPreview(): void
    {
        $this->em->expects($this->never())->method('flush');
        $this->em->expects($this->never())->method('persist');

        $this->customSkus['KIT-1'] = (new CustomSku())->setSku('KIT-1');
        $this->existingReferences = ['12-old'];

        $old = ['orderId' => '12-old'] + $this->ebayOrder();
        $mixed = $this->ebayOrder();
        $mixed['orderId'] = '12-mixed';
        $mixed['lineItems'] = [
            $this->line('LCD-1602', 1, '1.00'),
            $this->line('KIT-1', 2, '2.00'),
            $this->line('NOPE', 1, '1.00'),
        ];

        [$first, $second] = $this->importer->preview([$old, $mixed]);

        $this->assertInstanceOf(ImportPreview::class, $first);
        $this->assertSame('12-old', $first->orderId);
        $this->assertTrue($first->alreadyImported);
        $this->assertSame('buyer_1', $first->buyer);
        $this->assertSame('10.53', $first->total);
        $this->assertSame('GBP', $first->currency);
        $this->assertSame('2026-11-09T15:30:00+00:00', $first->created?->format(DATE_ATOM));

        $this->assertFalse($second->alreadyImported);
        $this->assertSame(
            [ImportPreview::MATCH_PRODUCT, ImportPreview::MATCH_CUSTOM_SKU, null],
            array_column($second->lines, 'match')
        );
        $this->assertSame(2, $second->lines[1]['quantity']);
        $this->assertSame(['NOPE'], $second->unknownSkus());
    }

    /**
     * Previewing no orders runs no queries.
     */
    public function testPreviewOfNothing(): void
    {
        $this->em->expects($this->never())->method('flush');
        $this->em->expects($this->never())->method('persist');

        $this->assertSame([], $this->importer->preview([]));
    }

    // ---------- helpers ----------

    /**
     * Items persisted during the import.
     *
     * @return list<OrderItem>
     */
    private function items(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $e): bool => $e instanceof OrderItem));
    }

    /**
     * A Fulfillment API order for one LCD-1602 at £6.99 with a £1.83 fee, shipped to Leeds.
     *
     * @return array<string, mixed>
     */
    private function ebayOrder(): array
    {
        return [
            'orderId' => '12-34567-89012',
            'creationDate' => '2026-11-09T15:30:00.000Z',
            'buyer' => ['username' => 'buyer_1', 'buyerRegistrationAddress' => ['email' => 'reg@example.com']],
            'pricingSummary' => ['total' => ['value' => '10.53', 'currency' => 'GBP']],
            'cancelStatus' => ['cancelState' => 'NONE_REQUESTED'],
            'totalMarketplaceFee' => ['value' => '1.83', 'currency' => 'GBP'],
            'fulfillmentStartInstructions' => [[
                'shippingStep' => [
                    'shipTo' => [
                        'fullName' => 'Jane Buyer',
                        'email' => 'jane@example.com',
                        'primaryPhone' => ['phoneNumber' => '07700900000'],
                        'contactAddress' => [
                            'addressLine1' => '1 High St',
                            'addressLine2' => 'Flat 2',
                            'city' => 'Leeds',
                            'stateOrProvince' => 'West Yorkshire',
                            'postalCode' => 'ls1 1aa',
                            'countryCode' => 'GB',
                        ],
                    ],
                ],
            ]],
            'lineItems' => [$this->line('LCD-1602', 1, '6.99')],
        ];
    }

    /**
     * A Fulfillment API line item.
     *
     * @return array<string, mixed>
     */
    private function line(string $sku, int $quantity, string $cost): array
    {
        return [
            'lineItemId' => 'line-' . $sku,
            'sku' => $sku,
            'title' => "Item $sku",
            'quantity' => $quantity,
            'lineItemCost' => ['value' => $cost, 'currency' => 'GBP'],
        ];
    }

    /**
     * Add a product (7.50% promo) to the fake repository.
     */
    private function addProduct(string $sku, ?string $shippingService, string $postage, string $package): void
    {
        $this->products[$sku] = (new Product())
            ->setSku($sku)
            ->setTitle("Product $sku")
            ->setShippingService($shippingService)
            ->setPostageCost($postage)
            ->setPackageCost($package);
    }

    /**
     * Add a product line to a custom SKU bundle.
     */
    private function addComponent(CustomSku $bundle, string $sku, string $price, int $quantity): void
    {
        $bundle->getCustomSkuProducts()->add(
            (new CustomSkuProduct())->setCustomSku($bundle)->setProduct($this->products[$sku])->setPrice($price)
                ->setFees('0.00')->setQuantity($quantity)
        );
    }

    /**
     * Fake repository for the entity class, answering findBy(['sku'|'orderReference' => [...]]).
     *
     * @return EntityRepository<object>
     */
    private function repository(string $class): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria) use ($class): array {
            $wanted = (array) reset($criteria);

            return match ($class) {
                Product::class => array_values(array_intersect_key($this->products, array_flip($wanted))),
                CustomSku::class => array_values(array_intersect_key($this->customSkus, array_flip($wanted))),
                Order::class => array_map(
                    static fn (string $ref): Order => (new Order())->setOrderReference($ref),
                    array_values(array_intersect($this->existingReferences, $wanted))
                ),
                default => [],
            };
        });

        return $repository;
    }

    /**
     * Format a date in UTC without modifying it.
     */
    private function utc(?\DateTimeInterface $date): string
    {
        return $date === null ? '' : \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
