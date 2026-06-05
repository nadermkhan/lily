<?php

namespace Lily\Routing;

class Route
{
    public function __construct(
        public string $method,
        public string $uri,
        public mixed $action
    ) {}
}
