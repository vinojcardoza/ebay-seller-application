<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\EbayController;
use App\Ebay\EbayApiException;
use App\Ebay\EbayConfig;
use App\Ebay\EbayOAuth;
use App\Ebay\Token\OAuthToken;
use App\Framework\Template\PlatesRender;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests for every EbayController action, with the eBay services faked and real views.
 */
#[CoversClass(EbayController::class)]
final class EbayControllerTest extends TestCase
{
    /**
     * OAuth helper fake (a stub until a test asks for a mock via oauthMock()).
     */
    private EbayOAuth&Stub $oauth;

    /**
     * Create the OAuth fake; the seller is not connected unless a test says otherwise.
     */
    protected function setUp(): void
    {
        $this->oauth = $this->createStub(EbayOAuth::class);
    }

    // ---------- index ----------

    /**
     * Without credentials the page explains what to configure and does not look up a connection.
     */
    public function testIndexWhenNotConfigured(): void
    {
        $this->oauthMock()->expects($this->never())->method('connection');

        $body = $this->body($this->controller(configured: false)->index($this->get('/ebay')));

        $this->assertStringContainsString('Not connected', $body);
        $this->assertStringContainsString('<code>EBAY_CLIENT_ID</code>', $body);
        $this->assertStringNotContainsString('href="/ebay/connect"', $body);
    }

    /**
     * Configured but not connected: offer the Connect button and the manual code form.
     */
    public function testIndexWhenNotConnected(): void
    {
        $this->oauthMock()->expects($this->once())->method('connection')->willReturn(null);

        $body = $this->body($this->controller()->index($this->get('/ebay')));

        $this->assertStringContainsString('Not connected', $body);
        $this->assertStringContainsString('href="/ebay/connect"', $body);
        $this->assertStringContainsString('action="/ebay/code"', $body);
        $this->assertStringNotContainsString('action="/ebay/disconnect"', $body);
    }

    /**
     * When connected, the page shows the connection and offers only Disconnect: orders,
     * tracking and feedback live on their own pages.
     */
    public function testIndexWhenConnected(): void
    {
        $this->connected();

        $response = $this->controller()->index($this->get('/ebay'));
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Connected</span>', $body);
        $this->assertStringContainsString('Reconnect by', $body);
        $this->assertStringContainsString('action="/ebay/disconnect"', $body);
        $this->assertStringNotContainsString('href="/ebay/connect"', $body);
        $this->assertStringNotContainsString('Recent eBay orders', $body);
        $this->assertStringNotContainsString('Sync tracking', $body);
        $this->assertStringNotContainsString('Import feedback', $body);
    }

    /**
     * The page lists the requested scopes.
     */
    public function testIndexShowsScopes(): void
    {
        $this->connected();

        $default = $this->body($this->controller()->index($this->get('/ebay')));
        $this->assertStringContainsString('<div>/commerce.feedback</div>', $default);

        $limited = $this->body($this->controller(scopes: [EbayConfig::SCOPE_PREFIX, EbayConfig::FULFILLMENT_SCOPE])
            ->index($this->get('/ebay')));
        $this->assertStringContainsString('<div>api_scope</div>', $limited);
        $this->assertStringContainsString('<div>/sell.fulfillment.readonly</div>', $limited);
        $this->assertStringNotContainsString('<div>/commerce.feedback</div>', $limited);
    }

    /**
     * Query codes and the message shown for them.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function messageProvider(): array
    {
        return [
            'connected' => [['done' => 'connected'], 'eBay account connected.'],
            'disconnected' => [['done' => 'disconnected'], 'eBay account disconnected.'],
            'state error' => [['error' => 'state'], 'The eBay sign-in could not be verified.'],
            'config error' => [['error' => 'config'], 'eBay is not configured.'],
            'denied error' => [['error' => 'denied'], 'eBay sign-in was cancelled or refused.'],
        ];
    }

    /**
     * Redirect codes show fixed messages.
     *
     * @param array<string, string> $query
     */
    #[DataProvider('messageProvider')]
    public function testIndexShowsMessages(array $query, string $message): void
    {
        $this->oauthMock()->expects($this->once())->method('connection')->willReturn(null);

        $body = $this->body($this->controller()->index($this->get('/ebay', $query)));

        $this->assertStringContainsString($message, $body);
    }

    /**
     * Unknown codes (including ones the page no longer uses) show no message.
     */
    public function testIndexIgnoresUnknownCodes(): void
    {
        $this->oauth->method('connection')->willReturn(null);

        $body = $this->body($this->controller()->index($this->get('/ebay', ['done' => 'imported', 'error' => 'x'])));

        $this->assertStringNotContainsString('alert-success', $body);
        $this->assertStringNotContainsString('alert-danger', $body);
    }

    // ---------- connect & callback ----------

