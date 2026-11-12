<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Ebay\EbayApiException;
use App\Ebay\FulfillmentApi;
use App\Ebay\TradingApi;
use App\Entities\Order;
use App\Framework\Clock\ClockInterface;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use SortDirection;

/**
 * Copies shipment tracking and delivery from eBay onto the given imported eBay
 * orders (e.g. those on the current orders page) that are recent and not
 * delivered yet (no delivery date), so tracking added or changed on eBay after
 * an earlier sync is picked up too.
 *
 * Replaces the legacy "get order tracking" action. The carrier, tracking number
 * and shipped date come from the REST Fulfillment API; it has no delivery date,
 * so `shipping_delivery_datetime` comes from the Trading API's GetOrders
 * (`ActualDeliveryTime`, the carrier's delivery scan). Delivered orders show as
 * delivered and are not checked again.
 */
class TrackingSynchroniser
{
    /**
     * Only orders this many days old or newer are checked.
     */
    public const LOOKBACK_DAYS = 30;

    /**
     * Display names for eBay carrier codes, matching names already stored by the legacy app.
     */
    private const CARRIER_NAMES = [
        'royalmail' => 'Royal Mail',
        'parcelforce' => 'Parcelforce',
        'evri' => 'Evri',
        'hermes' => 'Hermes',
        'yodel' => 'Yodel',
        'dpd' => 'DPD',
        'dhl' => 'DHL',
        'ups' => 'UPS',
        'fedex' => 'FedEx',
        'usps' => 'USPS',
    ];

    /**
     * Create the synchroniser.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FulfillmentApi $fulfillment,
        private readonly TradingApi $trading,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Fetch tracking and delivery for the given orders that are eBay purchases from the last
     * LOOKBACK_DAYS days and not delivered; other orders (including delivered ones) are not sent to eBay.
     *
     * @param list<int> $orderIds Orders to consider (one tracking API call each, plus one delivery call).
     *
     * @return array{checked: int, updated: int, delivered: int, failed: list<string>, deliveryError: ?string}
     *     `updated` counts orders whose tracking or delivery changed; `delivered` those now marked delivered;
     *     `failed` lists order references whose tracking lookup failed; `deliveryError` is set when the
     *     delivery lookup failed (tracking is still saved).
     */
    public function sync(array $orderIds): array
    {
        $result = ['checked' => 0, 'updated' => 0, 'delivered' => 0, 'failed' => [], 'deliveryError' => null];
        $orderIds = array_values(array_unique(array_filter($orderIds, static fn (int $id): bool => $id > 0)));

        if ($orderIds === []) {
            return $result;
        }

        $since = DateTime::createFromImmutable($this->clock->now()->modify('-' . self::LOOKBACK_DAYS . ' days'));
        $criteria = Criteria::create()
            ->where(Criteria::expr()->in('id', $orderIds))
            ->andWhere(Criteria::expr()->eq('platform', 'Ebay'))
            ->andWhere(Criteria::expr()->eq('orderType', 'Purchase'))
            ->andWhere(Criteria::expr()->isNull('shippingDeliveryDatetime'))
            ->andWhere(Criteria::expr()->gte('orderDate', $since))
            ->orderBy(['orderDate' => SortDirection::Descending])
            ->setMaxResults(count($orderIds));

        $orders = $this->em->getRepository(Order::class)->matching($criteria);
        $changed = [];

        foreach ($orders as $order) {
            $result['checked']++;

            try {
                $fulfillments = $this->fulfillment->shippingFulfillments($order->getOrderReference());
            } catch (EbayApiException) {
                $result['failed'][] = $order->getOrderReference();
                continue;
            }

            if ($this->applyTracking($order, $fulfillments)) {
                $changed[spl_object_id($order)] = true;
            }
        }

        $checked = array_values(array_filter(
            array_values($orders->toArray()),
            static fn (Order $order): bool => $order->getOrderReference() !== ''
        ));

        if ($checked !== []) {
            try {
                $deliveries = $this->trading->deliveryTimes(array_map(
                    static fn (Order $order): string => $order->getOrderReference(),
                    $checked
                ));
            } catch (EbayApiException $e) {
                $deliveries = [];
                $result['deliveryError'] = $e->getMessage();
            }

            foreach ($checked as $order) {
                $time = $deliveries[$order->getOrderReference()] ?? null;

                if ($time !== null) {
                    $order->setShippingDeliveryDatetime($this->localTime($time));
                    $changed[spl_object_id($order)] = true;
                    $result['delivered']++;
                }
            }
        }

        $result['updated'] = count($changed);

        if ($result['updated'] > 0) {
            $this->em->flush();
        }

        return $result;
    }

    /**
     * Copy carrier, tracking number and shipped date from the first fulfillment that has them.
     *
     * @param list<array<string, mixed>> $fulfillments
     *
     * @return bool Whether any of the order's values changed.
     */
    private function applyTracking(Order $order, array $fulfillments): bool
    {
        foreach ($fulfillments as $fulfillment) {
            $tracking = is_string($fulfillment['shipmentTrackingNumber'] ?? null)
                ? trim($fulfillment['shipmentTrackingNumber']) : '';

            if ($tracking === '') {
                continue;
            }

            $changed = $order->getTrackingCode() !== $tracking;
            $order->setTrackingCode($tracking);

            $carrier = is_string($fulfillment['shippingCarrierCode'] ?? null)
                ? trim($fulfillment['shippingCarrierCode']) : '';

            if ($carrier !== '' && $order->getShippingCompany() !== $this->carrierName($carrier)) {
                $order->setShippingCompany($this->carrierName($carrier));
                $changed = true;
            }

            $shipped = $this->date($fulfillment['shippedDate'] ?? null);

            // Compare instants: == on DateTime objects ignores the time zone they are expressed in.
            if ($shipped !== null && $order->getShippingDatetime() != $shipped) {
                $order->setShippingDatetime($shipped);
                $changed = true;
            }

            return $changed;
        }

        return false;
    }

    /**
     * Display name for an eBay carrier code, e.g. "RoyalMail" → "Royal Mail".
     */
    private function carrierName(string $code): string
    {
        $key = strtolower(preg_replace('/[^a-z]/i', '', $code) ?? $code);

        return self::CARRIER_NAMES[$key] ?? $code;
    }

    /**
     * Parse an eBay UTC timestamp into the app's time zone, or null.
     */
    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return $this->localTime(new DateTimeImmutable($value));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * A time converted to the app's time zone, as the mutable DateTime the entities store.
     */
    private function localTime(DateTimeImmutable $time): DateTime
    {
        return DateTime::createFromImmutable($time->setTimezone(new DateTimeZone(date_default_timezone_get())));
    }
}
