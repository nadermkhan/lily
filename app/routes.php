<?php

/** @var \Lily\Routing\Router $router */

use App\Controllers\HomeController;
use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Support\Stems\Route;

Route::get('/', [HomeController::class, 'index']);

Route::get('/hello', function (Request $request) {
    return new Response('Hello World from Lily!');
});
