<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Auth\Authenticator;
use App\Controllers\AuthController;
use App\Framework\Template\PlatesRender;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\AuthFixture;

/**
 * Tests for the login form, logging in and logging out.
 */
#[CoversClass(AuthController::class)]
final class AuthControllerTest extends TestCase
{
    use AuthFixture;

    /**
     * Controller under test.
     */
    private AuthController $controller;

    /**
     * Build the fixture user, session, authenticator and controller.
     */
    protected function setUp(): void
    {
        $this->setUpAuth();
        $factory = new Psr17Factory();
        $this->controller = new AuthController(
            new PlatesRender($this->auth),
            $factory,
            $factory,
            $this->createStub(EntityManagerInterface::class),
            $this->auth
        );
    }

    /**
     * The login form posts to /login, keeps a safe `next` and is not cached.
     */
    public function testLoginFormRenders(): void
    {
        $response = $this->controller->loginForm($this->get(['next' => '/orders?type=Refund']));
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('<title>Log in · JRN Shop</title>', $body);
        $this->assertStringContainsString('src="/assets/img/logo-dark-160.png"', $body);
        $this->assertStringContainsString('src="/assets/img/logo-light-160.jpg"', $body);
        $this->assertStringContainsString('href="/assets/img/favicon-64.png"', $body);
        $this->assertStringContainsString('<form method="post" action="/login"', $body);
        $this->assertStringContainsString('name="next" value="/orders?type=Refund"', $body);
        $this->assertStringContainsString('autocomplete="current-password"', $body);
        $this->assertStringNotContainsString('app-nav', $body, 'The login page has no app navigation.');
        $this->assertStringNotContainsString('alert', $body);
    }

    /**
     * After logging out the form says so.
     */
    public function testLoginFormShowsLoggedOutNotice(): void
    {
        $body = $this->body($this->controller->loginForm($this->get(['done' => 'logged-out'])));

        $this->assertStringContainsString('You have been logged out.', $body);
    }

    /**
     * A logged-in user visiting the login page goes straight to `next`.
     */
    public function testLoginFormRedirectsWhenLoggedIn(): void
    {
        $this->logIn();

        $this->assertRedirect('/reports', $this->controller->loginForm($this->get(['next' => '/reports'])));
    }

    /**
     * `next` values and where a successful login goes.
     *
     * @return array<string, array{string|null, string}>
     */
    public static function nextProvider(): array
    {
        return [
            'page' => ['/orders', '/orders'],
            'page with query' => ['/orders?type=Refund&page=2', '/orders?type=Refund&page=2'],
            'missing' => [null, '/'],
            'other site' => ['https://evil.example/', '/'],
            'protocol-relative' => ['//evil.example/', '/'],
            'backslash' => ['/\\evil.example/', '/'],
            'relative' => ['orders', '/'],
            'header injection' => ["/orders\r\nSet-Cookie: x=1", '/'],
            'login page' => ['/login', '/'],
        ];
    }

    /**
     * A correct login redirects to a safe `next` (or "/") and logs the user in.
     */
    #[DataProvider('nextProvider')]
    public function testLoginRedirectsToSafeNext(?string $next, string $location): void
    {
        $fields = ['login' => 'vinoj', 'password' => self::AUTH_PASSWORD] + ($next !== null ? ['next' => $next] : []);

        $this->assertRedirect($location, $this->controller->login($this->post($fields)));
        $this->assertSame(1, $this->session->data[Authenticator::USER_KEY] ?? null);
    }

    /**
     * Logging in by email works too.
     */
    public function testLoginByEmail(): void
    {
        $response = $this->controller->login(
            $this->post(['login' => 'vinoj@example.com', 'password' => self::AUTH_PASSWORD])
        );

        $this->assertRedirect('/', $response);
    }

    /**
     * Rejected login submissions and their message.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function rejectedLoginProvider(): array
    {
        return [
            'wrong password' => [['login' => 'vinoj', 'password' => 'nope'], 'Incorrect login or password.'],
            'unknown user' => [['login' => 'ghost', 'password' => 'whatever'], 'Incorrect login or password.'],
            'empty login' => [['login' => ' ', 'password' => 'x'], 'Enter your login and password.'],
            'empty password' => [['login' => 'vinoj', 'password' => ''], 'Enter your login and password.'],
            'array fields' => [['login' => ['vinoj'], 'password' => ['x']], 'Enter your login and password.'],
        ];
    }

    /**
     * Failed logins re-render the form with a 422 and the same message for unknown users and wrong passwords.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('rejectedLoginProvider')]
    public function testLoginRejects(array $fields, string $message): void
    {
        $response = $this->controller->login($this->post($fields + ['next' => '/orders']));
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($message, $body);
        $this->assertStringContainsString('name="next" value="/orders"', $body);
        $this->assertSame([], $this->session->data);
        $this->assertSame(0, $this->flushes);
    }

    /**
     * A failed login keeps the login name but never echoes the password.
     */
    public function testLoginKeepsLoginButNotPassword(): void
    {
        $body = $this->body($this->controller->login($this->post(['login' => 'vinoj', 'password' => 'secret-guess'])));

        $this->assertStringContainsString('value="vinoj"', $body);
        $this->assertStringNotContainsString('secret-guess', $body);
    }

    /**
     * Logging out ends the session and returns to the login page.
     */
    public function testLogout(): void
    {
        $this->logIn();

        $response = $this->controller->logout();

        $this->assertRedirect('/login?done=logged-out', $response);
        $this->assertSame(1, $this->session->destroyed);
        $this->assertNull($this->auth->user());
    }

    /**
     * Assert the response is a 302 redirect to the given location.
     */
    private function assertRedirect(string $location, ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($location, $response->getHeaderLine('Location'));
    }

    /**
     * Build a GET request for the login page.
     *
     * @param array<string, string> $query
     */
    private function get(array $query = []): ServerRequest
    {
        return (new ServerRequest('GET', '/login'))->withQueryParams($query);
    }

    /**
     * Build a POST request to /login with form fields.
     *
     * @param array<string, mixed> $fields
     */
    private function post(array $fields): ServerRequest
    {
        return (new ServerRequest('POST', '/login'))->withParsedBody($fields);
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
