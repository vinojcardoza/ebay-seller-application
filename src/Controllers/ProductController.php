<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
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
class ProductController extends ControllerAbstract
{
    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $repository = $this->em->getRepository(Product::class);

        $products = $repository->findAll();

        return $this->render('/product/index', [
            'products' => $products
        ]);
    }

    /**
     * @param ServerRequestInterface $request
     * @param array $args
     *
     * @return ResponseInterface
     */
    public function show(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        return $this->render('/product/show', $args);
    }
}
