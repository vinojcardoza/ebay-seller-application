<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\Import\ImportPreview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the import readiness rules of an eBay order preview.
 */
#[CoversClass(ImportPreview::class)]
final class ImportPreviewTest extends TestCase
{
    /**
     * An order whose lines all match can be imported.
     */
    public function testReadyOrder(): void
    {
        $preview = $this->preview([
            ['sku' => 'A', 'title' => 'A', 'quantity' => 1, 'match' => ImportPreview::MATCH_PRODUCT],
        ]);

        $this->assertTrue($preview->canImport());
        $this->assertSame([], $preview->problems());
        $this->assertSame([], $preview->unknownSkus());
    }

    /**
     * Each problem is reported, and any problem blocks the import.
     */
    public function testProblems(): void
    {
        $preview = $this->preview(
            [
                ['sku' => 'A', 'title' => 'Known', 'quantity' => 1, 'match' => ImportPreview::MATCH_CUSTOM_SKU],
                ['sku' => 'ZZZ', 'title' => 'Unknown', 'quantity' => 1, 'match' => null],
                ['sku' => '', 'title' => 'Untracked item', 'quantity' => 1, 'match' => null],
            ],
            alreadyImported: true,
            cancelled: true
        );

        $this->assertFalse($preview->canImport());
        $this->assertSame(['ZZZ', '(no SKU: Untracked item)'], $preview->unknownSkus());
        $this->assertSame([
            'Already imported.',
            'Cancelled on eBay.',
            'Unknown SKU: ZZZ, (no SKU: Untracked item).',
        ], $preview->problems());
    }

    /**
     * An order without line items cannot be imported.
     */
    public function testNoLines(): void
    {
        $this->assertSame(['Has no line items.'], $this->preview([])->problems());
    }

    /**
     * Build a preview with the given lines and flags.
     *
     * @param list<array{sku: string, title: string, quantity: int, match: string|null}> $lines
     */
    private function preview(array $lines, bool $alreadyImported = false, bool $cancelled = false): ImportPreview
    {
        return new ImportPreview('12-1', null, 'buyer', '1.00', 'GBP', $lines, $alreadyImported, $cancelled);
    }
}
