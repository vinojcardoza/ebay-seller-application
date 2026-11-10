<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Product list and detail pages.
 */
class ProductController extends ControllerAbstract
{
    /**
     * List all products, ordered by title.
     */
    public function index(): ResponseInterface
    {
        $products = $this->em->getRepository(Product::class)->findBy([], ['title' => 'ASC']);

        return $this->render('product/index', [
            'products' => $products,
        ]);
    }

    /**
     * Show a single product.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function show(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $product = $this->em->getRepository(Product::class)->find((int) ($args['id'] ?? 0));

        if ($product === null) {
            return $this->notFound('Product not found.');
        }

        return $this->render('product/show', [
            'product' => $product,
        ]);
    }
}
