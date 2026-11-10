<?php

declare(strict_types=1);

namespace App\Framework\Controller;

use App\Framework\Template\RendererInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Base class for controllers, providing template rendering, redirects and
 * access to the Doctrine entity manager.
 */
abstract class ControllerAbstract
{
    /**
     * Receive the shared services every controller needs from the container.
     */
    public function __construct(
        private RendererInterface $renderer,
        private StreamFactoryInterface $stream,
        private ResponseFactoryInterface $factory,
        protected EntityManagerInterface $em
    ) {
    }

    /**
     * Render a view template into an HTML response with the given status code.
     */
    protected function render(string $template, array $args = [], int $status = 200): ResponseInterface
    {
        $content = $this->renderer->render($template, $args);

        $stream = $this->stream->createStream($content);

        $response = $this->factory->createResponse($status);

        $response = $response->withBody($stream);

        return $response;
    }

    /**
     * Build a redirect response to the given URL.
     */
    protected function redirect(string $url, int $status = 302): ResponseInterface
    {
        return $this->factory->createResponse($status)->withHeader('Location', $url);
    }

    /**
     * Render the 404 error page with an optional message.
     */
    protected function notFound(string $message = 'Page not found.'): ResponseInterface
    {
        return $this->render('errors/404', ['message' => $message], 404);
    }
}
