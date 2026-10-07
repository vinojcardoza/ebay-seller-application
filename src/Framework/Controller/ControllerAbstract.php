<?php

declare(strict_types=1);

namespace App\Framework\Controller;

use App\Framework\Template\RendererInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Abstract Class ControllerAbstract
 */
abstract class ControllerAbstract
{
    public function __construct(
        private RendererInterface $renderer,
        private StreamFactoryInterface $stream,
        private ResponseFactoryInterface $factory
    ) {
    }

    protected function render(string $template, array $args = []): ResponseInterface
    {
        $content = $this->renderer->render($template, $args);

        $stream = $this->stream->createStream($content);

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
