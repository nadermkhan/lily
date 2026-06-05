<?php

require_once __DIR__ . '/../autoload.php';

use Lily\Http\Request;
use Lily\Foundation\Application;
use Lily\Routing\Router;
use Lily\Http\Kernel;

// Initialize the Application container
$app = new Application(dirname(__DIR__));

// Bind core services
$app->singleton(Router::class, function () {
    return new Router();
});

// Load application routes
require_once __DIR__ . '/../app/routes.php';

// Capture the incoming HTTP request
$request = Request::capture();

// Resolve Kernel and handle request
$kernel = $app->get(Kernel::class);
$response = $kernel->handle($request);

// Send the response back to the client
$response->send();
