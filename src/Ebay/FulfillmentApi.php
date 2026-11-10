<?php

declare(strict_types=1);

namespace App\Ebay;

use DateTimeInterface;
use DateTimeZone;
use DateTimeImmutable;

/**
 * eBay Sell Fulfillment API v1: the seller's orders and their shipments.
 *
 * Replaces the Trading API `GetOrders` call used by the legacy app.
 *
 * @see https://developer.ebay.com/api-docs/sell/fulfillment/overview.html
 */
class FulfillmentApi
{
    /**
     * Largest page size getOrders allows.
     */
    private const PAGE_SIZE = 200;

    /**
     * Base path of the API.
     */
    private const BASE = '/sell/fulfillment/v1';

    /**
     * Create the API wrapper.
     */
    public function __construct(private readonly EbayClient $client)
    {
    }

    /**
     * All orders created at or after the given time, newest last as eBay returns them.
     *
     * eBay only returns orders up to two years old.
     *
     * @return list<array<string, mixed>> Order objects as documented for getOrders.
     *
     * @throws EbayException
     */
    public function ordersCreatedSince(DateTimeInterface $from): array
    {
        $since = DateTimeImmutable::createFromInterface($from)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');

        $orders = [];
        $offset = 0;

        do {
            $page = $this->client->get(self::BASE . '/order', [
                'filter' => "creationdate:[$since..]",
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ]);

            $batch = $this->listOf($page['orders'] ?? []);
            array_push($orders, ...$batch);

            $offset += self::PAGE_SIZE;
            $total = (int) ($page['total'] ?? 0);
        } while ($batch !== [] && $offset < $total);

        return $orders;
    }

    /**
     * A single order by its eBay order ID.
     *
     * @return array<string, mixed>
     *
     * @throws EbayException When the order does not exist or the call fails.
     */
    public function order(string $orderId): array
    {
        return $this->client->get(self::BASE . '/order/' . rawurlencode($orderId));
    }

    /**
     * Shipments recorded for an order (carrier, tracking number, shipped date).
     *
     * @return list<array<string, mixed>>
     *
     * @throws EbayException
     */
    public function shippingFulfillments(string $orderId): array
    {
        $data = $this->client->get(self::BASE . '/order/' . rawurlencode($orderId) . '/shipping_fulfillment');

        return $this->listOf($data['fulfillments'] ?? []);
    }

    /**
     * Keep only the array entries of a decoded JSON list.
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
