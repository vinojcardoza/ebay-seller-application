<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Authenticator;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Login form, login and logout. After logging in the user returns to the page
 * they asked for (`next`), which must be a path on this site.
 */
class AuthController extends ControllerAbstract
{
    /**
     * Create the controller.
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * Show the login form, or go straight on when already logged in.
     */
    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $next = $this->safeNext($query['next'] ?? null);

        if ($this->auth->user() !== null) {
            return $this->redirect($next);
        }

        return $this->renderLogin('', $next, null, ($query['done'] ?? null) === 'logged-out'
            ? 'You have been logged out.' : null);
    }

    /**
     * Log in and redirect to `next`; 422 with an error when the fields are empty or the credentials are wrong.
     */
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $login = is_array($body) && is_string($body['login'] ?? null) ? trim($body['login']) : '';
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        $next = $this->safeNext(is_array($body) ? $body['next'] ?? null : null);

        if ($login === '' || $password === '') {
            return $this->renderLogin($login, $next, 'Enter your login and password.');
        }

        if ($this->auth->attempt($login, $password) === null) {
            return $this->renderLogin($login, $next, 'Incorrect login or password.');
        }

        return $this->redirect($next);
    }

    /**
     * Log out and return to the login page.
     */
    public function logout(): ResponseInterface
    {
        $this->auth->logout();

        return $this->redirect('/login?done=logged-out');
    }

    /**
     * Render the login page; 422 when there is an error.
     */
    private function renderLogin(string $login, string $next, ?string $error, ?string $notice = null): ResponseInterface
    {
        return $this->render('auth/login', [
            'login' => mb_substr($login, 0, 191),
            'next' => $next,
            'error' => $error,
            'notice' => $notice,
        ], $error === null ? 200 : 422)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A local path to return to after login, or "/". Rejects other sites
     * ("//host", "/\host", full URLs), control characters and the login page itself.
     */
    private function safeNext(mixed $value): string
    {
        if (
            !is_string($value)
            || strlen($value) > 2000
            || !str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_starts_with($value, '/login')
        ) {
            return '/';
        }

        return $value;
    }
}
