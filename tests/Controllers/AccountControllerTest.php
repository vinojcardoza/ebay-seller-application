<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AccountController;
use App\Framework\Template\PlatesRender;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\AuthFixture;

/**
 * Tests for changing the logged-in user's password.
 */
#[CoversClass(AccountController::class)]
final class AccountControllerTest extends TestCase
{
    use AuthFixture;

    /**
     * Controller under test.
     */
    private AccountController $controller;

    /**
     * Build the fixture user (logged in), session, authenticator and controller.
     */
    protected function setUp(): void
    {
        $this->setUpAuth();
        $this->logIn();
        $factory = new Psr17Factory();
        $this->controller = new AccountController(
            new PlatesRender($this->auth),
            $factory,
            $factory,
            $this->authEm,
            $this->auth
        );
    }

    /**
     * The form has the three password fields with password-manager hints and is not cached.
     */
    public function testPasswordForm(): void
    {
        $response = $this->controller->passwordForm($this->get());
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('<form method="post" action="/account/password"', $body);
        $this->assertMatchesRegularExpression(
            '#name="current_password"[^>]*\s+autocomplete="current-password"#',
            $body
        );
        $this->assertMatchesRegularExpression('#name="new_password"[^>]*\s+autocomplete="new-password"#', $body);
        $this->assertMatchesRegularExpression('#name="confirm_password"[^>]*\s+autocomplete="new-password"#', $body);
        $this->assertStringContainsString('At least 10 characters.', $body);
        $this->assertStringContainsString('href="/account/password" aria-label="Change password"', $body);
        $this->assertStringNotContainsString('alert', $body);
    }

    /**
     * After a change the form confirms it.
     */
    public function testPasswordFormShowsNotice(): void
    {
        $body = $this->body($this->controller->passwordForm($this->get(['done' => 'updated'])));

        $this->assertStringContainsString('Your password has been changed.', $body);
    }

    /**
     * Without a logged-in user both actions go to the login page.
     */
    public function testRequiresLogin(): void
    {
        $this->setUpAuth();
        $factory = new Psr17Factory();
        $controller = new AccountController(new PlatesRender(), $factory, $factory, $this->authEm, $this->auth);

        $this->assertRedirect('/login', $controller->passwordForm($this->get()));
        $this->assertRedirect('/login', $controller->updatePassword($this->post($this->valid())));
        $this->assertSame(0, $this->flushes);
    }

    /**
     * A valid change stores the new hash, saves, gives the session a new ID and keeps the user logged in.
     */
    public function testUpdatePassword(): void
    {
        $response = $this->controller->updatePassword($this->post($this->valid()));

        $this->assertRedirect('/account/password?done=updated', $response);
        $this->assertTrue(password_verify('a brand new password', $this->user->getPassword()));
        $this->assertFalse(password_verify(self::AUTH_PASSWORD, $this->user->getPassword()));
        $this->assertSame(1, $this->flushes);
        $this->assertSame(1, $this->session->regenerated);
        $this->assertSame(0, $this->session->destroyed);
        $this->assertSame($this->user, $this->auth->user());
    }

    /**
     * Invalid changes: field overrides, the field in error and its message.
     *
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'missing current' => [['current_password' => ''], 'current_password', 'Enter your current password.'],
            'wrong current' => [
                ['current_password' => 'not my password'],
                'current_password',
                'Your current password is incorrect.',
            ],
            'too short' => [
                ['new_password' => 'short', 'confirm_password' => 'short'],
                'new_password',
                'Use at least 10 characters.',
            ],
            'same as current' => [
                ['new_password' => self::AUTH_PASSWORD, 'confirm_password' => self::AUTH_PASSWORD],
                'new_password',
                'Choose a password different from your current one.',
            ],
            'mismatch' => [['confirm_password' => 'something else'], 'confirm_password', 'The passwords do not match.'],
        ];
    }

    /**
     * Invalid changes re-render with a 422 and the field error, change nothing and never echo passwords.
     *
     * @param array<string, string> $override
     */
    #[DataProvider('invalidProvider')]
    public function testUpdatePasswordRejects(array $override, string $field, string $message): void
    {
        $hash = $this->user->getPassword();

        $response = $this->controller->updatePassword($this->post($override + $this->valid()));
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('id="' . $field . '-error">' . $message . '</div>', $body);
        $this->assertMatchesRegularExpression('#name="' . $field . '"[^>]*\s+aria-invalid="true"#', $body);
        $this->assertStringNotContainsString('a brand new password', $body);
        $this->assertStringNotContainsString(self::AUTH_PASSWORD, $body);
        $this->assertSame($hash, $this->user->getPassword());
        $this->assertSame(0, $this->flushes);
        $this->assertSame(0, $this->session->regenerated);
    }

    /**
     * Passwords are taken as typed: surrounding spaces are part of them.
     */
    public function testPasswordsAreNotTrimmed(): void
    {
        $response = $this->controller->updatePassword($this->post([
            'current_password' => ' ' . self::AUTH_PASSWORD,
        ] + $this->valid()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Your current password is incorrect.', $this->body($response));
    }

    /**
     * A valid submission: current password and a matching new one.
     *
     * @return array<string, string>
     */
    private function valid(): array
    {
        return [
            'current_password' => self::AUTH_PASSWORD,
            'new_password' => 'a brand new password',
            'confirm_password' => 'a brand new password',
        ];
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
     * Build a GET request for the form.
     *
     * @param array<string, string> $query
     */
    private function get(array $query = []): ServerRequest
    {
        return (new ServerRequest('GET', '/account/password'))->withQueryParams($query);
    }

    /**
     * Build a POST request with form fields.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): ServerRequest
    {
        return (new ServerRequest('POST', '/account/password'))->withParsedBody($fields);
    }

    /**
     * Response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
