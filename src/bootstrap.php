<?php

declare(strict_types=1);

use App\Controllers\IndexController;
use App\Controllers\ProductController;
use GuzzleHttp\Psr7\ServerRequest;
use HttpSoft\Emitter\SapiEmitter;
use Psr\Http\Message\ResponseFactoryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;
use Psr\Http\Message\StreamFactoryInterface;

define("APP_ROOT", dirname(__DIR__));

ini_set("display_errors", 1);

require_once APP_ROOT . '/vendor/autoload.php';

$request = ServerRequest::fromGlobals();

$container = new DI\Container([
    StreamFactoryInterface::class => DI\create(Psr17Factory::class),
    ResponseFactoryInterface::class => DI\create(Psr17Factory::class)
]);
$controller = $container->get(IndexController::class);

$router = new Router();

// Application strategy will say to use the
// container autoloaded with dependency injection
$strategy = new ApplicationStrategy();
$strategy->setContainer($container);
$router->setStrategy($strategy);

$router->get('/', [IndexController::class, 'index']);
$router->get('/products', [ProductController::class, 'index']);
$router->get('/product/{id:number}', [ProductController::class, 'show']);

$response = $router->dispatch($request);

$emitter = new SapiEmitter();

$emitter->emit($response);