    /**
     * Connecting redirects to eBay's consent page and stores the same state in a cookie.
     */
    public function testConnectRedirectsToEbayWithState(): void
    {
        $state = null;
        $this->oauthMock()->expects($this->once())->method('authorizationUrl')->willReturnCallback(
            function (string $value) use (&$state): string {
                $state = $value;

                return 'https://auth.ebay.com/oauth2/authorize?state=' . $value;
            }
        );

        $response = $this->controller()->connect($this->get('https://shop.example/ebay/connect'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $state);
        $this->assertSame(
            'https://auth.ebay.com/oauth2/authorize?state=' . $state,
            $response->getHeaderLine('Location')
        );
        $this->assertSame(
            "ebay_oauth_state=$state; Path=/ebay; Max-Age=600; HttpOnly; SameSite=Lax; Secure",
            $response->getHeaderLine('Set-Cookie')
        );
    }

    /**
     * Without credentials, connecting explains what is missing.
     */
    public function testConnectWhenNotConfigured(): void
    {
        $this->oauthMock()->expects($this->never())->method('authorizationUrl');

        $response = $this->controller(configured: false)->connect($this->get('/ebay/connect'));

        $this->assertRedirect('/ebay?error=config', $response);
    }

    /**
     * A matching state exchanges the code, stores the tokens and clears the state cookie.
     */
    public function testCallbackExchangesCode(): void
    {
        $this->oauthMock()->expects($this->once())->method('exchangeCode')->with('v^1.1#code')
            ->willReturn($this->token());

        $response = $this->controller()->callback(
            $this->get('/ebay/callback', ['code' => 'v^1.1#code', 'state' => 'abc'], ['ebay_oauth_state' => 'abc'])
        );

        $this->assertRedirect('/ebay?done=connected', $response);
        $this->assertStringContainsString(
            'ebay_oauth_state=; Path=/ebay; Max-Age=0',
            $response->getHeaderLine('Set-Cookie')
        );
    }

    /**
     * Callback requests that must not exchange a code, and where they lead.
     *
     * @return array<string, array{array<string, string>, array<string, string>, string}>
     */
    public static function rejectedCallbackProvider(): array
    {
        return [
            'seller declined' => [
                ['error' => 'access_denied', 'state' => 'abc'],
                ['ebay_oauth_state' => 'abc'],
                'denied',
            ],
            'state mismatch' => [['code' => 'c', 'state' => 'evil'], ['ebay_oauth_state' => 'abc'], 'state'],
            'no state cookie' => [['code' => 'c', 'state' => 'abc'], [], 'state'],
            'empty state' => [['code' => 'c', 'state' => ''], ['ebay_oauth_state' => ''], 'state'],
            'no code' => [['state' => 'abc'], ['ebay_oauth_state' => 'abc'], 'code'],
        ];
    }

    /**
     * Declined, forged or incomplete callbacks never exchange a code.
     *
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    #[DataProvider('rejectedCallbackProvider')]
    public function testCallbackRejects(array $query, array $cookies, string $error): void
    {
        $this->oauthMock()->expects($this->never())->method('exchangeCode');

        $response = $this->controller()->callback($this->get('/ebay/callback', $query, $cookies));

        $this->assertRedirect("/ebay?error=$error", $response);
    }

    /**
     * A code eBay refuses shows eBay's reason on the page (502) and clears the state cookie.
     */
    public function testCallbackExchangeFailure(): void
    {
        $this->oauthMock()->expects($this->once())->method('exchangeCode')->willThrowException(
            EbayApiException::fromResponse(
                'Exchanging the eBay authorization code',
                400,
                '{"error":"invalid_grant","error_description":"the provided authorization grant code is invalid"}'
            )
        );

        $response = $this->controller()->callback(
            $this->get('/ebay/callback', ['code' => 'c', 'state' => 'abc'], ['ebay_oauth_state' => 'abc'])
        );
        $body = html_entity_decode($this->body($response), ENT_QUOTES);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('the provided authorization grant code is invalid', $body);
        $this->assertStringContainsString('codes last about 5 minutes', $body);
        $this->assertStringContainsString(
            'ebay_oauth_state=; Path=/ebay; Max-Age=0',
            $response->getHeaderLine('Set-Cookie')
        );
    }

    /**
     * eBay error codes and the hint shown for them.
     *
     * @return array<string, array{string, string}>
     */
    public static function exchangeErrorProvider(): array
    {
        return [
            'wrong client secret' => [
                '{"error":"invalid_client","error_description":"client authentication failed"}',
                'check that EBAY_CLIENT_SECRET is the Cert ID (Client Secret)',
            ],
            'expired or reused code' => [
                '{"error":"invalid_grant","error_description":"the provided authorization grant code is invalid"}',
                'Click Connect to get a new code.',
            ],
            'anything else' => ['{"error":"server_error"}', 'Please connect again.'],
        ];
    }

    /**
     * A failed manual connection shows eBay's reason and a hint for that error.
     */
    #[DataProvider('exchangeErrorProvider')]
    public function testSubmitCodeExplainsFailure(string $ebayResponse, string $hint): void
    {
        $this->oauthMock()->expects($this->once())->method('exchangeCode')->willThrowException(
            EbayApiException::fromResponse('Exchanging the eBay authorization code', 401, $ebayResponse)
        );

        $response = $this->controller()->submitCode($this->post('/ebay/code', ['code' => 'v^1.1#code']));
        $body = html_entity_decode($this->body($response), ENT_QUOTES);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString(
            'Could not connect eBay. Exchanging the eBay authorization code failed',
            $body
        );
        $this->assertStringContainsString($hint, $body);
    }

    /**
     * Pasted values and the code extracted from them.
     *
     * @return array<string, array{string, string}>
     */
    public static function pastedCodeProvider(): array
    {
        return [
            'raw code' => ['v^1.1#i^1#code', 'v^1.1#i^1#code'],
            'url-encoded code' => ['v%5E1.1%23i%5E1%23code', 'v^1.1#i^1#code'],
            'decoded URL with # and +' => [
                'https://example.com/ebay/callback?state=x&code=v^1.1#i^1#t^Ul4+eA==&expires_in=299',
                'v^1.1#i^1#t^Ul4+eA==',
            ],
            'encoded URL keeps +' => [
                'https://example.com/ebay/callback?code=v%5E1.1%23t%5EUl4%2BeA%3D%3D&expires_in=299',
                'v^1.1#t^Ul4+eA==',
            ],
            'redirect URL' => [
                'https://example.com/ebay/callback?state=x&code=v%5E1.1%23i%5E1%23code&expires_in=299',
                'v^1.1#i^1#code',
            ],
        ];
    }

    /**
     * A pasted code, encoded code or full redirect URL is exchanged.
     */
    #[DataProvider('pastedCodeProvider')]
    public function testSubmitCode(string $pasted, string $code): void
    {
        $this->oauthMock()->expects($this->once())->method('exchangeCode')->with($code)->willReturn($this->token());

        $response = $this->controller()->submitCode($this->post('/ebay/code', ['code' => " $pasted "]));

        $this->assertRedirect('/ebay?done=connected', $response);
    }

    /**
     * An empty submission is rejected without calling eBay.
     */
    public function testSubmitEmptyCode(): void
    {
        $this->oauthMock()->expects($this->never())->method('exchangeCode');

        $this->assertRedirect(
            '/ebay?error=code',
            $this->controller()->submitCode($this->post('/ebay/code', ['code' => '  ']))
        );
    }

    /**
     * Disconnecting forgets the tokens.
     */
    public function testDisconnect(): void
    {
        $this->oauthMock()->expects($this->once())->method('disconnect');

        $this->assertRedirect('/ebay?done=disconnected', $this->controller()->disconnect());
    }

    // ---------- helpers ----------

    /**
     * Build the controller; the entity manager fails the test on any write.
     *
     * @param list<string> $scopes OAuth scopes in the configuration.
     */
    private function controller(bool $configured = true, array $scopes = EbayConfig::SCOPES): EbayController
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $em->expects($this->never())->method('remove');

        $factory = new Psr17Factory();
        $config = $configured
            ? new EbayConfig('id', 'secret', 'ru', EbayConfig::PRODUCTION, 'EBAY_GB', 'test-seller', $scopes)
            : new EbayConfig('', '', '');

        return new EbayController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            $config,
            $this->oauth,
        );
    }

    /**
     * Make the OAuth fake report a connected seller.
     */
    private function connected(): void
    {
        $this->oauth->method('connection')->willReturn($this->token());
    }

    /**
     * A token valid for an hour (access) and a year (refresh).
     */
    private function token(): OAuthToken
    {
        $now = new DateTimeImmutable('2026-11-10 12:00:00 UTC');

        return new OAuthToken('a', $now->modify('+1 hour'), 'r', $now->modify('+1 year'));
    }

    /**
     * Replace the OAuth stub with a mock so the test can set expectations.
     */
    private function oauthMock(): EbayOAuth&MockObject
    {
        if (!$this->oauth instanceof MockObject) {
            $this->oauth = $this->createMock(EbayOAuth::class);
        }

        return $this->oauth;
    }

    /**
     * Build a GET request with query parameters and cookies.
     *
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    private function get(string $uri, array $query = [], array $cookies = []): ServerRequest
    {
        return (new ServerRequest('GET', $uri))->withQueryParams($query)->withCookieParams($cookies);
    }

    /**
     * Build a POST request with form fields.
     *
     * @param array<string, string> $fields
     */
    private function post(string $uri, array $fields): ServerRequest
    {
        return (new ServerRequest('POST', $uri))->withParsedBody($fields);
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * Assert the response is a 302 redirect to the given location.
     */
    private function assertRedirect(string $location, ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($location, $response->getHeaderLine('Location'));
    }
}
