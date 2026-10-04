<?php

declare(strict_types=1);

use App\Controllers\IndexController;
use App\Controllers\ProductController;
use GuzzleHttp\Psr7\ServerRequest;
use HttpSoft\Emitter\SapiEmitter;
use League\Route\Router;

define("APP_ROOT", dirname(__DIR__));

ini_set("display_errors", 1);

require_once APP_ROOT . '/vendor/autoload.php';

$request = ServerRequest::fromGlobals();

$router = new Router();

$router->get('/', [IndexController::class, 'index']);
$router->get('/product', [ProductController::class, 'index']);
$router->get('/product/{id:number}', [ProductController::class, 'show']);

$response = $router->dispatch($request);

$emitter = new SapiEmitter();

$emitter->emit($response);
