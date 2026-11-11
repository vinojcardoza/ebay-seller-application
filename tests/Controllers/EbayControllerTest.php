<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\EbayController;
use App\Ebay\EbayApiException;
use App\Ebay\EbayConfig;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\EbayOAuth;
use App\Ebay\FulfillmentApi;
use App\Ebay\Import\FeedbackImporter;
use App\Ebay\Import\ImportPreview;
use App\Ebay\Import\OrderImporter;
use App\Ebay\Import\OrderImportException;
use App\Ebay\Import\TrackingSynchroniser;
use App\Ebay\Token\OAuthToken;
use App\Entities\Order;
use App\Framework\Template\PlatesRender;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
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
     * Fulfillment API fake (a stub until a test asks for a mock via fulfillmentMock()).
     */
    private FulfillmentApi&Stub $fulfillment;

    /**
     * Order importer fake (a stub until a test asks for a mock via importerMock()).
     */
    private OrderImporter&Stub $importer;

    /**
     * Tracking synchroniser fake.
     */
    private TrackingSynchroniser&Stub $tracking;

    /**
     * Feedback importer fake.
     */
    private FeedbackImporter&Stub $feedback;

    /**
     * Create the fakes. The seller is connected unless a test says otherwise.
     */
    protected function setUp(): void
    {
        $this->oauth = $this->createStub(EbayOAuth::class);
        $this->fulfillment = $this->createStub(FulfillmentApi::class);
        $this->importer = $this->createStub(OrderImporter::class);
        $this->tracking = $this->createStub(TrackingSynchroniser::class);
        $this->feedback = $this->createStub(FeedbackImporter::class);
    }

    // ---------- index ----------

    /**
     * Without credentials the page explains what to configure and calls nothing.
     */
    public function testIndexWhenNotConfigured(): void
    {
        $this->oauthMock()->expects($this->never())->method('connection');
        $this->fulfillmentMock()->expects($this->never())->method('ordersCreatedSince');
        $this->importerMock()->expects($this->never())->method('preview');

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
        $this->fulfillmentMock()->expects($this->never())->method('ordersCreatedSince');
        $this->importerMock()->expects($this->never())->method('preview');

        $body = $this->body($this->controller()->index($this->get('/ebay')));

        $this->assertStringContainsString('href="/ebay/connect"', $body);
        $this->assertStringContainsString('action="/ebay/code"', $body);
        $this->assertStringContainsString('Connect your eBay account to see recent orders.', $body);
        $this->assertMatchesRegularExpression('#action="/ebay/tracking">\s*<button[^>]*disabled#', $body);
    }

    /**
     * The page lists the requested scopes, and disables feedback import without its scope.
     */
    public function testIndexShowsScopesAndFeedbackScopeHint(): void
    {
        $this->connected();
        $this->fulfillment->method('ordersCreatedSince')->willReturn([]);
        $this->importer->method('preview')->willReturn([]);

        $default = $this->body($this->controller()->index($this->get('/ebay')));
        $this->assertStringContainsString('<div>/commerce.feedback</div>', $default);
        $this->assertStringNotContainsString('Needs the <code>commerce.feedback</code>', $default);

        $limited = $this->body($this->controller(scopes: [EbayConfig::SCOPE_PREFIX, EbayConfig::FULFILLMENT_SCOPE])
            ->index($this->get('/ebay')));
        $this->assertStringContainsString('<div>api_scope</div>', $limited);
        $this->assertStringContainsString('<div>/sell.fulfillment.readonly</div>', $limited);
        $this->assertStringContainsString('Needs the <code>commerce.feedback</code> scope', $limited);
        $this->assertMatchesRegularExpression('#action="/ebay/feedback">\s*<button[^>]*\s+disabled>#', $limited);
    }

    /**
     * When connected, recent orders for the chosen period are listed newest first with their status.
     */
    public function testIndexListsRecentOrders(): void
    {
        $this->connected();
        $this->fulfillmentMock()->expects($this->once())
            ->method('ordersCreatedSince')
            ->with($this->callback(static fn (DateTimeInterface $from): bool
                => $from->format('Y-m-d H:i') === '2026-11-03 12:00'))
            ->willReturn([['orderId' => 'raw']]);
        $this->importerMock()->expects($this->once())->method('preview')->with([['orderId' => 'raw']])->willReturn([
            $this->preview('12-old', '2026-11-05', alreadyImported: true),
            $this->preview('12-new', '2026-11-09'),
            $this->preview('12-bad', '2026-11-07', match: null),
        ]);

        $response = $this->controller()->index($this->get('/ebay', ['days' => '7']));
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Connected', $body);
        $this->assertStringContainsString('Reconnect by', $body);
        $this->assertMatchesRegularExpression('#<option value="7"\s+selected>#', $body);
        $this->assertLessThan(strpos($body, '12-bad'), strpos($body, '12-new'), 'Newest order first.');
        $this->assertLessThan(strpos($body, '12-old'), strpos($body, '12-bad'));
        $this->assertStringContainsString('action="/ebay/orders/12-new"', $body);
        $this->assertStringNotContainsString('action="/ebay/orders/12-old"', $body);
        $this->assertStringContainsString('Imported</span>', $body);
        $this->assertStringContainsString('Unknown SKU: LCD-1602.', $body);
        $this->assertStringContainsString('£6.99', $body);
    }

    /**
     * Look-back periods outside the allowed options fall back to 3 days.
     */
    public function testIndexRejectsUnknownDays(): void
    {
        $this->connected();
        $this->importer->method('preview')->willReturn([]);
        $this->fulfillmentMock()->expects($this->once())
            ->method('ordersCreatedSince')
            ->with($this->callback(static fn (DateTimeInterface $from): bool
                => $from->format('Y-m-d') === '2026-11-07'))
            ->willReturn([]);

        $body = $this->body($this->controller()->index($this->get('/ebay', ['days' => '365'])));

        $this->assertStringContainsString('No eBay orders in the last 3 days.', $body);
    }

    /**
     * An eBay error while loading orders is shown on the page instead of failing it.
     */
    public function testIndexShowsOrdersError(): void
    {
        $this->connected();
        $this->importerMock()->expects($this->never())->method('preview');
        $this->fulfillment->method('ordersCreatedSince')
            ->willThrowException(new EbayApiException('eBay request GET /order failed (HTTP 500).', 500));

        $response = $this->controller()->index($this->get('/ebay'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('eBay request GET /order failed (HTTP 500).', $this->body($response));
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
            'imported' => [['done' => 'imported', 'ref' => '12-34567-89012'], 'Order 12-34567-89012 imported.'],
            'imported with unsafe ref' => [['done' => 'imported', 'ref' => '<script>'], 'Order imported.'],
            'tracking' => [
                ['done' => 'tracking', 'checked' => '5', 'updated' => '3', 'failed' => '1'],
                'Checked 5 order(s): 3 updated with tracking, 1 could not be looked up.',
            ],
            'feedback' => [
                ['done' => 'feedback', 'fetched' => '1', 'imported' => '1'],
                'Fetched 1 feedback entry; 1 new imported.',
            ],
            'state error' => [['error' => 'state'], 'The eBay sign-in could not be verified.'],
            'config error' => [['error' => 'config'], 'eBay is not configured.'],
        ];
    }

    /**
     * Redirect codes show fixed messages built only from known values.
     *
     * @param array<string, string> $query
     */
    #[DataProvider('messageProvider')]
    public function testIndexShowsMessages(array $query, string $message): void
    {
        $this->oauthMock()->expects($this->once())->method('connection')->willReturn(null);
        $this->importerMock()->expects($this->never())->method('preview');
        $this->fulfillmentMock()->expects($this->never())->method('order');

        $body = $this->body($this->controller()->index($this->get('/ebay', $query)));

        $this->assertStringContainsString($message, $body);
        $this->assertStringNotContainsString('Order <script', $body);
        $this->assertStringNotContainsString('script&gt;', $body);
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

    // ---------- importOrder ----------

    /**
     * Importing fetches the order from eBay, imports it (promoted flag passed through) and redirects.
     */
    public function testImportOrder(): void
    {
        $this->fulfillmentMock()->expects($this->once())->method('order')->with('12-34567-89012')
            ->willReturn(['orderId' => '12-34567-89012']);
        $this->importerMock()->expects($this->once())->method('import')->with(['orderId' => '12-34567-89012'], true)
            ->willReturn((new Order())->setOrderReference('12-34567-89012'));

        $response = $this->controller()->importOrder(
            $this->post('/ebay/orders/12-34567-89012', ['promoted' => '1', 'days' => '14']),
            ['orderId' => '12-34567-89012']
        );

        $this->assertRedirect('/ebay?done=imported&ref=12-34567-89012&days=14', $response);
    }

    /**
     * Without the promoted box ticked, the import is not promoted.
     */
    public function testImportOrderNotPromoted(): void
    {
        $this->fulfillmentMock()->expects($this->once())->method('order')->willReturn([]);
        $this->importerMock()->expects($this->once())->method('import')->with([], false)
            ->willReturn((new Order())->setOrderReference('12-1'));

        $this->controller()->importOrder($this->post('/ebay/orders/12-1', []), ['orderId' => '12-1']);
    }

    /**
     * An order that cannot be imported redisplays the page with the reason (422).
     */
    public function testImportOrderRejected(): void
    {
        $this->connected();
        $this->fulfillmentMock()->expects($this->once())->method('order')->willReturn([]);
        $this->fulfillment->method('ordersCreatedSince')->willReturn([]);
        $this->importerMock()->expects($this->once())->method('import')
            ->willThrowException(new OrderImportException('eBay order 12-1 cannot be imported: Unknown SKU: X.'));
        $this->importer->method('preview')->willReturn([]);

        $response = $this->controller()->importOrder($this->post('/ebay/orders/12-1', []), ['orderId' => '12-1']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'eBay order 12-1 cannot be imported: Unknown SKU: X.',
            $this->body($response)
        );
    }

    /**
     * An eBay API error during import shows a 502 page with the error.
     */
    public function testImportOrderApiError(): void
    {
        $this->oauth->method('connection')->willReturn(null);
        $this->fulfillmentMock()->expects($this->once())->method('order')
            ->willThrowException(new EbayApiException('Order 12-1 was not found.', 404));
        $this->importerMock()->expects($this->never())->method('import');

        $response = $this->controller()->importOrder($this->post('/ebay/orders/12-1', []), ['orderId' => '12-1']);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('Order 12-1 was not found.', $this->body($response));
    }

    /**
     * When eBay is not connected, importing asks the user to connect.
     */
    public function testImportOrderNotConnected(): void
    {
        $this->fulfillmentMock()->expects($this->once())->method('order')
            ->willThrowException(new EbayNotConnectedException('not connected'));
        $this->importerMock()->expects($this->never())->method('import');

        $response = $this->controller()->importOrder($this->post('/ebay/orders/12-1', []), ['orderId' => '12-1']);

        $this->assertRedirect('/ebay?error=not-connected', $response);
    }

    // ---------- syncTracking & importFeedback ----------

    /**
     * Tracking sync redirects with the counts.
     */
    public function testSyncTracking(): void
    {
        $this->tracking->method('sync')->willReturn(['checked' => 4, 'updated' => 2, 'failed' => ['06-1']]);

        $this->assertRedirect(
            '/ebay?done=tracking&checked=4&updated=2&failed=1',
            $this->controller()->syncTracking($this->post('/ebay/tracking', []))
        );
    }

    /**
     * Feedback import redirects with the counts.
     */
    public function testImportFeedback(): void
    {
        $this->feedback->method('import')->willReturn(['fetched' => 200, 'imported' => 7]);

        $this->assertRedirect(
            '/ebay?done=feedback&fetched=200&imported=7',
            $this->controller()->importFeedback($this->post('/ebay/feedback', []))
        );
    }

    /**
     * Sync actions and the fake that should fail.
     *
     * @return array<string, array{string}>
     */
    public static function syncActionProvider(): array
    {
        return [
            'tracking' => ['syncTracking'],
            'feedback' => ['importFeedback'],
        ];
    }

    /**
     * Sync actions ask to connect when eBay is not connected.
     */
    #[DataProvider('syncActionProvider')]
    public function testSyncActionsNotConnected(string $action): void
    {
        $this->tracking->method('sync')->willThrowException(new EbayNotConnectedException('x'));
        $this->feedback->method('import')->willThrowException(new EbayNotConnectedException('x'));

        $this->assertRedirect('/ebay?error=not-connected', $this->controller()->$action($this->post('/ebay/x', [])));
    }

    /**
     * Sync actions show eBay errors on the page with a 502.
     */
    #[DataProvider('syncActionProvider')]
    public function testSyncActionsApiError(string $action): void
    {
        $this->oauth->method('connection')->willReturn(null);
        $this->tracking->method('sync')->willThrowException(new EbayApiException('eBay is down', 503));
        $this->feedback->method('import')->willThrowException(new EbayApiException('eBay is down', 503));

        $response = $this->controller()->$action($this->post('/ebay/x', []));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('eBay is down', $this->body($response));
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
            $this->fulfillment,
            $this->importer,
            $this->tracking,
            $this->feedback,
            new FrozenClock('2026-11-10 12:00:00 UTC'),
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
     * Replace the Fulfillment API stub with a mock so the test can set expectations.
     */
    private function fulfillmentMock(): FulfillmentApi&MockObject
    {
        if (!$this->fulfillment instanceof MockObject) {
            $this->fulfillment = $this->createMock(FulfillmentApi::class);
        }

        return $this->fulfillment;
    }

    /**
     * Replace the importer stub with a mock so the test can set expectations.
     */
    private function importerMock(): OrderImporter&MockObject
    {
        if (!$this->importer instanceof MockObject) {
            $this->importer = $this->createMock(OrderImporter::class);
        }

        return $this->importer;
    }

    /**
     * Build an order preview with one LCD-1602 line.
     */
    private function preview(
        string $orderId,
        string $created,
        bool $alreadyImported = false,
        ?string $match = ImportPreview::MATCH_PRODUCT
    ): ImportPreview {
        return new ImportPreview(
            $orderId,
            new DateTimeImmutable("$created 10:00:00 UTC"),
            'buyer_1',
            '6.99',
            'GBP',
            [['sku' => 'LCD-1602', 'title' => 'LCD Display', 'quantity' => 1, 'match' => $match]],
            $alreadyImported,
            false,
        );
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
