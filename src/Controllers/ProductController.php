<?php

declare(strict_types=1);

namespace App\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class ProductController
 *
 * @package App\Controllers
 */
class ProductController
{
    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $factory = new Psr17Factory();

        $stream = $factory->createStream('Products Index');

        $response = $factory->createResponse();

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

        $factory = new Psr17Factory();

        $stream = $factory->createStream('Products Show ' . $id);

        $response = $factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
