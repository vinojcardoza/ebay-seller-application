<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\FeedbackController;
use App\Ebay\EbayApiException;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\Import\FeedbackImporter;
use App\Entities\Feedback;
use App\Framework\Template\PlatesRender;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\FrozenClock;

/**
 * Tests for every FeedbackController action, with real views, a fake
 * repository and DBAL connection, and a fake eBay importer.
 */
#[CoversClass(FeedbackController::class)]
final class FeedbackControllerTest extends TestCase
{
    /**
     * Feedback counts by comment type, as the summary query returns them.
     *
     * @var array<string, int>
     */
    private array $typeCounts = ['Positive' => 95, 'Neutral' => 3, 'Negative' => 2];

    /**
     * MAX(comment_time) returned by the database, or null when there is no feedback.
     */
    private ?string $latest = '2026-02-15 19:20:09';

    /**
     * Daily counts keyed by Y-m-d.
     *
     * @var array<string, int>
     */
    private array $daily = ['2026-02-15' => 53, '2026-02-01' => 7, '2025-12-18' => 12];

    /**
     * Monthly counts keyed by Y-m.
     *
     * @var array<string, int>
     */
    private array $monthly = ['2026-02' => 520, '2025-12' => 200, '2025-03' => 250];

    /**
     * Parameters passed to the daily and monthly queries, keyed 'daily'/'monthly'.
     *
     * @var array<string, list<mixed>>
     */
    private array $chartParams = [];

    /**
     * Feedback repository fake (a stub until a test asks for a mock via repositoryMock()).
     *
     * @var EntityRepository<Feedback>&Stub
     */
    private EntityRepository&Stub $repository;

    /**
     * Feedback importer fake.
     */
    private FeedbackImporter&Stub $importer;

    /**
     * Seed two feedback entries on one page of 100 matching entries.
     */
    protected function setUp(): void
    {
        $this->chartParams = [];
        $this->repository = $this->createStub(EntityRepository::class);
        $this->repository->method('count')->willReturn(100);
        $this->repository->method('findBy')->willReturn([
            $this->feedback('anthony9116', 'Positive', 'Works well', '2026-02-15 19:20'),
            $this->feedback('grumpy_<b>', 'Negative', 'Arrived <broken>', '2026-02-14 08:00'),
        ]);
        $this->importer = $this->createStub(FeedbackImporter::class);
    }

    // ---------- index: summary and list ----------

    /**
     * The summary shows totals by type and the positive share.
     */
    public function testIndexShowsSummary(): void
    {
        $body = $this->body($this->index());

        $expected = ['100' => 'Total feedback', '95' => 'Positive (95.0%)', '3' => 'Neutral', '2' => 'Negative'];

        foreach ($expected as $n => $label) {
            $this->assertMatchesRegularExpression(
                '#kpi-value">' . $n . '</p>\s*<p class="kpi-label">' . preg_quote($label, '#') . '<#',
                $body
            );
        }
    }

    /**
     * Each entry shows date, buyer (linked to their feedback), type badge, comment and item, escaped.
     */
    public function testIndexListsFeedback(): void
    {
        $body = $this->body($this->index());

        $this->assertStringContainsString('100 entries', $body);
        $this->assertStringContainsString('15 Feb 2026 19:20', $body);
        $this->assertStringContainsString('href="/feedbacks?user=anthony9116"', $body);
        $this->assertMatchesRegularExpression('#tint-success">\s*Positive\s*</span>#', $body);
        $this->assertStringContainsString('tint-danger', $body);
        $this->assertStringContainsString('Works well', $body);
        $this->assertStringContainsString('Arrived &lt;broken&gt;', $body);
        $this->assertStringContainsString('grumpy_&lt;b&gt;', $body);
        $this->assertStringNotContainsString('<broken>', $body);
        $this->assertStringContainsString('Item for anthony9116', $body);
        $this->assertStringContainsString('£6.99', $body);
    }

    /**
     * Filters and page become repository criteria, newest first, 50 per page.
     */
    public function testIndexFiltersAndPaginates(): void
    {
        $criteria = ['commentType' => 'Negative', 'ebayUserId' => 'bob'];
        $repository = $this->repositoryMock();
        $repository->expects($this->once())->method('count')->with($criteria)->willReturn(120);
        $repository->expects($this->once())->method('findBy')
            ->with($criteria, ['commentTime' => 'DESC', 'id' => 'DESC'], 50, 50)
            ->willReturn([]);

        $body = $this->body($this->index(['type' => 'Negative', 'user' => ' bob ', 'page' => '2']));

        $this->assertStringContainsString('No feedback matches these filters.', $body);
        $this->assertStringContainsString('href="/feedbacks"', $body, 'Clear link shown when filtered.');
        $this->assertMatchesRegularExpression('#<option value="Negative"\s+selected>#', $body);
        $this->assertStringContainsString('value="bob"', $body);
    }

