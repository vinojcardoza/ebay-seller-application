<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\EbayApiException;
use App\Ebay\FulfillmentApi;
use App\Ebay\Import\TrackingSynchroniser;
use App\Ebay\TradingApi;
use App\Entities\Order;
use DateTimeZone;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SortDirection;
use Tests\Support\FrozenClock;

/**
 * Tests for copying shipment tracking from eBay onto imported orders.
 */
#[CoversClass(TrackingSynchroniser::class)]
final class TrackingSynchroniserTest extends TestCase
{
    /**
     * Orders returned by the fake repository query.
     *
     * @var list<Order>
     */
    private array $orders = [];

    /**
     * Fulfillments (or an exception) eBay returns per order reference.
     *
     * @var array<string, list<array<string, mixed>>|EbayApiException>
     */
    private array $fulfillments = [];

    /**
     * Delivery times eBay's Trading API returns by order reference, or an exception to throw.
     *
     * @var array<string, \DateTimeImmutable>|EbayApiException
     */
    private array|EbayApiException $deliveries = [];

    /**
     * Order references passed to each delivery lookup.
     *
     * @var list<list<string>>
     */
    private array $deliveryLookups = [];

    /**
     * Criteria the synchroniser queried with.
     */
    private ?Criteria $criteria = null;

    /**
     * Entity manager mock; tests set expectations on flush().
     */
    private EntityManagerInterface&MockObject $em;

    /**
     * Synchroniser under test.
     */
    private TrackingSynchroniser $synchroniser;

    /**
     * Build the synchroniser with a fake repository and Fulfillment API.
     */
    protected function setUp(): void
    {
        $this->orders = [];
        $this->fulfillments = [];
        $this->deliveries = [];
        $this->deliveryLookups = [];
        $this->criteria = null;

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('matching')->willReturnCallback(function (Criteria $criteria): AbstractLazyCollection {
            $this->criteria = $criteria;

            return $this->lazyCollection($this->orders);
        });

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($repository) {
            $this->assertSame(Order::class, $class);

            return $repository;
        });

        $api = $this->createStub(FulfillmentApi::class);
        $api->method('shippingFulfillments')->willReturnCallback(function (string $reference): array {
            $result = $this->fulfillments[$reference] ?? [];

            if ($result instanceof EbayApiException) {
                throw $result;
            }

            return $result;
        });

        $trading = $this->createStub(TradingApi::class);
        $trading->method('deliveryTimes')->willReturnCallback(function (array $references): array {
            $this->deliveryLookups[] = $references;

            if ($this->deliveries instanceof EbayApiException) {
                throw $this->deliveries;
            }

            return $this->deliveries;
        });

