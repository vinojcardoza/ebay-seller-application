<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Framework\Template\RendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Class ProductController
 *
 * @package App\Controllers
 */
class ProductController
{
    public function __construct(
        private StreamFactoryInterface $stream,
        private ResponseFactoryInterface $factory,
        private RendererInterface $renderer
    ) {
    }
    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $content = $this->renderer->render('/product/index');

        $stream = $this->stream->createStream($content);

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @param array $args
     *
     * @return ResponseInterface
     */
    public function show(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $content = $this->renderer->render('/product/show', $args);

        $stream = $this->stream->createStream($content);

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
