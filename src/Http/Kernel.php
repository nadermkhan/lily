<?php

namespace Lily\Http;

use Lily\Foundation\Application;
use Lily\Routing\Router;

class Kernel
{
    protected Application $app;
    protected Router $router;
    protected array $middleware = [
        \Lily\Http\Middleware\RateLimitMiddleware::class,
        \Lily\Http\Middleware\HotReloadMiddleware::class,
        \Lily\Http\Middleware\HotEyesMiddleware::class,
    ];

    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        return $this->sendRequestThroughRouter($request);
    }

    protected function sendRequestThroughRouter(Request $request): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function ($next, $middleware) {
                return function ($request) use ($next, $middleware) {
                    $instance = $this->app->get($middleware);
                    return $instance->handle($request, $next);
                };
            },
            function ($request) {
                return $this->router->dispatch($request);
            }
        );

        return $pipeline($request);
    }

    public function addMiddleware(string $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}
