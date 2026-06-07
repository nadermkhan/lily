<?php

namespace Lily\Routing;

/**
 * Provides a scoped interface for defining routes.
 */
class RouteScope
{
    /**
     * Create a new RouteScope instance.
     *
     * @param Router $router The router instance.
     * @param array $allowHosts The list of allowed hosts.
     * @param array $blockHosts The list of blocked hosts.
     */
    public function __construct(
        private Router $router,
        private array $allowHosts = [],
        private array $blockHosts = []
    ) {}

    /**
     * Register a new GET route within this scope.
     *
     * @param string $uri The URI pattern.
     * @param mixed $action The route action.
     * @return void
     */
    public function get(string $uri, mixed $action): void
    {
        $this->router->addRoute('GET', $uri, $action, $this->allowHosts, $this->blockHosts);
    }

    /**
     * Register a new POST route within this scope.
     *
     * @param string $uri The URI pattern.
     * @param mixed $action The route action.
     * @return void
     */
    public function post(string $uri, mixed $action): void
    {
        $this->router->addRoute('POST', $uri, $action, $this->allowHosts, $this->blockHosts);
    }
}
