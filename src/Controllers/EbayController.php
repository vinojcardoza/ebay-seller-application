<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Ebay\EbayApiException;
use App\Ebay\EbayConfig;
use App\Ebay\EbayException;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\EbayOAuth;
use App\Ebay\FulfillmentApi;
use App\Ebay\Import\FeedbackImporter;
use App\Ebay\Import\ImportPreview;
use App\Ebay\Import\OrderImportException;
use App\Ebay\Import\OrderImporter;
use App\Ebay\Import\TrackingSynchroniser;
use App\Framework\Clock\ClockInterface;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * eBay integration page: connect the seller's account (OAuth), import recent
 * orders, sync shipment tracking and import feedback via eBay's REST APIs.
 */
class EbayController extends ControllerAbstract
{
    /**
     * Cookie holding the OAuth `state` value between /ebay/connect and the callback.
     */
    private const STATE_COOKIE = 'ebay_oauth_state';

    /**
     * Allowed look-back periods (days) for the recent orders list.
     */
    public const DAY_OPTIONS = [1, 3, 7, 14, 30, 90];

    /**
     * Default look-back period, matching the legacy "recent orders" (3 days).
     */
    private const DEFAULT_DAYS = 3;

    /**
     * Messages for `?error=` codes set by redirects.
     */
    private const ERRORS = [
        'config' => 'eBay is not configured. Set EBAY_CLIENT_ID, EBAY_CLIENT_SECRET and EBAY_RU_NAME.',
        'denied' => 'eBay sign-in was cancelled or refused.',
        'state' => 'The eBay sign-in could not be verified. Please connect again.',
        'exchange' => 'eBay rejected the authorization code. Please connect again.',
        'code' => 'Enter the authorization code, or the URL eBay redirected you to.',
        'not-connected' => 'Connect your eBay account first.',
    ];

