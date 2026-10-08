<?php

declare(strict_types=1);

use App\Controllers\IndexController;
use App\Controllers\ProductController;
use GuzzleHttp\Psr7\ServerRequest;
use HttpSoft\Emitter\SapiEmitter;
use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;

define("APP_ROOT", dirname(__DIR__));

ini_set("display_errors", 1);

require_once APP_ROOT . '/vendor/autoload.php';

$request = ServerRequest::fromGlobals();

$builder = new DI\ContainerBuilder();

$builder->addDefinitions(dirname(__DIR__) . "/config/definitions.php");

$builder->useAttributes(true);

$container = $builder->build();

$router = new Router();

// Application strategy will say to use the
// container autoloaded with dependency injection
$strategy = new ApplicationStrategy();
$strategy->setContainer($container);
$router->setStrategy($strategy);

$routes = require dirname(__DIR__) . "/config/routes.php";
$routes($router);
$response = $router->dispatch($request);

$emitter = new SapiEmitter();

$emitter->emit($response);
