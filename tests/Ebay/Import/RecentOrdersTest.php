<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\EbayConfig;
use App\Ebay\EbayOAuth;
use App\Ebay\FulfillmentApi;
use App\Ebay\Import\ImportPreview;
use App\Ebay\Import\OrderImporter;
use App\Ebay\Import\RecentOrders;
use App\Ebay\Token\OAuthToken;
use App\Entities\Order;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

/**
 * Tests for listing recent eBay orders that are not imported yet, and importing one.
 */
#[CoversClass(RecentOrders::class)]
final class RecentOrdersTest extends TestCase
{
    /**
     * Connected only when eBay is configured and tokens are stored.
     */
    public function testIsConnected(): void
    {
        $connected = $this->createStub(EbayOAuth::class);
        $connected->method('connection')->willReturn(new OAuthToken(
            'a',
            new DateTimeImmutable('+1 hour'),
            'r',
            new DateTimeImmutable('+1 year')
        ));
        $disconnected = $this->createStub(EbayOAuth::class);
        $disconnected->method('connection')->willReturn(null);

        $this->assertTrue($this->service(oauth: $connected)->isConnected());
        $this->assertFalse($this->service(oauth: $disconnected)->isConnected());
        $this->assertFalse($this->service(oauth: $connected, configured: false)->isConnected());
    }

    /**
     * Orders created in the period are previewed; already imported ones are left out
     * and the rest are sorted newest first.
     */
    public function testNotImported(): void
    {
        $fulfillment = $this->createMock(FulfillmentApi::class);
        $fulfillment->expects($this->once())->method('ordersCreatedSince')
            ->with($this->callback(static fn (DateTimeInterface $from): bool
                => $from->format('Y-m-d H:i') === '2026-11-03 12:00'))
            ->willReturn([['orderId' => 'raw']]);
        $importer = $this->createMock(OrderImporter::class);
        $importer->expects($this->once())->method('preview')->with([['orderId' => 'raw']])->willReturn([
            $this->preview('12-old', '2026-11-04'),
            $this->preview('12-done', '2026-11-08', alreadyImported: true),
            $this->preview('12-new', '2026-11-09'),
        ]);

        $previews = $this->service(fulfillment: $fulfillment, importer: $importer)->notImported(7);

        $this->assertSame(
            ['12-new', '12-old'],
            array_map(static fn (ImportPreview $preview): string => $preview->orderId, $previews)
        );
    }

    /**
     * A look-back period outside the allowed options falls back to the default.
     */
    public function testNotImportedUsesDefaultForUnknownDays(): void
    {
        $fulfillment = $this->createMock(FulfillmentApi::class);
        $fulfillment->expects($this->once())->method('ordersCreatedSince')
            ->with($this->callback(static fn (DateTimeInterface $from): bool
                => $from->format('Y-m-d') === '2026-11-07'))
            ->willReturn([]);

        $this->assertSame([], $this->service(fulfillment: $fulfillment)->notImported(365));
    }

    /**
     * Importing fetches the full order from eBay and passes it to the importer with the promoted flag.
     */
    public function testImport(): void
    {
        $order = (new Order())->setOrderReference('12-1');
        $fulfillment = $this->createMock(FulfillmentApi::class);
        $fulfillment->expects($this->once())->method('order')->with('12-1')->willReturn(['orderId' => '12-1']);
        $importer = $this->createMock(OrderImporter::class);
        $importer->expects($this->once())->method('import')->with(['orderId' => '12-1'], true)->willReturn($order);

        $this->assertSame($order, $this->service(fulfillment: $fulfillment, importer: $importer)->import('12-1', true));
    }

    /**
     * Submitted look-back values and the period used.
     *
     * @return array<string, array{mixed, int}>
     */
    public static function daysProvider(): array
    {
        return [
            'allowed option' => ['14', 14],
            'allowed int' => [30, 30],
            'not an option' => ['5', RecentOrders::DEFAULT_DAYS],
            'not numeric' => ['week', RecentOrders::DEFAULT_DAYS],
            'missing' => [null, RecentOrders::DEFAULT_DAYS],
        ];
    }

    /**
     * Look-back days are limited to the allowed options.
     */
    #[DataProvider('daysProvider')]
    public function testDays(mixed $value, int $days): void
    {
        $this->assertSame($days, RecentOrders::days($value));
    }

    /**
     * Build the service with the given fakes (stubs when not given).
     */
    private function service(
        ?EbayOAuth $oauth = null,
        ?FulfillmentApi $fulfillment = null,
        ?OrderImporter $importer = null,
        bool $configured = true
    ): RecentOrders {
        return new RecentOrders(
            $configured ? new EbayConfig('id', 'secret', 'ru') : new EbayConfig('', '', ''),
            $oauth ?? $this->createStub(EbayOAuth::class),
            $fulfillment ?? $this->createStub(FulfillmentApi::class),
            $importer ?? $this->createStub(OrderImporter::class),
            new FrozenClock('2026-11-10 12:00:00 UTC'),
        );
    }

    /**
     * Build an importable order preview created on the given date.
     */
    private function preview(string $orderId, string $created, bool $alreadyImported = false): ImportPreview
    {
        return new ImportPreview(
            $orderId,
            new DateTimeImmutable("$created 10:00:00 UTC"),
            'buyer_1',
            '6.99',
            'GBP',
            [['sku' => 'LCD-1602', 'title' => 'LCD Display', 'quantity' => 1, 'match' => ImportPreview::MATCH_PRODUCT]],
            $alreadyImported,
            false,
        );
    }
}
