<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use DateTimeImmutable;

/**
 * Summary of an eBay order and whether it can be imported, for listing on the eBay page.
 */
final readonly class ImportPreview
{
    /**
     * Line matched a product SKU.
     */
    public const MATCH_PRODUCT = 'product';

    /**
     * Line matched a custom SKU (bundle).
     */
    public const MATCH_CUSTOM_SKU = 'custom_sku';

    /**
     * Create the preview.
     *
     * @param list<array{sku: string, title: string, quantity: int, match: string|null}> $lines
     *     One entry per eBay line item; `match` is MATCH_PRODUCT, MATCH_CUSTOM_SKU or null.
     */
    public function __construct(
        public string $orderId,
        public ?DateTimeImmutable $created,
        public string $buyer,
        public string $total,
        public string $currency,
        public array $lines,
        public bool $alreadyImported,
        public bool $cancelled,
    ) {
    }

    /**
     * SKUs on the order that match neither a product nor a custom SKU.
     *
     * @return list<string>
     */
    public function unknownSkus(): array
    {
        $unknown = [];

        foreach ($this->lines as $line) {
            if ($line['match'] === null) {
                $unknown[] = $line['sku'] !== '' ? $line['sku'] : '(no SKU: ' . $line['title'] . ')';
            }
        }

        return $unknown;
    }

    /**
     * Reasons the order cannot be imported; empty when it can.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->alreadyImported) {
            $problems[] = 'Already imported.';
        }

        if ($this->cancelled) {
            $problems[] = 'Cancelled on eBay.';
        }

        if ($this->lines === []) {
            $problems[] = 'Has no line items.';
        }

        $unknown = $this->unknownSkus();

        if ($unknown !== []) {
            $problems[] = 'Unknown SKU: ' . implode(', ', $unknown) . '.';
        }

        return $problems;
    }

    /**
     * Whether the order can be imported.
     */
    public function canImport(): bool
    {
        return $this->problems() === [];
    }
}
