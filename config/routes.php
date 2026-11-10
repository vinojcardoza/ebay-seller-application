<?php

use App\Controllers\CategoriesController;
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
};
