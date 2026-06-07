<?php

namespace Lily\Container;

use ReflectionClass;
use ReflectionParameter;
use Exception;

/**
 * The dependency injection container.
 * 
 * Manages class dependencies and performs dependency injection.
 */
class Container
{
    /**
     * The container's shared instances.
     *
     * @var array
     */
    protected array $instances = [];

    /**
     * The container's bindings.
     *
     * @var array
     */
    protected array $bindings = [];

    /**
     * Register a shared binding in the container.
     *
     * @param string $abstract The abstract type to bind.
     * @param callable|string|null $concrete The concrete implementation.
     * @return void
     */
    public function singleton(string $abstract, callable|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => true];
    }

    /**
     * Register a binding with the container.
     *
     * @param string $abstract The abstract type to bind.
     * @param callable|string|null $concrete The concrete implementation.
     * @return void
     */
    public function bind(string $abstract, callable|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => false];
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param string $abstract The abstract type to bind.
     * @param mixed $instance The instance to register.
     * @return void
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract The abstract type to resolve.
     * @return mixed
     */
    public function get(string $abstract): mixed
    {
        return $this->resolve($abstract);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract The abstract type to resolve.
     * @return mixed
     */
    protected function resolve(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        if ($concrete instanceof \Closure) {
            $object = $concrete($this);
        } else {
            $object = $this->build($concrete);
        }

        if (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared']) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Instantiate a concrete instance of the given type.
     *
     * @param string $concrete The concrete type to build.
     * @return mixed
     * @throws \Exception
     */
    protected function build(string $concrete): mixed
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (\ReflectionException $e) {
            throw new Exception("Target class [$concrete] does not exist.", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new Exception("Target class [$concrete] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return new $concrete;
        }

        $dependencies = $constructor->getParameters();
        $instances = $this->resolveDependencies($dependencies);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param array $dependencies The dependencies to resolve.
     * @return array
     * @throws \Exception
     */
    protected function resolveDependencies(array $dependencies): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            /** @var ReflectionParameter $dependency */
            $type = $dependency->getType();
            if ($type && !$type->isBuiltin()) {
                $results[] = $this->resolve($type->getName());
            } else {
                if ($dependency->isDefaultValueAvailable()) {
                    $results[] = $dependency->getDefaultValue();
                } else {
                    throw new Exception("Unresolvable dependency resolving [$dependency] in class {$dependency->getDeclaringClass()->getName()}");
                }
            }
        }

        return $results;
    }
}
