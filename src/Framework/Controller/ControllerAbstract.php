<?php

declare(strict_types=1);

namespace App\Framework\Controller;

use App\Framework\Template\RendererInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use DI\Attribute\Inject;

/**
 * Abstract Class ControllerAbstract
 */
abstract class ControllerAbstract
{
    #[Inject]
    private readonly RendererInterface $renderer;

    #[Inject]
    private readonly StreamFactoryInterface $stream;

    #[Inject]
    private readonly ResponseFactoryInterface $factory;

    protected function render(string $template, array $args = []): ResponseInterface
    {
        $content = $this->renderer->render($template, $args);

        $stream = $this->stream->createStream($content);

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
