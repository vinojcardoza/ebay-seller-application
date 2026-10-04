<?php

declare(strict_types=1);

namespace App\Controllers;

use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\Response;

/**
 * Class IndexController
 *
 * @package App\Controllers
 */
class IndexController
{
    /**
     * @return Response
     */
    public function index(): Response
    {
        $stream = Utils::streamFor('Home page');

        $response = new Response();

        $response = $response->withBody($stream);

        return $response;
    }
}