    /**
     * Unknown types are ignored, page numbers are clamped, and the buyer filter is capped at 100 characters.
     *
     * @return array<string, array{array<string, string>, array<string, string>, int}>
     */
    public static function sanitisedQueryProvider(): array
    {
        return [
            'unknown type' => [['type' => 'Bogus'], [], 0],
            'page past the end' => [['page' => '999'], [], 50],
            'page below 1' => [['page' => '-3'], [], 0],
            'non-numeric page' => [['page' => 'two'], [], 0],
            'long user' => [['user' => str_repeat('x', 150)], ['ebayUserId' => str_repeat('x', 100)], 0],
        ];
    }

    /**
     * Query input is sanitised before reaching the repository.
     *
     * @param array<string, string> $query
     * @param array<string, string> $criteria
     */
    #[DataProvider('sanitisedQueryProvider')]
    public function testIndexSanitisesQuery(array $query, array $criteria, int $offset): void
    {
        $repository = $this->repositoryMock();
        $repository->expects($this->once())->method('count')->with($criteria)->willReturn(100);
        $repository->expects($this->once())->method('findBy')
            ->with($criteria, $this->anything(), 50, $offset)
            ->willReturn([]);

        $this->assertSame(200, $this->index($query)->getStatusCode());
    }

    /**
     * Pagination shows the current page and keeps the filters in its links.
     */
    public function testIndexPaginationLinks(): void
    {
        $body = $this->body($this->index(['type' => 'Positive', 'page' => '2']));

        $this->assertStringContainsString('Page 2 of 2', $body);
        $this->assertStringContainsString('href="/feedbacks?type=Positive"', $body);
        $this->assertMatchesRegularExpression(
            '#page-item active">\s*<a class="page-link" href="/feedbacks\?type=Positive&amp;page=2"#',
            $body
        );
    }

    // ---------- index: charts ----------

    /**
     * The daily chart covers the 60 days ending on the latest feedback, not today.
     */
    public function testDailyChartEndsAtLatestFeedback(): void
    {
        $body = $this->body($this->index());
        $chart = $this->chart($body, 'feedback-daily');

        $this->assertSame(['2025-12-18', '2026-02-16'], $this->chartParams['daily']);
        $this->assertStringContainsString('18 Dec – 15 Feb 2026 · 72 total', $chart);
        $this->assertSame(60, substr_count($chart, 'class="column-chart-bar"'));
        $this->assertStringContainsString('aria-label="Sun 15 Feb 2026: 53 feedback"', $chart);
        $this->assertStringContainsString('aria-label="Thu 18 Dec 2025: 12 feedback"', $chart);
        $this->assertStringNotContainsString(
            'aria-label="Mon 16 Feb 2026',
            $chart,
            'Chart must not go past the latest feedback.'
        );
        $this->assertSame(['0', '20', '40', '60'], $this->tickLabels($chart));
        $this->assertSame(3, substr_count($chart, 'class="column-chart-fill"'), 'Zero days draw no bar.');
    }

    /**
     * The monthly chart covers the 12 months ending with the latest feedback's month.
     */
    public function testMonthlyChart(): void
    {
        $chart = $this->chart($this->body($this->index()), 'feedback-monthly');

        $this->assertSame(['2025-03-01', '2026-03-01'], $this->chartParams['monthly']);
        $this->assertStringContainsString('Mar 2025 – Feb 2026 · 970 total', $chart);
        $this->assertSame(12, substr_count($chart, 'class="column-chart-bar"'));
        $this->assertStringContainsString('aria-label="February 2026: 520 feedback"', $chart);
        $this->assertSame(['0', '200', '400', '600'], $this->tickLabels($chart));
        $this->assertStringContainsString('<td>March 2025</td>', $chart, 'Table view lists every month.');
    }

    /**
     * Peak values and the clean y-axis ticks chosen for them.
     *
     * @return array<string, array{int, list<string>}>
     */
    public static function tickProvider(): array
    {
        return [
            'all zero' => [0, ['0', '1']],
            'small' => [3, ['0', '1', '2', '3']],
            'exact step' => [8, ['0', '2', '4', '6', '8']],
            'ten' => [10, ['0', '5', '10']],
            'thousands' => [4321, ['0', '2,000', '4,000', '6,000']],
        ];
    }

    /**
     * The y-axis uses 1, 2 or 5 × 10ⁿ steps up to the first step at or above the peak.
     *
     * @param list<string> $ticks
     */
    #[DataProvider('tickProvider')]
    public function testTicks(int $peak, array $ticks): void
    {
        $this->daily = ['2026-02-15' => $peak];

        $chart = $this->chart($this->body($this->index()), 'feedback-daily');

        $this->assertSame($ticks, $this->tickLabels($chart));
    }

    /**
     * Feedback dated in the future is charted up to today only.
     */
    public function testChartsNeverEndAfterToday(): void
    {
        $this->latest = '2027-01-01 00:00:00';

        $this->index();

        $this->assertSame(['2026-09-12', '2026-11-11'], $this->chartParams['daily']);
    }

