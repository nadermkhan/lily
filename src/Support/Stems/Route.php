<?php

namespace Lily\Support\Stems;

use Lily\Support\Stem;

/**
 * The Route stem (facade) for registering application routes.
 * 
 * @method static \Lily\Routing\RouteScope on(string|array $hosts)
 * @method static \Lily\Routing\RouteScope except(string|array $hosts)
 * @method static void get(string $uri, mixed $action)
 * @method static void post(string $uri, mixed $action)
 *
 * @see \Lily\Routing\Router
 */
class Route extends Stem
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getStemAccessor(): string
    {
        return \Lily\Routing\Router::class;
    }
}
