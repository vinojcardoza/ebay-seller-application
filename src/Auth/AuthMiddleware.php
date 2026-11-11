<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gates the application: requests without a logged-in user are redirected to
 * the login page, except for PUBLIC_PATHS. GET requests carry the page they
 * asked for as `?next=` so the user returns there after logging in.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Paths reachable without logging in.
     */
    public const PUBLIC_PATHS = ['/login'];

    /**
     * Create the middleware.
     */
    public function __construct(
        private readonly Authenticator $auth,
        private readonly ResponseFactoryInterface $factory,
    ) {
    }

    /**
     * Pass the request on when it is public or the user is logged in; otherwise redirect to /login.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();

        if (in_array($uri->getPath(), self::PUBLIC_PATHS, true) || $this->auth->user() !== null) {
            return $handler->handle($request);
        }

        $location = '/login';
        $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');

        if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && $target !== '/') {
            $location .= '?' . http_build_query(['next' => $target]);
        }

        return $this->factory->createResponse(302)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store');
    }
}
