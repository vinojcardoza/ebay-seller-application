<?php

declare(strict_types=1);

namespace App\Ebay;

use DateTimeImmutable;
use Exception;
use SimpleXMLElement;

/**
 * eBay Trading API (XML) calls that have no REST equivalent.
 *
 * The REST Fulfillment API reports shipments but not deliveries; the Trading
 * API's GetOrders reports the carrier's delivery scan as `ActualDeliveryTime`.
 *
 * @see https://developer.ebay.com/devzone/xml/docs/reference/ebay/GetOrders.html
 */
class TradingApi
{
    /**
     * Most order IDs sent in one GetOrders call (its largest page size).
     */
    public const BATCH_SIZE = 100;

    /**
     * Namespace of Trading API responses.
     */
    private const NS = 'urn:ebay:apis:eBLBaseComponents';

    /**
     * Create the API wrapper.
     */
    public function __construct(private readonly EbayClient $client)
    {
    }

    /**
     * When each order was delivered, according to the carrier's tracking.
     *
     * Orders that are not delivered yet (or unknown to eBay) are left out. When
     * an order has several packages, the latest delivery time is used.
     *
     * @param list<string> $orderIds eBay order IDs (e.g. `12-34567-89012`).
     *
     * @return array<string, DateTimeImmutable> Delivery time (UTC) by order ID.
     *
     * @throws EbayException
     */
    public function deliveryTimes(array $orderIds): array
    {
        $delivered = [];

        foreach (array_chunk(array_values(array_unique($orderIds)), self::BATCH_SIZE) as $batch) {
            $ids = implode('', array_map(
                static fn (string $id): string => '<OrderID>' . htmlspecialchars($id, ENT_XML1) . '</OrderID>',
                $batch
            ));

            $xml = $this->client->trading(
                'GetOrders',
                "<OrderIDArray>$ids</OrderIDArray><OrderRole>Seller</OrderRole>"
                . '<Pagination><EntriesPerPage>' . self::BATCH_SIZE . '</EntriesPerPage></Pagination>'
            );

            foreach ($xml->OrderArray->Order ?? [] as $order) {
                $time = $this->latestDelivery($order);

                if ($time === null) {
                    continue;
                }

                foreach ([(string) $order->OrderID, (string) $order->ExtendedOrderID] as $id) {
                    if ($id !== '') {
                        $delivered[$id] = $time;
                    }
                }
            }
        }

        return $delivered;
    }

    /**
     * Latest `ActualDeliveryTime` anywhere in an order (order or line level), or null.
     */
    private function latestDelivery(SimpleXMLElement $order): ?DateTimeImmutable
    {
        $order->registerXPathNamespace('e', self::NS);
        $latest = null;

        foreach ($order->xpath('.//e:ShippingPackageInfo/e:ActualDeliveryTime') ?: [] as $node) {
            try {
                $time = new DateTimeImmutable(trim((string) $node));
            } catch (Exception) {
                continue;
            }

            if ($latest === null || $time > $latest) {
                $latest = $time;
            }
        }

        return $latest;
    }
}
