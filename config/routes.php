<?php

use App\Controllers\IndexController;
use App\Controllers\ProductController;
use League\Route\Router;

return function (Router $router) {

    $router->get('/', [IndexController::class, 'index']);

    $router->get('/products', [ProductController::class, 'index']);

    $router->get('/product/{id:number}', [ProductController::class, 'show']);
};
