<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Ebay\EbayApiException;
use App\Ebay\FulfillmentApi;
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
 * Copies shipment tracking from eBay onto imported eBay orders that have none yet
 * (tracking code NULL or empty — the legacy app stored both).
 *
 * Replaces the legacy "get order tracking" action. eBay's REST Fulfillment API
 * reports the carrier, tracking number and shipped date, but not the actual
 * delivery date, so `shipping_delivery_datetime` is left as it is.
 */
class TrackingSynchroniser
{
    /**
     * Only orders this many days old or newer are checked.
     */
    public const LOOKBACK_DAYS = 90;

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
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Fetch tracking for recent eBay orders without a tracking code.
     *
     * @param int $limit Most orders to check in one run (one API call each).
     *
     * @return array{checked: int, updated: int, failed: list<string>}
     *     `failed` lists order references whose lookup failed.
     */
    public function sync(int $limit = 50): array
    {
        $since = DateTime::createFromImmutable($this->clock->now()->modify('-' . self::LOOKBACK_DAYS . ' days'));
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('platform', 'Ebay'))
            ->andWhere(Criteria::expr()->eq('orderType', 'Purchase'))
            ->andWhere(Criteria::expr()->orX(
                Criteria::expr()->isNull('trackingCode'),
                Criteria::expr()->eq('trackingCode', '')
            ))
            ->andWhere(Criteria::expr()->gte('orderDate', $since))
            ->orderBy(['orderDate' => SortDirection::Descending])
            ->setMaxResults(max(1, $limit));

        $orders = $this->em->getRepository(Order::class)->matching($criteria);
        $result = ['checked' => 0, 'updated' => 0, 'failed' => []];

        foreach ($orders as $order) {
            $result['checked']++;

            try {
                $fulfillments = $this->fulfillment->shippingFulfillments($order->getOrderReference());
            } catch (EbayApiException) {
                $result['failed'][] = $order->getOrderReference();
                continue;
            }

            if ($this->applyTracking($order, $fulfillments)) {
                $result['updated']++;
            }
        }

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
     * @return bool Whether the order changed.
     */
    private function applyTracking(Order $order, array $fulfillments): bool
    {
        foreach ($fulfillments as $fulfillment) {
            $tracking = is_string($fulfillment['shipmentTrackingNumber'] ?? null)
                ? trim($fulfillment['shipmentTrackingNumber']) : '';

            if ($tracking === '') {
                continue;
            }

            $order->setTrackingCode($tracking);

            $carrier = is_string($fulfillment['shippingCarrierCode'] ?? null)
                ? trim($fulfillment['shippingCarrierCode']) : '';

            if ($carrier !== '') {
                $order->setShippingCompany($this->carrierName($carrier));
            }

            $shipped = $this->date($fulfillment['shippedDate'] ?? null);

            if ($shipped !== null) {
                $order->setShippingDatetime($shipped);
            }

            return true;
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
            return DateTime::createFromImmutable(
                (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))
            );
        } catch (Exception) {
            return null;
        }
    }
}
