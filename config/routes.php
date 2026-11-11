<?php

use App\Auth\AuthMiddleware;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CategoriesController;
use App\Controllers\CustomSkusController;
use App\Controllers\EbayController;
use App\Controllers\FeedbackController;
use App\Controllers\IndexController;
use App\Controllers\OrdersController;
use App\Controllers\ProductController;
use App\Controllers\PurchasesController;
use App\Controllers\ReportsController;
use League\Route\Router;

return function (Router $router) {

    // Every page requires a logged-in user except those in AuthMiddleware::PUBLIC_PATHS.
    $router->lazyMiddleware(AuthMiddleware::class);

    $router->get('/login', [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout']);
    $router->get('/account/password', [AccountController::class, 'passwordForm']);
    $router->post('/account/password', [AccountController::class, 'updatePassword']);

    $router->get('/', [IndexController::class, 'index']);

    $router->get('/products', [ProductController::class, 'index']);
    $router->get('/products/create', [ProductController::class, 'create']);
    $router->post('/products', [ProductController::class, 'store']);
    $router->get('/product/{id:number}', [ProductController::class, 'show']);
    $router->get('/product/{id:number}/edit', [ProductController::class, 'edit']);
    $router->post('/product/{id:number}', [ProductController::class, 'update']);
    $router->post('/product/{id:number}/delete', [ProductController::class, 'delete']);

    $router->get('/categories', [CategoriesController::class, 'index']);
    $router->get('/categories/create', [CategoriesController::class, 'create']);
    $router->post('/categories', [CategoriesController::class, 'store']);
    $router->get('/categories/{id:number}', [CategoriesController::class, 'show']);
    $router->get('/categories/{id:number}/edit', [CategoriesController::class, 'edit']);
    $router->post('/categories/{id:number}', [CategoriesController::class, 'update']);
    $router->post('/categories/{id:number}/delete', [CategoriesController::class, 'delete']);

    $router->get('/custom-skus', [CustomSkusController::class, 'index']);
    $router->get('/custom-skus/create', [CustomSkusController::class, 'create']);
    $router->post('/custom-skus', [CustomSkusController::class, 'store']);
    $router->get('/custom-skus/{id:number}', [CustomSkusController::class, 'show']);
    $router->get('/custom-skus/{id:number}/edit', [CustomSkusController::class, 'edit']);
    $router->post('/custom-skus/{id:number}', [CustomSkusController::class, 'update']);
    $router->post('/custom-skus/{id:number}/delete', [CustomSkusController::class, 'delete']);
    $router->post('/custom-skus/{id:number}/products', [CustomSkusController::class, 'addLine']);
    $router->get('/custom-skus/{id:number}/products/{lineId:number}/edit', [CustomSkusController::class, 'editLine']);
    $router->post('/custom-skus/{id:number}/products/{lineId:number}', [CustomSkusController::class, 'updateLine']);
    $router->post(
        '/custom-skus/{id:number}/products/{lineId:number}/delete',
        [CustomSkusController::class, 'removeLine']
    );

    $router->get('/orders', [OrdersController::class, 'index']);
    $router->get('/orders/create', [OrdersController::class, 'create']);
    $router->post('/orders', [OrdersController::class, 'store']);
    $router->get('/orders/{id:number}', [OrdersController::class, 'show']);
    $router->get('/orders/{id:number}/edit', [OrdersController::class, 'edit']);
    $router->post('/orders/{id:number}', [OrdersController::class, 'update']);
    $router->post('/orders/{id:number}/delete', [OrdersController::class, 'delete']);
    $router->post('/orders/{id:number}/items', [OrdersController::class, 'addItem']);
    $router->get('/orders/{id:number}/items/{itemId:number}/edit', [OrdersController::class, 'editItem']);
    $router->post('/orders/{id:number}/items/{itemId:number}', [OrdersController::class, 'updateItem']);
    $router->post('/orders/{id:number}/items/{itemId:number}/delete', [OrdersController::class, 'removeItem']);

    $router->get('/purchases', [PurchasesController::class, 'index']);
    $router->get('/purchases/create', [PurchasesController::class, 'create']);
    $router->post('/purchases', [PurchasesController::class, 'store']);
    $router->post('/purchases/receive', [PurchasesController::class, 'receiveSelected']);
    $router->get('/purchases/{id:number}/edit', [PurchasesController::class, 'edit']);
    $router->post('/purchases/{id:number}', [PurchasesController::class, 'update']);
    $router->post('/purchases/{id:number}/receive', [PurchasesController::class, 'receive']);
    $router->post('/purchases/{id:number}/delete', [PurchasesController::class, 'delete']);

    $router->get('/reports', [ReportsController::class, 'index']);

    $router->get('/feedbacks', [FeedbackController::class, 'index']);
    $router->post('/feedbacks/import', [FeedbackController::class, 'import']);

    $router->get('/ebay', [EbayController::class, 'index']);
    $router->get('/ebay/connect', [EbayController::class, 'connect']);
    $router->get('/ebay/callback', [EbayController::class, 'callback']);
    $router->post('/ebay/code', [EbayController::class, 'submitCode']);
    $router->post('/ebay/disconnect', [EbayController::class, 'disconnect']);
    $router->post('/ebay/orders/{orderId:[0-9A-Za-z-]+}', [EbayController::class, 'importOrder']);
    $router->post('/ebay/tracking', [EbayController::class, 'syncTracking']);
    $router->post('/ebay/feedback', [EbayController::class, 'importFeedback']);
};
