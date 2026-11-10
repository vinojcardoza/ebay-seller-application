<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\EbayApiException;
use App\Ebay\FulfillmentApi;
use App\Ebay\Import\TrackingSynchroniser;
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

        $this->synchroniser = new TrackingSynchroniser($this->em, $api, new FrozenClock('2026-11-10 12:00:00 UTC'));
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

        $this->assertSame(['checked' => 1, 'updated' => 1, 'failed' => []], $result);
        $this->assertSame('AB123456789GB', $order->getTrackingCode());
        $this->assertSame('Royal Mail', $order->getShippingCompany());
        $this->assertSame(
            '2026-11-09 16:05:00',
            $this->utc($order->getShippingDatetime())
        );
        $this->assertNull($order->getShippingDeliveryDatetime(), 'The REST API has no delivery date.');
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

        $this->assertSame(['checked' => 2, 'updated' => 1, 'failed' => []], $result);
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

        $this->assertSame(['checked' => 2, 'updated' => 1, 'failed' => ['06-old']], $result);
        $this->assertSame('T1', $ok->getTrackingCode());
    }

    /**
     * Nothing is flushed when no order changed.
     */
    public function testNoFlushWithoutUpdates(): void
    {
        $this->order('12-1');
        $this->em->expects($this->never())->method('flush');

        $this->assertSame(['checked' => 1, 'updated' => 0, 'failed' => []], $this->synchroniser->sync());
    }

    /**
     * Only recent eBay purchases without tracking (NULL or empty) are queried, newest first, up to the limit.
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
        $this->assertFalse($matches($this->order('c', $recent)->setTrackingCode('T1')));
        $this->assertFalse($matches($this->order('d', new \DateTime('2026-08-01 10:00:00 UTC'))));
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
     * Add an eBay purchase without tracking to the fake query result.
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
     * Format a date in UTC without modifying it.
     */
    private function utc(?\DateTimeInterface $date): string
    {
        return $date === null ? '' : \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
