<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Class IndexController
 *
 * @package App\Controllers
 */
class IndexController
{
    /**
     * MARK: Constructor
     */
    public function __construct(
        private ResponseFactoryInterface $factory,
        private StreamFactoryInterface $stream
    ) {
    }

    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $stream = $this->stream->createStream('Home page');

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
