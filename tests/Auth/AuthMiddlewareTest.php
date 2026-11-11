<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\AuthFixture;

/**
 * Tests that the app is gated behind login, with the login page public.
 */
#[CoversClass(AuthMiddleware::class)]
final class AuthMiddlewareTest extends TestCase
{
    use AuthFixture;

    /**
     * Whether the next handler was called.
     */
    private bool $handled = false;

    /**
     * Build the fixture user, session and authenticator.
     */
    protected function setUp(): void
    {
        $this->setUpAuth();
        $this->handled = false;
    }

    /**
     * Anonymous requests and where they are redirected.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function anonymousProvider(): array
    {
        return [
            'dashboard' => ['GET', '/', '/login'],
            'page' => ['GET', '/orders', '/login?next=%2Forders'],
            'page with query' => [
                'GET',
                '/orders?type=Refund&page=2',
                '/login?next=%2Forders%3Ftype%3DRefund%26page%3D2',
            ],
            'head' => ['HEAD', '/reports', '/login?next=%2Freports'],
            'form post' => ['POST', '/purchases/7/delete', '/login'],
            'logout' => ['POST', '/logout', '/login'],
        ];
    }

    /**
     * Anonymous requests are redirected to the login page, GETs remembering the page asked for.
     */
    #[DataProvider('anonymousProvider')]
    public function testAnonymousRequestsRedirectToLogin(string $method, string $uri, string $location): void
    {
        $response = $this->process(new ServerRequest($method, $uri));

        $this->assertFalse($this->handled);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($location, $response->getHeaderLine('Location'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The login page is reachable without logging in.
     */
    public function testLoginPageIsPublic(): void
    {
        $this->assertSame(200, $this->process(new ServerRequest('GET', '/login?next=%2Forders'))->getStatusCode());
        $this->assertTrue($this->handled);

        $this->handled = false;
        $this->assertSame(200, $this->process(new ServerRequest('POST', '/login'))->getStatusCode());
        $this->assertTrue($this->handled);
    }

    /**
     * A logged-in user's requests pass through.
     */
    public function testLoggedInRequestsPass(): void
    {
        $this->logIn();

        $response = $this->process(new ServerRequest('POST', '/purchases/receive'));

        $this->assertTrue($this->handled);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * An expired session is treated as anonymous.
     */
    public function testExpiredSessionRedirects(): void
    {
        $this->logIn('2026-11-09 12:00:00 UTC');

        $response = $this->process(new ServerRequest('GET', '/orders'));

        $this->assertFalse($this->handled);
        $this->assertSame('/login?next=%2Forders', $response->getHeaderLine('Location'));
    }

    /**
     * Run the middleware with a handler that answers 200 and records the call.
     */
    private function process(ServerRequestInterface $request): ResponseInterface
    {
        $factory = new Psr17Factory();
        $handler = new class ($factory, fn () => $this->handled = true) implements RequestHandlerInterface {
            /**
             * Create the handler.
             */
            public function __construct(private Psr17Factory $factory, private \Closure $onHandle)
            {
            }

            /**
             * Record the call and answer 200.
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->onHandle)();

                return $this->factory->createResponse(200);
            }
        };

        return (new AuthMiddleware($this->auth, $factory))->process($request, $handler);
    }
}
