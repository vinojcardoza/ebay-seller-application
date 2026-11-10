<?php

use App\Controllers\CategoriesController;
use App\Controllers\EbayController;
use App\Controllers\IndexController;
use App\Controllers\ProductController;
use League\Route\Router;

return function (Router $router) {

    $router->get('/', [IndexController::class, 'index']);

    $router->get('/products', [ProductController::class, 'index']);

    $router->get('/product/{id:number}', [ProductController::class, 'show']);

    $router->get('/categories', [CategoriesController::class, 'index']);
    $router->get('/categories/create', [CategoriesController::class, 'create']);
    $router->post('/categories', [CategoriesController::class, 'store']);
    $router->get('/categories/{id:number}', [CategoriesController::class, 'show']);
    $router->get('/categories/{id:number}/edit', [CategoriesController::class, 'edit']);
    $router->post('/categories/{id:number}', [CategoriesController::class, 'update']);
    $router->post('/categories/{id:number}/delete', [CategoriesController::class, 'delete']);

    $router->get('/ebay', [EbayController::class, 'index']);
    $router->get('/ebay/connect', [EbayController::class, 'connect']);
    $router->get('/ebay/callback', [EbayController::class, 'callback']);
    $router->post('/ebay/code', [EbayController::class, 'submitCode']);
    $router->post('/ebay/disconnect', [EbayController::class, 'disconnect']);
    $router->post('/ebay/orders/{orderId:[0-9A-Za-z-]+}', [EbayController::class, 'importOrder']);
    $router->post('/ebay/tracking', [EbayController::class, 'syncTracking']);
    $router->post('/ebay/feedback', [EbayController::class, 'importFeedback']);
};