    /**
     * Create the controller.
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly EbayConfig $config,
        private readonly EbayOAuth $oauth,
        private readonly FulfillmentApi $fulfillment,
        private readonly OrderImporter $importer,
        private readonly TrackingSynchroniser $tracking,
        private readonly FeedbackImporter $feedback,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * Show connection status, actions and recent eBay orders.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request);
    }

    /**
     * Start the OAuth flow: remember a random state and send the seller to eBay's consent page.
     */
    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->config->isConfigured()) {
            return $this->redirect('/ebay?error=config');
        }

        $state = bin2hex(random_bytes(16));

        return $this->redirect($this->oauth->authorizationUrl($state))
            ->withAddedHeader('Set-Cookie', $this->stateCookie($request, $state, 600));
    }

    /**
     * OAuth redirect target: verify the state, exchange the code and store the tokens.
     */
    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $cookie = $request->getCookieParams()[self::STATE_COOKIE] ?? '';
        $state = $query['state'] ?? '';
        $code = $query['code'] ?? '';

        if (isset($query['error'])) {
            $target = '/ebay?error=denied';
        } elseif (!is_string($cookie) || !is_string($state) || $cookie === '' || !hash_equals($cookie, $state)) {
            $target = '/ebay?error=state';
        } elseif (!is_string($code) || $code === '') {
            $target = '/ebay?error=code';
        } else {
            $target = $this->exchange($code);
        }

        return $this->redirect($target)->withAddedHeader('Set-Cookie', $this->stateCookie($request, '', 0));
    }

    /**
     * Connect with an authorization code pasted by hand, for setups where eBay cannot
     * redirect back to the app (eBay only redirects to HTTPS URLs).
     */
    public function submitCode(ServerRequestInterface $request): ResponseInterface
    {
        $code = $this->extractCode($this->field($request, 'code'));

        return $this->redirect($code === '' ? '/ebay?error=code' : $this->exchange($code));
    }

    /**
     * Forget the stored eBay tokens.
     */
    public function disconnect(): ResponseInterface
    {
        $this->oauth->disconnect();

        return $this->redirect('/ebay?done=disconnected');
    }

    /**
     * Import one eBay order (with its items) into the orders table.
     *
     * @param array<string, string> $args Route placeholders, including `orderId`.
     */
    public function importOrder(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $orderId = $args['orderId'] ?? '';
        $days = $this->days($this->field($request, 'days'));

        try {
            $order = $this->importer->import(
                $this->fulfillment->order($orderId),
                $this->field($request, 'promoted') === '1'
            );
        } catch (EbayNotConnectedException) {
            return $this->redirect('/ebay?error=not-connected');
        } catch (OrderImportException $e) {
            return $this->renderIndex($request, $e->getMessage(), 422, $days);
        } catch (EbayException $e) {
            return $this->renderIndex($request, $e->getMessage(), 502, $days);
        }

        return $this->redirect('/ebay?' . http_build_query([
            'done' => 'imported',
            'ref' => $order->getOrderReference(),
            'days' => $days,
        ]));
    }

    /**
     * Fetch tracking details from eBay for recent orders that have none.
     */
    public function syncTracking(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->tracking->sync();
        } catch (EbayNotConnectedException) {
            return $this->redirect('/ebay?error=not-connected');
        } catch (EbayException $e) {
            return $this->renderIndex($request, $e->getMessage(), 502);
        }

        return $this->redirect('/ebay?' . http_build_query([
            'done' => 'tracking',
            'checked' => $result['checked'],
            'updated' => $result['updated'],
            'failed' => count($result['failed']),
        ]));
    }

    /**
     * Import new feedback received from buyers.
     */
    public function importFeedback(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->feedback->import();
        } catch (EbayNotConnectedException) {
            return $this->redirect('/ebay?error=not-connected');
        } catch (EbayException $e) {
            return $this->renderIndex($request, $e->getMessage(), 502);
        }

        return $this->redirect('/ebay?' . http_build_query([
            'done' => 'feedback',
            'fetched' => $result['fetched'],
            'imported' => $result['imported'],
        ]));
    }

    /**
     * Render the eBay page, loading recent orders when connected.
     */
    private function renderIndex(
        ServerRequestInterface $request,
        ?string $error = null,
        int $status = 200,
        ?int $days = null
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $days ??= $this->days($query['days'] ?? null);
        $connection = $this->config->isConfigured() ? $this->oauth->connection() : null;
        $previews = [];
        $ordersError = null;

        if ($connection !== null) {
            try {
                $previews = $this->importer->preview(
                    $this->fulfillment->ordersCreatedSince($this->clock->now()->modify("-$days days"))
                );
                usort($previews, static fn (ImportPreview $a, ImportPreview $b): int => $b->created <=> $a->created);
            } catch (EbayNotConnectedException) {
                $connection = null;
            } catch (EbayApiException $e) {
                $ordersError = $e->getMessage();
            }
        }

        return $this->render('ebay/index', [
            'config' => $this->config,
            'connection' => $connection,
            'previews' => $previews,
            'days' => $days,
            'dayOptions' => self::DAY_OPTIONS,
            'notice' => $this->notice($query),
            'error' => $error ?? (is_string($query['error'] ?? null) ? self::ERRORS[$query['error']] ?? null : null),
            'ordersError' => $ordersError,
        ], $status);
    }

    /**
     * Exchange an authorization code and return where to redirect.
     */
    private function exchange(string $code): string
    {
        try {
            $this->oauth->exchangeCode($code);
        } catch (EbayException) {
            return '/ebay?error=exchange';
        }

        return '/ebay?done=connected';
    }

    /**
     * Success message for a `?done=` redirect, built only from known codes and integers.
     *
     * @param array<string, mixed> $query
     */
    private function notice(array $query): ?string
    {
        $int = static fn (string $key): int => max(0, (int) ($query[$key] ?? 0));
        $ref = is_string($query['ref'] ?? null) && preg_match('/^[0-9A-Za-z-]{1,40}$/', $query['ref'])
            ? $query['ref'] : '';

        return match ($query['done'] ?? null) {
            'connected' => 'eBay account connected.',
            'disconnected' => 'eBay account disconnected.',
            'imported' => $ref !== '' ? "Order $ref imported." : 'Order imported.',
            'tracking' => sprintf(
                'Checked %d order(s): %d updated with tracking, %d could not be looked up.',
                $int('checked'),
                $int('updated'),
                $int('failed')
            ),
            'feedback' => sprintf(
                'Fetched %d feedback entr%s; %d new imported.',
                $int('fetched'),
                $int('fetched') === 1 ? 'y' : 'ies',
                $int('imported')
            ),
            default => null,
        };
    }

    /**
     * Pull the code out of a pasted value: either the code itself or the full
     * redirect URL containing `code=...`.
     */
    private function extractCode(string $value): string
    {
        if (str_contains($value, 'code=')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $params);

            return is_string($params['code'] ?? null) ? trim($params['code']) : '';
        }

        return str_contains($value, '%') ? rawurldecode($value) : $value;
    }

    /**
     * Look-back days from user input, limited to the allowed options.
     */
    private function days(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : self::DEFAULT_DAYS;

        return in_array($days, self::DAY_OPTIONS, true) ? $days : self::DEFAULT_DAYS;
    }

    /**
     * A trimmed string field from the submitted form ('' when missing).
     */
    private function field(ServerRequestInterface $request, string $name): string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[$name] ?? '') : '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Set-Cookie header for the OAuth state (an empty value with max age 0 deletes it).
     */
    private function stateCookie(ServerRequestInterface $request, string $value, int $maxAge): string
    {
        return sprintf(
            '%s=%s; Path=/ebay; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            self::STATE_COOKIE,
            $value,
            $maxAge,
            $request->getUri()->getScheme() === 'https' ? '; Secure' : ''
        );
    }
}
