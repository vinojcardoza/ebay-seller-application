<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Ebay\EbayApiException;
use App\Ebay\EbayConfig;
use App\Ebay\EbayException;
use App\Ebay\EbayOAuth;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * eBay integration page: connect or disconnect the seller's account (OAuth).
 *
 * Importing orders and syncing tracking live on the Orders page, importing
 * feedback on the Feedback page.
 */
class EbayController extends ControllerAbstract
{
    /**
     * Cookie holding the OAuth `state` value between /ebay/connect and the callback.
     */
    private const STATE_COOKIE = 'ebay_oauth_state';

    /**
     * Messages for `?error=` codes set by redirects.
     */
    private const ERRORS = [
        'config' => 'eBay is not configured. Set EBAY_CLIENT_ID, EBAY_CLIENT_SECRET and EBAY_RU_NAME.',
        'denied' => 'eBay sign-in was cancelled or refused.',
        'state' => 'The eBay sign-in could not be verified. Please connect again.',
        'code' => 'Enter the authorization code, or the URL eBay redirected you to.',
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
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * Show the connection status and the connect / disconnect actions.
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

        $clearCookie = $this->stateCookie($request, '', 0);

        if (isset($query['error'])) {
            $target = '/ebay?error=denied';
        } elseif (!is_string($cookie) || !is_string($state) || $cookie === '' || !hash_equals($cookie, $state)) {
            $target = '/ebay?error=state';
        } elseif (!is_string($code) || $code === '') {
            $target = '/ebay?error=code';
        } else {
            $failure = $this->exchange($code);

            if ($failure !== null) {
                return $this->renderIndex($request, $failure, 502)->withAddedHeader('Set-Cookie', $clearCookie);
            }

            $target = '/ebay?done=connected';
        }

        return $this->redirect($target)->withAddedHeader('Set-Cookie', $clearCookie);
    }

    /**
     * Connect with an authorization code pasted by hand, for setups where eBay cannot
     * redirect back to the app (eBay only redirects to HTTPS URLs).
     */
    public function submitCode(ServerRequestInterface $request): ResponseInterface
    {
        $code = $this->extractCode($this->field($request, 'code'));

        if ($code === '') {
            return $this->redirect('/ebay?error=code');
        }

        $failure = $this->exchange($code);

        return $failure === null
            ? $this->redirect('/ebay?done=connected')
            : $this->renderIndex($request, $failure, 502);
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
     * Render the eBay page.
     */
    private function renderIndex(
        ServerRequestInterface $request,
        ?string $error = null,
        int $status = 200
    ): ResponseInterface {
        $query = $request->getQueryParams();

        return $this->render('ebay/index', [
            'config' => $this->config,
            'connection' => $this->config->isConfigured() ? $this->oauth->connection() : null,
            'notice' => $this->notice($query),
            'error' => $error ?? (is_string($query['error'] ?? null) ? self::ERRORS[$query['error']] ?? null : null),
        ], $status);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return string|null Null on success, otherwise eBay's reason plus a hint on how to fix it.
     */
    private function exchange(string $code): ?string
    {
        try {
            $this->oauth->exchangeCode($code);
        } catch (EbayException $e) {
            $hint = match ($e instanceof EbayApiException ? $e->getErrorCode() : '') {
                'invalid_client' => 'eBay did not accept the app credentials: check that EBAY_CLIENT_SECRET is the'
                    . ' Cert ID (Client Secret) of the same keyset as EBAY_CLIENT_ID (production Cert IDs start'
                    . ' with "PRD-"), then restart the containers.',
                'invalid_grant' => 'The code is invalid, expired (codes last about 5 minutes) or already used, or'
                    . ' EBAY_RU_NAME is not the RuName you signed in with. Click Connect to get a new code.',
                default => 'Please connect again.',
            };

            return 'Could not connect eBay. ' . $e->getMessage() . ' ' . $hint;
        }

        return null;
    }

    /**
     * Success message for a `?done=` redirect.
     *
     * @param array<string, mixed> $query
     */
    private function notice(array $query): ?string
    {
        return match ($query['done'] ?? null) {
            'connected' => 'eBay account connected.',
            'disconnected' => 'eBay account disconnected.',
            default => null,
        };
    }

    /**
     * Pull the code out of a pasted value: either the code itself or the full
     * redirect URL containing `code=...`.
     */
    private function extractCode(string $value): string
    {
        // Read the code straight from the text: a decoded URL contains "#"
        // (eBay codes do), which URL parsers treat as the start of a fragment.
        if (preg_match('/(?:^|[?&])code=([^&\s]+)/', $value, $match)) {
            $value = $match[1];
        }

        // rawurldecode keeps "+" (eBay codes may contain it); only decode encoded input.
        return str_contains($value, '%') ? rawurldecode($value) : $value;
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
