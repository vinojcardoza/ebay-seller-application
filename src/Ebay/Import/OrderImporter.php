<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\Order;
use App\Entities\OrderItem;
use App\Entities\Product;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

/**
 * Turns eBay Fulfillment API orders into Order and OrderItem records.
 *
 * Follows the legacy "quick add" rules: SKUs map to products or custom SKUs
 * (bundles, expanded into their products), postage and packaging come from
 * the matched product, and each item's cost is its FIFO purchase cost.
 * Unlike legacy, prices and fees are the real amounts from eBay: the order's
 * marketplace fee is shared across lines by value.
 */
class OrderImporter
{
    /**
     * Shipping service used when the matched product has none (legacy default).
     */
    public const DEFAULT_SHIPPING_SERVICE = 'CRL48';

    /**
     * Postage cost used when the matched product has none (legacy default).
     */
    public const DEFAULT_POSTAGE_COST = '1.30';

    /**
     * VAT added on top of promoted-listing fees (legacy: fee × 1.2).
     */
    private const PROMO_VAT_RATE = 0.2;

    /**
     * Create the importer.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseCostCalculator $costs,
    ) {
    }

    /**
     * Summarise several eBay orders and whether each can be imported.
     *
     * @param list<array<string, mixed>> $ebayOrders Orders from the Fulfillment API.
     *
     * @return list<ImportPreview>
     */
    public function preview(array $ebayOrders): array
    {
        $references = array_map(fn (array $order): string => $this->string($order, 'orderId'), $ebayOrders);
        $imported = [];

        if ($references !== []) {
            foreach ($this->em->getRepository(Order::class)->findBy(['orderReference' => $references]) as $order) {
                $imported[$order->getOrderReference()] = true;
            }
        }

        $skus = [];

        foreach ($ebayOrders as $order) {
            foreach ($this->lineItems($order) as $line) {
                $skus[] = $this->string($line, 'sku');
            }
        }

        [$products, $customSkus] = $this->findSkus($skus);

        $previews = [];

        foreach ($ebayOrders as $order) {
            $lines = [];

            foreach ($this->lineItems($order) as $line) {
                $sku = $this->string($line, 'sku');
                $lines[] = [
                    'sku' => $sku,
                    'title' => $this->string($line, 'title'),
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'match' => isset($customSkus[$sku]) ? ImportPreview::MATCH_CUSTOM_SKU
                        : (isset($products[$sku]) ? ImportPreview::MATCH_PRODUCT : null),
                ];
            }

            $total = is_array($order['pricingSummary']['total'] ?? null) ? $order['pricingSummary']['total'] : [];
            $orderId = $this->string($order, 'orderId');

            $previews[] = new ImportPreview(
                $orderId,
                $this->date($this->string($order, 'creationDate')),
                is_array($order['buyer'] ?? null) ? $this->string($order['buyer'], 'username') : '',
                Money::format(Money::toPence($this->string($total, 'value'))),
                $this->string($total, 'currency'),
                $lines,
                isset($imported[$orderId]),
                $this->isCancelled($order),
            );
        }

        return $previews;
    }

    /**
     * Create an Order with its OrderItems from an eBay order, and save them.
     *
     * @param array<string, mixed> $ebayOrder Order from the Fulfillment API.
     * @param bool $promoted Whether the sale came through a promoted listing; adds
     *     each product's promo percentage (plus VAT) to its fees, as legacy did.
     *
     * @throws OrderImportException When the order cannot be imported.
     */
    public function import(array $ebayOrder, bool $promoted = false): Order
    {
        $preview = $this->preview([$ebayOrder])[0];

        if (!$preview->canImport()) {
            throw new OrderImportException(sprintf(
                'eBay order %s cannot be imported: %s',
                $preview->orderId,
                implode(' ', $preview->problems())
            ));
        }

        $lines = $this->lineItems($ebayOrder);
        [$products, $customSkus] = $this->findSkus(
            array_map(fn (array $line): string => $this->string($line, 'sku'), $lines)
        );

        foreach ($lines as $line) {
            $customSku = $customSkus[$this->string($line, 'sku')] ?? null;

            if ($customSku !== null && $this->bundleComponents($customSku) === []) {
                throw new OrderImportException(sprintf(
                    'eBay order %s cannot be imported: custom SKU %s has no products.',
                    $preview->orderId,
                    $customSku->getSku()
                ));
            }
        }

        $order = $this->createOrder($ebayOrder, $preview, $products, $customSkus);
        $this->em->persist($order);

        $lineSales = array_map(fn (array $line): int => $this->lineSale($line), $lines);
        $lineFees = Money::split($this->marketplaceFee($ebayOrder), $lineSales);

        foreach ($lines as $i => $line) {
            $sku = $this->string($line, 'sku');
            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            if (isset($customSkus[$sku])) {
                $this->addBundleItems($order, $customSkus[$sku], $quantity, $lineSales[$i], $lineFees[$i], $promoted);
            } else {
                $this->addItem($order, $products[$sku], $quantity, $lineSales[$i], $lineFees[$i], $promoted);
            }
        }

        $this->em->flush();

        return $order;
    }

