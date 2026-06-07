<?php

namespace Lily\Support;

use Lily\Foundation\Application;
use RuntimeException;

/**
 * Base class for all Stem facades.
 * 
 * Provides static access to instances bound in the application container.
 */
abstract class Stem
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function getStemAccessor(): string
    {
        throw new RuntimeException('Stem does not implement getStemAccessor method.');
    }

    /**
     * Resolve the underlying instance from the container.
     *
     * @return mixed
     *
     * @throws \RuntimeException
     */
    protected static function resolveStemInstance()
    {
        $accessor = static::getStemAccessor();
        $app = Application::getInstance();

        if (!$app) {
            throw new RuntimeException('A Stem root has not been set. Application instance is missing.');
        }

        return $app->get($accessor);
    }

    /**
     * Handle dynamic, static calls to the object.
     *
     * @param  string  $method
     * @param  array   $args
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public static function __callStatic(string $method, array $args)
    {
        $instance = static::resolveStemInstance();

        if (!$instance) {
            throw new RuntimeException('A Stem root has not been resolved.');
        }

        return $instance->$method(...$args);
    }
}
