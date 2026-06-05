<?php

namespace Lily\Routing;

class RouteScope
{
    public function __construct(
        private Router $router,
        private array $allowHosts = [],
        private array $blockHosts = []
    ) {}

    public function get(string $uri, mixed $action): void
    {
        $this->router->addRoute('GET', $uri, $action, $this->allowHosts, $this->blockHosts);
    }

    public function post(string $uri, mixed $action): void
    {
        $this->router->addRoute('POST', $uri, $action, $this->allowHosts, $this->blockHosts);
    }
}
