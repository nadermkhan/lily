<?php

namespace Lily\Foundation;

use Lily\Container\Container;
use Lily\Routing\Router;
use Lily\Http\Request;

class Application extends Container
{
    protected static ?Application $instance = null;
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->registerBaseBindings();
    }

    protected function registerBaseBindings(): void
    {
        static::setInstance($this);
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
    }

    public static function setInstance(?Application $app): void
    {
        static::$instance = $app;
    }

    public static function getInstance(): ?Application
    {
        return static::$instance;
    }
    
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