        $this->synchroniser = new TrackingSynchroniser(
            $this->em,
            $api,
            $trading,
            new FrozenClock('2026-11-10 12:00:00 UTC')
        );
    }

    /**
     * Tracking number, carrier name and shipped date are copied onto the order.
     */
    public function testSyncCopiesTracking(): void
    {
        $order = $this->order('12-1');
        $this->fulfillments['12-1'] = [[
            'fulfillmentId' => 'F1',
            'shipmentTrackingNumber' => ' AB123456789GB ',
            'shippingCarrierCode' => 'RoyalMail',
            'shippedDate' => '2026-11-09T16:05:00.000Z',
        ]];
        $this->em->expects($this->once())->method('flush');

        $result = $this->synchroniser->sync();

        $this->assertSame($this->syncResult(1, 1), $result);
        $this->assertSame('AB123456789GB', $order->getTrackingCode());
        $this->assertSame('Royal Mail', $order->getShippingCompany());
        $this->assertSame(
            '2026-11-09 16:05:00',
            $this->utc($order->getShippingDatetime())
        );
        $this->assertNull($order->getShippingDeliveryDatetime(), 'Not delivered yet.');
    }

    /**
     * eBay carrier codes and the stored carrier name.
     *
     * @return array<string, array{string, string}>
     */
    public static function carrierProvider(): array
    {
        return [
            'Royal Mail' => ['RoyalMail', 'Royal Mail'],
            'Royal Mail with underscore' => ['Royal_Mail', 'Royal Mail'],
            'Evri' => ['EVRI', 'Evri'],
            'Yodel' => ['Yodel', 'Yodel'],
            'unknown kept as is' => ['ACME Couriers', 'ACME Couriers'],
        ];
    }

    /**
     * Carrier codes map to the names the legacy app stored.
     */
    #[DataProvider('carrierProvider')]
    public function testCarrierNames(string $code, string $name): void
    {
        $order = $this->order('12-1');
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => 'T1', 'shippingCarrierCode' => $code]];
        $this->em->expects($this->once())->method('flush');

        $this->synchroniser->sync();

        $this->assertSame($name, $order->getShippingCompany());
    }

    /**
     * The first fulfillment with a tracking number wins; orders without one are left alone.
     */
    public function testSkipsFulfillmentsWithoutTracking(): void
    {
        $shipped = $this->order('12-1');
        $unshipped = $this->order('12-2');
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => ''], ['shipmentTrackingNumber' => 'SECOND']];
        $this->fulfillments['12-2'] = [];
        $this->em->expects($this->once())->method('flush');

        $result = $this->synchroniser->sync();

        $this->assertSame($this->syncResult(2, 1), $result);
        $this->assertSame('SECOND', $shipped->getTrackingCode());
        $this->assertNull($shipped->getShippingCompany());
        $this->assertNull($unshipped->getTrackingCode());
    }

    /**
     * A failed lookup is reported and the remaining orders are still processed.
     */
    public function testFailedLookupsAreReported(): void
    {
        $this->order('06-old');
        $ok = $this->order('12-1');
        $this->fulfillments['06-old'] = new EbayApiException('Order not found', 404);
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => 'T1']];
        $this->em->expects($this->once())->method('flush');

        $result = $this->synchroniser->sync();

        $this->assertSame($this->syncResult(2, 1, failed: ['06-old']), $result);
        $this->assertSame('T1', $ok->getTrackingCode());
    }

    /**
     * Nothing is flushed when no order changed.
     */
    public function testNoFlushWithoutUpdates(): void
    {
        $this->order('12-1');
        $this->em->expects($this->never())->method('flush');

        $this->assertSame($this->syncResult(1, 0), $this->synchroniser->sync());
    }

    /**
     * Orders that already have the same tracking are checked but not counted as updated.
     */
    public function testUnchangedTrackingIsNotCounted(): void
    {
        $order = $this->order('12-1')
            ->setTrackingCode('T1')
            ->setShippingCompany('Royal Mail')
            ->setShippingDatetime(new \DateTime('2026-11-09 16:05:00 UTC'));
        $this->fulfillments['12-1'] = [[
            'shipmentTrackingNumber' => 'T1',
            'shippingCarrierCode' => 'RoyalMail',
            'shippedDate' => '2026-11-09T16:05:00.000Z',
        ]];
        $this->em->expects($this->never())->method('flush');

        $this->assertSame($this->syncResult(1, 0), $this->synchroniser->sync());
        $this->assertSame('T1', $order->getTrackingCode());
    }

    /**
     * A tracking number changed on eBay since the last sync replaces the stored one.
     */
    public function testChangedTrackingIsUpdated(): void
    {
        $order = $this->order('12-1')->setTrackingCode('OLD');
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => 'NEW']];
        $this->em->expects($this->once())->method('flush');

        $this->assertSame($this->syncResult(1, 1), $this->synchroniser->sync());
        $this->assertSame('NEW', $order->getTrackingCode());
    }

    /**
     * A delivery reported by the carrier sets the delivery date (in the app's time zone) and counts as an update.
     */
    public function testDeliveredOrderGetsDeliveryDate(): void
    {
        $order = $this->order('12-1')->setTrackingCode('T1');
        $this->order('12-2');
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => 'T1']];
        $this->deliveries = ['12-1' => new \DateTimeImmutable('2026-11-10T09:30:00.000Z')];
        $this->em->expects($this->once())->method('flush');

        $result = $this->synchroniser->sync();

        $this->assertSame($this->syncResult(2, 1, delivered: 1), $result);
        $this->assertSame([['12-1', '12-2']], $this->deliveryLookups, 'One lookup for all checked orders.');
        $this->assertSame('2026-11-10 09:30:00', $this->utc($order->getShippingDeliveryDatetime()));
        $this->assertSame(
            date_default_timezone_get(),
            $order->getShippingDeliveryDatetime()?->getTimezone()->getName()
        );
    }

    /**
     * New tracking and a delivery on the same order count as one updated order.
     */
    public function testTrackingAndDeliveryCountOnce(): void
    {
        $order = $this->order('12-1');
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => 'T1']];
        $this->deliveries = ['12-1' => new \DateTimeImmutable('2026-11-10T09:30:00Z')];
        $this->em->expects($this->once())->method('flush');

        $this->assertSame($this->syncResult(1, 1, delivered: 1), $this->synchroniser->sync());
        $this->assertSame('T1', $order->getTrackingCode());
        $this->assertNotNull($order->getShippingDeliveryDatetime());
    }

    /**
     * Orders whose tracking lookup failed are still checked for delivery.
     */
    public function testDeliveryCheckedWhenTrackingLookupFails(): void
    {
        $order = $this->order('12-1');
        $this->fulfillments['12-1'] = new EbayApiException('Timeout');
        $this->deliveries = ['12-1' => new \DateTimeImmutable('2026-11-10T09:30:00Z')];
        $this->em->expects($this->once())->method('flush');

        $this->assertSame($this->syncResult(1, 1, delivered: 1, failed: ['12-1']), $this->synchroniser->sync());
        $this->assertNotNull($order->getShippingDeliveryDatetime());
    }

    /**
     * A failed delivery lookup is reported, and tracking found is still saved.
     */
    public function testFailedDeliveryLookupKeepsTracking(): void
    {
        $order = $this->order('12-1');
        $this->fulfillments['12-1'] = [['shipmentTrackingNumber' => 'T1']];
        $this->deliveries = new EbayApiException('GetOrders failed');
        $this->em->expects($this->once())->method('flush');

        $this->assertSame(
            $this->syncResult(1, 1, deliveryError: 'GetOrders failed'),
            $this->synchroniser->sync()
        );
        $this->assertSame('T1', $order->getTrackingCode());
        $this->assertNull($order->getShippingDeliveryDatetime());
    }

    /**
     * eBay is not asked for deliveries when there are no orders to check.
     */
    public function testNoDeliveryLookupWithoutOrders(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->assertSame($this->syncResult(0, 0), $this->synchroniser->sync());
        $this->assertSame([], $this->deliveryLookups);
    }

    /**
     * Only eBay purchases from the last 30 days that are not delivered are queried,
     * with or without tracking, newest first, up to the limit.
     */
    public function testQueryCriteria(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->synchroniser->sync(25);

        $this->assertNotNull($this->criteria);
        $this->assertSame(25, $this->criteria->getMaxResults());
        $this->assertSame(['orderDate' => SortDirection::Descending], $this->criteria->getOrderings());

        $where = $this->criteria->getWhereExpression();
        $this->assertNotNull($where);
        $matches = static fn (Order $order): bool => (new ArrayCollection([$order]))
            ->matching(Criteria::create()->where($where))
            ->count() === 1;

        $recent = new \DateTime('2026-11-01 10:00:00 UTC');
        $this->assertTrue($matches($this->order('a', $recent)));
        $this->assertTrue($matches($this->order('b', $recent)->setTrackingCode('')));
        $this->assertTrue($matches($this->order('c', $recent)->setTrackingCode('T1')));
        $this->assertTrue($matches($this->order('d', new \DateTime('2026-10-11 12:00:00 UTC'))), '30 days ago.');
        $this->assertFalse($matches($this->order('g', new \DateTime('2026-10-11 11:59:00 UTC'))), 'Older.');
        $this->assertFalse($matches(
            $this->order('h', $recent)->setTrackingCode('T1')->setShippingDeliveryDatetime(new \DateTime('2026-11-03'))
        ));
        $this->assertFalse($matches($this->order('e', $recent)->setPlatform('Etsy')));
        $this->assertFalse($matches($this->order('f', $recent)->setOrderType('Refund')));
    }

    /**
     * Lazy, selectable collection like the one EntityRepository::matching() returns.
     *
     * @param list<Order> $items
     *
     * @return AbstractLazyCollection<int, Order>&Selectable<int, Order>
     */
    private function lazyCollection(array $items): AbstractLazyCollection
    {
        return new class ($items) extends AbstractLazyCollection implements Selectable {
            /**
             * Hold the items until the collection is initialised.
             *
             * @param list<Order> $items
             */
            public function __construct(private readonly array $items)
            {
            }

            /**
             * Load the items into an ArrayCollection.
             */
            protected function doInitialize(): void
            {
                $this->collection = new ArrayCollection($this->items);
            }

            /**
             * Filter the items by criteria.
             *
             * @return Collection<int, Order>
             */
            public function matching(Criteria $criteria): Collection
            {
                return (new ArrayCollection($this->items))->matching($criteria);
            }
        };
    }

    /**
     * Add an undelivered eBay purchase without tracking to the fake query result.
     */
    private function order(string $reference, ?\DateTime $date = null): Order
    {
        $order = (new Order())
            ->setPlatform('Ebay')
            ->setOrderType('Purchase')
            ->setOrderReference($reference)
            ->setOrderDate($date ?? new \DateTime('2026-11-08 10:00:00 UTC'));

        $this->orders[] = $order;

        return $order;
    }

    /**
     * Expected sync() result.
     *
     * @param list<string> $failed
     *
     * @return array{checked: int, updated: int, delivered: int, failed: list<string>, deliveryError: ?string}
     */
    private function syncResult(
        int $checked,
        int $updated,
        int $delivered = 0,
        array $failed = [],
        ?string $deliveryError = null
    ): array {
        return [
            'checked' => $checked,
            'updated' => $updated,
            'delivered' => $delivered,
            'failed' => $failed,
            'deliveryError' => $deliveryError,
        ];
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
