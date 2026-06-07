<?php

namespace Lily\Http;

use Lily\Foundation\Application;
use Lily\Routing\Router;

/**
 * Core kernel for handling HTTP requests.
 */
class Kernel
{
    /**
     * The application instance.
     *
     * @var Application
     */
    protected Application $app;

    /**
     * The router instance.
     *
     * @var Router
     */
    protected Router $router;

    /**
     * The application's global HTTP middleware stack.
     *
     * @var array<int, string>
     */
    protected array $middleware = [
        \Lily\Http\Middleware\RateLimitMiddleware::class,
        \Lily\Http\Middleware\HotReloadMiddleware::class,
        \Lily\Http\Middleware\HotEyesMiddleware::class,
    ];

    /**
     * Create a new HTTP kernel instance.
     *
     * @param Application $app
     * @param Router $router
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;
    }

    /**
     * Handle an incoming HTTP request.
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return $this->sendRequestThroughRouter($request);
    }

    /**
     * Send the given request through the middleware / router.
     *
     * @param Request $request
     * @return Response
     */
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

    /**
     * Add a new middleware to the application's global HTTP middleware stack.
     *
     * @param string $middleware
     * @return void
     */
    public function addMiddleware(string $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}
