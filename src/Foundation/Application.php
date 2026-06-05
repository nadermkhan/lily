<?php

namespace Lily\Foundation;

use Lily\Container\Container;
use Lily\Routing\Router;
use Lily\Http\Request;
use Lily\Support\Env;
use ErrorException;

class Application extends Container
{
    protected static ?Application $instance = null;
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->registerBaseBindings();
        $this->initErrorHandling();
    }

    protected function initErrorHandling(): void
    {
        // Type Safety / Null Safety Switch
        $strict = Env::get('STRICT_TYPE_SAFETY', true);
        $strict = is_string($strict) ? strtolower($strict) === 'true' : (bool)$strict;

        if ($strict) {
            set_error_handler(function (int $severity, string $message, string $file, int $line) {
                if (!(error_reporting() & $severity)) {
                    // This error code is not included in error_reporting, so let it fall
                    // through to the standard PHP error handler
                    return false;
                }
                throw new ErrorException($message, 0, $severity, $file, $line);
            });
        }
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
