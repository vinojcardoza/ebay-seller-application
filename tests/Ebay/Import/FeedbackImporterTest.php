<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\EbayConfig;
use App\Ebay\EbayException;
use App\Ebay\FeedbackApi;
use App\Ebay\Import\FeedbackImporter;
use App\Entities\Feedback;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for importing buyer feedback from the Feedback API.
 */
#[CoversClass(FeedbackImporter::class)]
final class FeedbackImporterTest extends TestCase
{
    /**
     * Pages the fake API returns, by offset.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private array $pages = [];

    /**
     * Total the fake API reports.
     */
    private int $total = 0;

    /**
     * Offsets requested from the fake API.
     *
     * @var list<int>
     */
    private array $requestedOffsets = [];

    /**
     * Feedback already stored.
     *
     * @var list<Feedback>
     */
    private array $existing = [];

    /**
     * Entities passed to persist().
     *
     * @var list<Feedback>
     */
    private array $persisted = [];

    /**
     * Entity manager mock; tests set expectations on flush().
     */
    private EntityManagerInterface&MockObject $em;

    /**
     * Fake Feedback API.
     */
    private FeedbackApi $api;

    /**
     * Build the fakes.
     */
    protected function setUp(): void
    {
        $this->pages = [];
        $this->total = 0;
        $this->requestedOffsets = [];
        $this->existing = [];
        $this->persisted = [];

        $api = $this->createStub(FeedbackApi::class);
        $api->method('receivedFeedback')->willReturnCallback(function (string $user, int $offset): array {
            $this->assertSame('test-seller', $user);
            $this->requestedOffsets[] = $offset;

            return ['entries' => $this->pages[$offset] ?? [], 'total' => $this->total];
        });
        $this->api = $api;

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            $field = array_key_first($criteria);
            $getter = $field === 'feedbackId' ? 'getFeedbackId' : 'getOrderLineItemId';

            return array_values(array_filter(
                $this->existing,
                static fn (Feedback $f): bool => in_array($f->$getter(), $criteria[$field], true)
            ));
        });

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($repository) {
            $this->assertSame(Feedback::class, $class);

            return $repository;
        });
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertInstanceOf(Feedback::class, $entity);
            $this->persisted[] = $entity;
        });
    }

    /**
     * A feedback entry is stored in the legacy column formats.
     */
    public function testImportMapsFields(): void
    {
        $this->pages[0] = [$this->entry('F1', '155431189738-10083175563407')];
        $this->total = 1;
        $this->em->expects($this->once())->method('flush');

        $result = $this->importer()->import();

        $this->assertSame(['fetched' => 1, 'imported' => 1], $result);
        $feedback = $this->persisted[0];
        $this->assertSame('F1', $feedback->getFeedbackId());
        $this->assertSame('happy_buyer', $feedback->getEbayUserId());
        $this->assertSame('Great seller, fast delivery', $feedback->getCommentText());
        $this->assertSame('Positive', $feedback->getCommentType());
        $this->assertSame(
            '2026-11-08 09:15:00',
            $this->utc($feedback->getCommentTime())
        );
        $this->assertSame('155431189738', $feedback->getItemId());
        $this->assertSame('LCD Display 16x2', $feedback->getItemTitle());
        $this->assertSame('6.99', $feedback->getItemPrice());
        $this->assertSame('155431189738-10083175563407', $feedback->getOrderLineItemId());
        $this->assertSame('10083175563407', $feedback->getTransactionId());
    }

    /**
     * Feedback from sellers, and entries already stored (by feedback ID or line item), are skipped.
     */
    public function testSkipsNonBuyerAndExistingFeedback(): void
    {
        $this->existing = [
            (new Feedback())->setFeedbackId('F-old'),
            (new Feedback())->setFeedbackId('legacy-numeric-id')->setOrderLineItemId('111-222'),
        ];
        $this->pages[0] = [
            $this->entry('F-new', '999-1'),
            $this->entry('F-old', '999-2'),
            $this->entry('F-rest-id', '111-222'),
            $this->entry('F-seller', '999-3', role: 'SELLER'),
        ];
        $this->total = 4;
        $this->em->expects($this->once())->method('flush');

        $result = $this->importer()->import();

        $this->assertSame(['fetched' => 4, 'imported' => 1], $result);
        $this->assertSame(
            ['F-new'],
            array_map(static fn (Feedback $f): ?string => $f->getFeedbackId(), $this->persisted)
        );
    }

    /**
     * Full pages with new entries continue to the next page until the total is reached.
     */
    public function testPaginatesWhileNewFeedbackIsFound(): void
    {
        $this->pages[0] = array_map(fn (int $i): array => $this->entry("A$i", "1-$i"), range(1, 200));
        $this->pages[200] = [$this->entry('B1', '2-1')];
        $this->total = 201;
        $this->em->expects($this->once())->method('flush');

        $result = $this->importer()->import();

        $this->assertSame(['fetched' => 201, 'imported' => 201], $result);
        $this->assertSame([0, 200], $this->requestedOffsets);
    }

    /**
     * The import stops at the first page with nothing new, so repeat runs make one call.
     */
    public function testStopsAtFirstPageWithNothingNew(): void
    {
        $this->pages[0] = array_map(fn (int $i): array => $this->entry("A$i", "1-$i"), range(1, 200));
        $this->existing = array_map(
            static fn (int $i): Feedback => (new Feedback())->setFeedbackId("A$i"),
            range(1, 200)
        );
        $this->total = 5000;
        $this->em->expects($this->never())->method('flush');

        $this->assertSame(['fetched' => 200, 'imported' => 0], $this->importer()->import());
        $this->assertSame([0], $this->requestedOffsets);
    }

    /**
     * The page limit caps how much one run fetches.
     */
    public function testRespectsMaxPages(): void
    {
        foreach ([0, 200, 400] as $offset) {
            $this->pages[$offset] = array_map(
                fn (int $i): array => $this->entry("P$offset-$i", "$offset-$i"),
                range(1, 200)
            );
        }
        $this->total = 600;
        $this->em->expects($this->once())->method('flush');

        $this->importer()->import(maxPages: 2);

        $this->assertSame([0, 200], $this->requestedOffsets);
    }

    /**
     * Without a seller username the import cannot run.
     */
    public function testRequiresSellerUsername(): void
    {
        $this->em->expects($this->never())->method('flush');
        $importer = new FeedbackImporter($this->em, $this->api, new EbayConfig('id', 'secret', 'ru'));

        $this->expectException(EbayException::class);
        $this->expectExceptionMessage('Set EBAY_SELLER_USERNAME to import feedback.');

        $importer->import();
    }

    /**
     * Build the importer for seller "test-seller".
     */
    private function importer(): FeedbackImporter
    {
        $config = new EbayConfig('id', 'secret', 'ru', EbayConfig::PRODUCTION, 'EBAY_GB', 'test-seller');

        return new FeedbackImporter($this->em, $this->api, $config);
    }

    /**
     * A Feedback API entry.
     *
     * @return array<string, mixed>
     */
    private function entry(string $id, string $lineItemId, string $role = 'BUYER'): array
    {
        return [
            'feedbackId' => $id,
            'commentType' => 'POSITIVE',
            'feedbackEnteredDate' => '2026-11-08T09:15:00.000Z',
            'feedbackComment' => ['commentText' => "Great seller,\x07 fast delivery"],
            'providerUserDetail' => ['userId' => 'happy_buyer', 'role' => $role],
            'orderLineItemSummary' => [
                'listingId' => explode('-', $lineItemId)[0],
                'listingTitle' => 'LCD Display 16x2',
                'listingPrice' => ['currency' => 'GBP', 'value' => 6.99],
                'orderLineItemId' => $lineItemId,
            ],
        ];
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
