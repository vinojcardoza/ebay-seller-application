<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Class IndexController
 *
 * @package App\Controllers
 */
class IndexController
{
    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $factory = new Psr17Factory();
        $stream = $factory->createStream('Home page');

        $response = $factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
