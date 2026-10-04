<?php

declare(strict_types=1);

namespace App\Controllers;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/**
 * Class ProductController
 *
 * @package App\Controllers
 */
class ProductController
{
    /**
     * @return Response
     */
    public function index(): Response
    {
        $stream = Utils::streamFor('Products Index');

        $response = new Response();

        $response = $response->withBody($stream);

        return $response;
    }

    public function show(ServerRequest $request, array $args = []): Response
    {
        $id = $args['id'];

        $stream = Utils::streamFor('Products Show ' . $id);

        $response = new Response();

        $response = $response->withBody($stream);

        return $response;
    }
}
