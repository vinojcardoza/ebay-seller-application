<?php

declare(strict_types=1);

namespace App\Controllers;

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
        private ResponseFactoryInterface $factory
    ) {
    }
    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $stream = $this->stream->createStream('Products Index');

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
        $id = $args['id'];

        $stream = $this->stream->createStream('Products Show ' . $id);

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
