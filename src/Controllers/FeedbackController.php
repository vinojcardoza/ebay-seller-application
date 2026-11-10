<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Ebay\EbayException;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\Import\FeedbackImporter;
use App\Entities\Feedback;
use App\Framework\Clock\ClockInterface;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Buyer feedback: summary figures, daily and monthly charts, a filterable
 * list, and importing new feedback from eBay.
 *
 * Replaces the legacy FeedbacksController, which listed every entry on one page.
 */
class FeedbackController extends ControllerAbstract
{
    /**
     * Feedback entries per page.
     */
    public const PER_PAGE = 50;

    /**
     * Comment types that can be filtered on, as stored in `feedbacks.comment_type`.
     */
    public const TYPES = ['Positive', 'Neutral', 'Negative'];

    /**
     * Days shown in the daily chart (legacy: 60).
     */
    private const DAILY_DAYS = 60;

    /**
     * Months shown in the monthly chart.
     */
    private const MONTHS = 12;

    /**
     * Messages for `?error=` codes set by redirects.
     */
    private const ERRORS = [
        'not-connected' => 'Connect your eBay account on the eBay page before importing feedback.',
    ];

    /**
     * Create the controller.
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly FeedbackImporter $importer,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * Show the feedback summary, charts and the filtered, paginated list.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request);
    }

    /**
     * Import new buyer feedback from eBay and return to the list.
     */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->importer->import();
        } catch (EbayNotConnectedException) {
            return $this->redirect('/feedbacks?error=not-connected');
        } catch (EbayException $e) {
            return $this->renderIndex($request, $e->getMessage(), 502);
        }

        return $this->redirect('/feedbacks?' . http_build_query([
            'done' => 'imported',
            'fetched' => $result['fetched'],
            'imported' => $result['imported'],
        ]));
    }

    /**
     * Render the feedback page.
     */
    private function renderIndex(
        ServerRequestInterface $request,
        ?string $error = null,
        int $status = 200
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $type = in_array($query['type'] ?? null, self::TYPES, true) ? $query['type'] : '';
        $user = is_string($query['user'] ?? null) ? mb_substr(trim($query['user']), 0, 100) : '';

        $criteria = array_filter(
            ['commentType' => $type, 'ebayUserId' => $user],
            static fn (string $value): bool => $value !== ''
        );
        $repository = $this->em->getRepository(Feedback::class);
        $total = $repository->count($criteria);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1));

        $feedbacks = $repository->findBy(
            $criteria,
            ['commentTime' => 'DESC', 'id' => 'DESC'],
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE
        );

        $latest = $this->latestFeedbackDate();

        return $this->render('feedbacks/index', [
            'feedbacks' => $feedbacks,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'type' => $type,
            'user' => $user,
            'types' => self::TYPES,
            'summary' => $this->summary(),
            'daily' => $latest !== null ? $this->dailySeries($latest) : null,
            'monthly' => $latest !== null ? $this->monthlySeries($latest) : null,
            'notice' => $this->notice($query),
            'error' => $error ?? (is_string($query['error'] ?? null) ? self::ERRORS[$query['error']] ?? null : null),
        ], $status);
    }

    /**
     * Counts of all feedback by type, and the positive share.
     *
     * @return array{total: int, positive: int, neutral: int, negative: int, positiveShare: float|null}
     */
    private function summary(): array
    {
        $counts = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT comment_type, COUNT(*) FROM feedbacks GROUP BY comment_type'
        );
        $count = static fn (string $type): int => (int) ($counts[$type] ?? 0);
        $total = array_sum(array_map('intval', $counts));

        return [
            'total' => $total,
            'positive' => $count('Positive'),
            'neutral' => $count('Neutral'),
            'negative' => $count('Negative'),
            'positiveShare' => $total > 0 ? $count('Positive') / $total * 100 : null,
        ];
    }

    /**
     * Date of the most recent feedback, or null when there is none. Charts end
     * here rather than today, so they stay useful when imports are behind.
     */
    private function latestFeedbackDate(): ?DateTimeImmutable
    {
        $latest = $this->em->getConnection()->fetchOne('SELECT MAX(comment_time) FROM feedbacks');

        if (!is_string($latest) || $latest === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($latest);
        } catch (Exception) {
            return null;
        }

        $today = $this->clock->now();

        return ($date > $today ? $today : $date)->setTime(0, 0);
    }

    /**
     * Feedback per day for the DAILY_DAYS days ending on the given date.
     *
     * @return array{points: list<array{label: string, axis: string, value: int}>, ticks: list<int>, max: int,
     *     from: DateTimeImmutable, to: DateTimeImmutable, total: int}
     */
    private function dailySeries(DateTimeImmutable $end): array
    {
        $start = $end->modify('-' . (self::DAILY_DAYS - 1) . ' days');
        $counts = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT DATE(comment_time), COUNT(*) FROM feedbacks WHERE comment_time >= ? AND comment_time < ?'
            . ' GROUP BY DATE(comment_time)',
            [$start->format('Y-m-d'), $end->modify('+1 day')->format('Y-m-d')]
        );

        $points = [];

        for ($day = $start, $i = 0; $day <= $end; $day = $day->modify('+1 day'), $i++) {
            $points[] = [
                'label' => $day->format('D j M Y'),
                'axis' => $i % 14 === 0 ? $day->format('j M') : '',
                'value' => (int) ($counts[$day->format('Y-m-d')] ?? 0),
            ];
        }

        return $this->series($points, $start, $end);
    }

    /**
     * Feedback per month for the MONTHS months ending with the given date's month.
     *
     * @return array{points: list<array{label: string, axis: string, value: int}>, ticks: list<int>, max: int,
     *     from: DateTimeImmutable, to: DateTimeImmutable, total: int}
     */
    private function monthlySeries(DateTimeImmutable $end): array
    {
        $last = $end->modify('first day of this month');
        $start = $last->modify('-' . (self::MONTHS - 1) . ' months');
        $counts = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT DATE_FORMAT(comment_time, '%Y-%m'), COUNT(*) FROM feedbacks"
            . " WHERE comment_time >= ? AND comment_time < ? GROUP BY DATE_FORMAT(comment_time, '%Y-%m')",
            [$start->format('Y-m-d'), $last->modify('+1 month')->format('Y-m-d')]
        );

        $points = [];

        for ($month = $start; $month <= $last; $month = $month->modify('+1 month')) {
            $points[] = [
                'label' => $month->format('F Y'),
                'axis' => $month->format('M'),
                'value' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $this->series($points, $start, $last->modify('last day of this month'));
    }

    /**
     * Add a clean y-axis scale (0 and 4 equal steps of 1, 2 or 5 × 10ⁿ) to chart points.
     *
     * @param list<array{label: string, axis: string, value: int}> $points
     *
     * @return array{points: list<array{label: string, axis: string, value: int}>, ticks: list<int>, max: int,
     *     from: DateTimeImmutable, to: DateTimeImmutable, total: int}
     */
    private function series(array $points, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $values = array_column($points, 'value');
        $peak = max([0, ...$values]);
        $rough = max(1, $peak / 4);
        $magnitude = 10 ** (int) floor(log10($rough));
        $step = (int) (array_values(array_filter(
            [1, 2, 5, 10],
            static fn (int $n): bool => $n * $magnitude >= $rough
        ))[0] * $magnitude);
        $max = $step * max(1, (int) ceil($peak / $step));

        return [
            'points' => $points,
            'ticks' => range(0, $max, $step),
            'max' => $max,
            'from' => $from,
            'to' => $to,
            'total' => array_sum($values),
        ];
    }

    /**
     * Success message for a `?done=` redirect, built only from integers.
     *
     * @param array<string, mixed> $query
     */
    private function notice(array $query): ?string
    {
        if (($query['done'] ?? null) !== 'imported') {
            return null;
        }

        $fetched = max(0, (int) ($query['fetched'] ?? 0));

        return sprintf(
            'Fetched %d feedback entr%s from eBay; %d new imported.',
            $fetched,
            $fetched === 1 ? 'y' : 'ies',
            max(0, (int) ($query['imported'] ?? 0))
        );
    }
}
