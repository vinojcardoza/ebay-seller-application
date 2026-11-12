<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Ebay\EbayConfig;
use App\Ebay\EbayException;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\EbayOAuth;
use App\Ebay\FulfillmentApi;
use App\Entities\Order;
use App\Framework\Clock\ClockInterface;

/**
 * Recent eBay orders that have not been imported yet, and importing one of them;
 * shown at the bottom of the Orders page.
 */
class RecentOrders
{
    /**
     * Allowed look-back periods (days) for the recent orders list.
     */
    public const DAY_OPTIONS = [1, 3, 7, 14, 30, 90];

    /**
     * Default look-back period, matching the legacy "recent orders" (3 days).
     */
    public const DEFAULT_DAYS = 3;

    /**
     * Create the service.
     */
    public function __construct(
        private readonly EbayConfig $config,
        private readonly EbayOAuth $oauth,
        private readonly FulfillmentApi $fulfillment,
        private readonly OrderImporter $importer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Whether eBay is configured and the seller's account is connected.
     */
    public function isConnected(): bool
    {
        return $this->config->isConfigured() && $this->oauth->connection() !== null;
    }

    /**
     * eBay orders created in the last `$days` days that are not imported yet, newest first.
     *
     * @return list<ImportPreview>
     *
     * @throws EbayException When eBay is not connected or the API call fails.
     */
    public function notImported(int $days): array
    {
        $since = $this->clock->now()->modify('-' . self::days($days) . ' days');
        $previews = array_values(array_filter(
            $this->importer->preview($this->fulfillment->ordersCreatedSince($since)),
            static fn (ImportPreview $preview): bool => !$preview->alreadyImported
        ));
        usort($previews, static fn (ImportPreview $a, ImportPreview $b): int => $b->created <=> $a->created);

        return $previews;
    }

    /**
     * Fetch one eBay order and import it (with its items) into the orders table.
     *
     * @throws EbayNotConnectedException When eBay is not connected.
     * @throws OrderImportException When the order cannot be imported.
     * @throws EbayException When the API call fails.
     */
    public function import(string $orderId, bool $promoted): Order
    {
        return $this->importer->import($this->fulfillment->order($orderId), $promoted);
    }

    /**
     * Look-back days from user input, limited to the allowed options.
     */
    public static function days(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : self::DEFAULT_DAYS;

        return in_array($days, self::DAY_OPTIONS, true) ? $days : self::DEFAULT_DAYS;
    }
}