    /**
     * With no feedback at all there are no charts and an empty list.
     */
    public function testIndexWithNoFeedback(): void
    {
        $this->latest = null;
        $this->typeCounts = [];
        $repository = $this->repositoryMock();
        $repository->expects($this->once())->method('count')->willReturn(0);
        $repository->expects($this->once())->method('findBy')->willReturn([]);

        $body = $this->body($this->index());

        $this->assertStringNotContainsString('column-chart', $body);
        $this->assertStringContainsString('No feedback yet.', $body);
        $this->assertMatchesRegularExpression('#kpi-value">0</p>\s*<p class="kpi-label">Positive<#', $body);
        $this->assertSame([], $this->chartParams);
    }

    // ---------- index: messages ----------

    /**
     * Redirect codes show fixed messages built only from integers.
     */
    public function testIndexMessages(): void
    {
        $this->assertStringContainsString(
            'Fetched 200 feedback entries from eBay; 7 new imported.',
            $this->body($this->index(['done' => 'imported', 'fetched' => '200', 'imported' => '7']))
        );
        $this->assertStringContainsString(
            'Fetched 1 feedback entry from eBay; 0 new imported.',
            $this->body($this->index(['done' => 'imported', 'fetched' => '1', 'imported' => '<x>']))
        );
        $this->assertStringContainsString(
            'Connect your eBay account on the eBay page before importing feedback.',
            $this->body($this->index(['error' => 'not-connected']))
        );
        $this->assertStringNotContainsString('alert-danger', $this->body($this->index(['error' => '<script>'])));
    }

    // ---------- import ----------

    /**
     * Importing redirects back with the counts.
     */
    public function testImport(): void
    {
        $this->importer->method('import')->willReturn(['fetched' => 200, 'imported' => 7]);

        $response = $this->controller()->import($this->request('POST'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/feedbacks?done=imported&fetched=200&imported=7', $response->getHeaderLine('Location'));
    }

    /**
     * Without an eBay connection the user is asked to connect.
     */
    public function testImportNotConnected(): void
    {
        $this->importer->method('import')->willThrowException(new EbayNotConnectedException('x'));

        $response = $this->controller()->import($this->request('POST'));

        $this->assertSame('/feedbacks?error=not-connected', $response->getHeaderLine('Location'));
    }

    /**
     * An eBay error shows the page with the message and a 502.
     */
    public function testImportApiError(): void
    {
        $this->importer->method('import')->willThrowException(new EbayApiException('eBay is down', 503));

        $response = $this->controller()->import($this->request('POST'));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('eBay is down', $this->body($response));
    }

    // ---------- helpers ----------

    /**
     * Call index() with the given query.
     *
     * @param array<string, string> $query
     */
    private function index(array $query = []): ResponseInterface
    {
        return $this->controller()->index($this->request('GET', $query));
    }

    /**
     * Build the controller with a clock at 2026-11-10; the entity manager fails the test on any write.
     */
    private function controller(): FeedbackController
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(fn (): ?string => $this->latest);
        $connection->method('fetchAllKeyValue')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (str_contains($sql, 'GROUP BY comment_type')) {
                    return $this->typeCounts;
                }

                $key = str_contains($sql, 'DATE_FORMAT') ? 'monthly' : 'daily';
                $this->chartParams[$key] = $params;

                return $key === 'monthly' ? $this->monthly : $this->daily;
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(function (string $class): EntityRepository {
            $this->assertSame(Feedback::class, $class);

            return $this->repository;
        });
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $em->expects($this->never())->method('remove');

        $factory = new Psr17Factory();

        return new FeedbackController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            $this->importer,
            new FrozenClock('2026-11-10 12:00:00 UTC')
        );
    }

    /**
     * Replace the repository stub with a mock so the test can set expectations.
     *
     * @return EntityRepository<Feedback>&MockObject
     */
    private function repositoryMock(): EntityRepository&MockObject
    {
        $mock = $this->createMock(EntityRepository::class);
        $this->repository = $mock;

        return $mock;
    }

    /**
     * Build a feedback entry.
     */
    private function feedback(string $user, string $type, string $comment, string $time): Feedback
    {
        return (new Feedback())
            ->setFeedbackId('F-' . $user)
            ->setEbayUserId($user)
            ->setCommentType($type)
            ->setCommentText($comment)
            ->setCommentTime(new DateTime($time))
            ->setItemTitle('Item for ' . $user)
            ->setItemPrice('6.99');
    }

    /**
     * Build a request with query parameters.
     *
     * @param array<string, string> $query
     */
    private function request(string $method, array $query = []): ServerRequest
    {
        return (new ServerRequest($method, '/feedbacks'))->withQueryParams($query);
    }

    /**
     * The HTML of one chart figure, found by its ID prefix.
     */
    private function chart(string $body, string $id): string
    {
        $start = strpos($body, 'aria-labelledby="' . $id . '-title"');
        $this->assertNotFalse($start, "Chart $id not found.");
        $end = strpos($body, '</figure>', $start);
        $this->assertNotFalse($end);

        return substr($body, $start, $end - $start);
    }

    /**
     * Y-axis tick labels of a chart, bottom to top.
     *
     * @return list<string>
     */
    private function tickLabels(string $chart): array
    {
        preg_match_all('#column-chart-gridline"[^>]*>\s*<span>([^<]+)</span>#', $chart, $matches);

        return $matches[1];
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