    /**
     * Build the Order from the eBay order's buyer and shipping details.
     *
     * @param array<string, mixed> $ebayOrder
     * @param array<string, Product> $products
     * @param array<string, CustomSku> $customSkus
     */
    private function createOrder(
        array $ebayOrder,
        ImportPreview $preview,
        array $products,
        array $customSkus
    ): Order {
        $firstSku = $preview->lines[0]['sku'];
        $source = $customSkus[$firstSku] ?? $products[$firstSku];
        $shipTo = $this->shipTo($ebayOrder);
        $address = is_array($shipTo['contactAddress'] ?? null) ? $shipTo['contactAddress'] : [];
        $phone = is_array($shipTo['primaryPhone'] ?? null) ? $this->string($shipTo['primaryPhone'], 'phoneNumber') : '';
        $buyer = is_array($ebayOrder['buyer'] ?? null) ? $ebayOrder['buyer'] : [];
        $registration = is_array($buyer['buyerRegistrationAddress'] ?? null) ? $buyer['buyerRegistrationAddress'] : [];
        $country = strtoupper($this->string($address, 'countryCode'));
        $created = $preview->created ?? new DateTimeImmutable();

        return (new Order())
            ->setPlatform('Ebay')
            ->setOrderType('Purchase')
            ->setOrderReference($preview->orderId)
            ->setOrderDate(DateTime::createFromImmutable(
                $created->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ))
            ->setShippingService($source->getShippingService() ?: self::DEFAULT_SHIPPING_SERVICE)
            ->setPostageCost(
                Money::toPence($source->getPostageCost()) > 0 ? $source->getPostageCost() : self::DEFAULT_POSTAGE_COST
            )
            ->setPackagingCost(Money::format(Money::toPence($source->getPackageCost())))
            ->setEbayUserId($this->nullable($preview->buyer))
            ->setFullName($this->nullable($this->string($shipTo, 'fullName')))
            ->setEmail($this->nullable($this->string($shipTo, 'email') ?: $this->string($registration, 'email')))
            ->setPhone($this->nullable($phone))
            ->setStreet1($this->nullable($this->string($address, 'addressLine1')))
            ->setStreet2($this->nullable($this->string($address, 'addressLine2')))
            ->setCity($this->nullable($this->string($address, 'city')))
            ->setState($this->nullable($this->string($address, 'stateOrProvince')))
            ->setPostcode($this->nullable(strtoupper($this->string($address, 'postalCode'))))
            ->setCountry($this->nullable($country))
            ->setCountryName($this->nullable($this->countryName($country)))
            ->setStatus(1);
    }

    /**
     * Add one OrderItem for a product line.
     */
    private function addItem(
        Order $order,
        Product $product,
        int $quantity,
        int $salePence,
        int $feePence,
        bool $promoted
    ): void {
        if ($promoted) {
            $feePence += $this->promoFee($product, $salePence);
        }

        $item = (new OrderItem())
            ->setOrder($order)
            ->setProduct($product)
            ->setQuantity($quantity)
            ->setItemCost($this->costs->unitCost($product))
            ->setSoldPrice(Money::format($salePence))
            ->setFeesDeductions(Money::format($feePence))
            ->setTotalSoldPrice(Money::format($salePence - $feePence));

        $this->em->persist($item);
    }

