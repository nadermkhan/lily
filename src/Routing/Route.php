<?php

namespace Lily\Routing;

/**
 * Represents a registered route.
 */
class Route
{
    /**
     * Create a new Route instance.
     *
     * @param string $method The HTTP method.
     * @param string $uri The URI pattern.
     * @param mixed $action The route action.
     */
    public function __construct(
        public string $method,
        public string $uri,
        public mixed $action
    ) {}
}
