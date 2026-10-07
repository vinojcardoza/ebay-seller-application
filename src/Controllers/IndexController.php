<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Framework\Template\RendererInterface;
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
        private StreamFactoryInterface $stream,
        private RendererInterface $renderer
    ) {
    }

    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $content = $this->renderer->render('index/index', [
            'name' => 'Vinoj Cardoza'
            ]);

        $stream = $this->stream->createStream($content);

        $response = $this->factory->createResponse();

        $response = $response->withBody($stream);

        return $response;
    }
}