    /**
     * Add one OrderItem per product in a custom SKU bundle. The line's sale and
     * fees are shared in proportion to each line's configured price, which is
     * the price for all of that line's units (e.g. £11.99 for a 3-pack).
     */
    private function addBundleItems(
        Order $order,
        CustomSku $customSku,
        int $quantity,
        int $salePence,
        int $feePence,
        bool $promoted
    ): void {
        $components = $this->bundleComponents($customSku);
        $weights = array_map(
            static fn (CustomSkuProduct $component): int => Money::toPence($component->getPrice()),
            $components
        );
        $sales = Money::split($salePence, $weights);
        $fees = Money::split($feePence, $weights);

        foreach ($components as $i => $component) {
            $this->addItem(
                $order,
                $component->getProduct() ?? throw new OrderImportException('Bundle line without a product.'),
                $quantity * max(1, $component->getQuantity() ?? 1),
                $sales[$i],
                $fees[$i],
                $promoted
            );
        }
    }

    /**
     * The custom SKU's product lines that have a product.
     *
     * @return list<CustomSkuProduct>
     */
    private function bundleComponents(CustomSku $customSku): array
    {
        return array_values(array_filter(
            $customSku->getCustomSkuProducts()->toArray(),
            static fn (CustomSkuProduct $component): bool => $component->getProduct() !== null
        ));
    }

    /**
     * Promoted-listing fee for a sale: the product's promo percentage, plus VAT.
     */
    private function promoFee(Product $product, int $salePence): int
    {
        $fee = (int) round($salePence * (float) $product->getPromoPercentage() / 100);

        return $fee + (int) round($fee * self::PROMO_VAT_RATE);
    }

    /**
     * Products and custom SKUs for the given SKUs, keyed by SKU.
     *
     * @param list<string> $skus
     *
     * @return array{array<string, Product>, array<string, CustomSku>}
     */
    private function findSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $sku): bool => $sku !== '')));

        if ($skus === []) {
            return [[], []];
        }

        $products = [];

        foreach ($this->em->getRepository(Product::class)->findBy(['sku' => $skus]) as $product) {
            $products[$product->getSku()] = $product;
        }

        $customSkus = [];

        foreach ($this->em->getRepository(CustomSku::class)->findBy(['sku' => $skus]) as $customSku) {
            $customSkus[$customSku->getSku()] = $customSku;
        }

        return [$products, $customSkus];
    }

    /**
     * Country name for a code, taken from earlier orders (the app has no country list).
     */
    private function countryName(string $code): string
    {
        if ($code === '') {
            return '';
        }

        $name = $this->em->getConnection()->fetchOne(
            "SELECT country_name FROM orders WHERE country = ? AND country_name <> '' ORDER BY id DESC LIMIT 1",
            [$code]
        );

        return is_string($name) ? $name : $code;
    }

    /**
     * Amount the buyer paid for a line's items (after discounts, before postage and tax), in pence.
     *
     * @param array<string, mixed> $line
     */
    private function lineSale(array $line): int
    {
        $amount = $line['discountedLineItemCost'] ?? $line['lineItemCost'] ?? [];

        return is_array($amount) ? Money::toPence($this->string($amount, 'value')) : 0;
    }

    /**
     * The order's total marketplace (final value) fee, in pence.
     *
     * @param array<string, mixed> $ebayOrder
     */
    private function marketplaceFee(array $ebayOrder): int
    {
        $fee = $ebayOrder['totalMarketplaceFee'] ?? [];

        return is_array($fee) ? Money::toPence($this->string($fee, 'value')) : 0;
    }

    /**
     * Whether eBay reports the order as cancelled.
     *
     * @param array<string, mixed> $ebayOrder
     */
    private function isCancelled(array $ebayOrder): bool
    {
        $cancel = $ebayOrder['cancelStatus'] ?? [];

        return is_array($cancel) && ($cancel['cancelState'] ?? '') === 'CANCELED';
    }

    /**
     * Shipping recipient of the first fulfillment instruction.
     *
     * @param array<string, mixed> $ebayOrder
     *
     * @return array<string, mixed>
     */
    private function shipTo(array $ebayOrder): array
    {
        $instructions = $ebayOrder['fulfillmentStartInstructions'][0] ?? [];
        $shipTo = is_array($instructions) ? ($instructions['shippingStep']['shipTo'] ?? []) : [];

        return is_array($shipTo) ? $shipTo : [];
    }

    /**
     * The order's line items.
     *
     * @param array<string, mixed> $ebayOrder
     *
     * @return list<array<string, mixed>>
     */
    private function lineItems(array $ebayOrder): array
    {
        $lines = $ebayOrder['lineItems'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    /**
     * Parse an eBay ISO 8601 timestamp, or null when missing or invalid.
     */
    private function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Read a scalar field as a trimmed string ('' when missing).
     *
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }

    /**
     * Null for empty strings, so blank eBay fields are stored as NULL.
     */
    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
