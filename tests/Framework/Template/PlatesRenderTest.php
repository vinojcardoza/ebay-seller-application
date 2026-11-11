<?php

declare(strict_types=1);

namespace Tests\Framework\Template;

use App\Framework\Template\PlatesRender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthFixture;

/**
 * Tests that the renderer shares the logged-in user with the layout header.
 */
#[CoversClass(PlatesRender::class)]
final class PlatesRenderTest extends TestCase
{
    use AuthFixture;

    /**
     * Build the fixture user, session and authenticator.
     */
    protected function setUp(): void
    {
        $this->setUpAuth();
    }

    /**
     * When logged in, the header shows the user's name and a logout button.
     */
    public function testHeaderShowsLoggedInUser(): void
    {
        $this->logIn();

        $html = (new PlatesRender($this->auth))->render('errors/404', ['message' => 'Gone']);

        $this->assertStringContainsString('<span class="app-user-name">Vinoj Cardoza</span>', $html);
        $this->assertStringContainsString('<form method="post" action="/logout"', $html);
    }

    /**
     * A user without a full name is shown by login.
     */
    public function testHeaderFallsBackToLogin(): void
    {
        $this->logIn();
        $this->user->setFullName(null);

        $html = (new PlatesRender($this->auth))->render('errors/404', ['message' => 'Gone']);

        $this->assertStringContainsString('<span class="app-user-name">vinoj</span>', $html);
    }

    /**
     * Without a user (or an authenticator) the header has no user name or logout button.
     */
    public function testHeaderWithoutUser(): void
    {
        foreach ([new PlatesRender($this->auth), new PlatesRender()] as $renderer) {
            $html = $renderer->render('errors/404', ['message' => 'Gone']);

            $this->assertStringNotContainsString('app-user-name', $html);
            $this->assertStringNotContainsString('/logout', $html);
        }
    }
}
