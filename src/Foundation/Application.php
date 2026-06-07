<?php

namespace Lily\Foundation;

use Lily\Container\Container;
use Lily\Routing\Router;
use Lily\Http\Request;
use Lily\Support\Env;
use ErrorException;

/**
 * The core Application class for the Lily framework.
 * 
 * This class extends the dependency injection Container and serves as the 
 * central registry and orchestrator for the application, handling basic 
 * environment setup and error handling.
 */
class Application extends Container
{
    /**
     * The singleton instance of the application.
     *
     * @var \Lily\Foundation\Application|null
     */
    protected static ?Application $instance = null;

    /**
     * The base path of the application installation.
     *
     * @var string
     */
    private string $basePath;

    /**
     * Create a new Application instance.
     *
     * @param string $basePath The base path of the application.
     * @return void
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '\/');
        
        // Auto-load environment variables globally
        if (!empty($this->basePath)) {
            Env::load($this->basePath . '/.env');
        }

        $this->registerBaseBindings();
        $this->initErrorHandling();
    }

    /**
     * Initialize the application's error handling.
     * 
     * Sets up a custom error handler that throws ErrorExceptions 
     * based on strict type safety settings in the environment.
     *
     * @return void
     */
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

    /**
     * Register the basic bindings into the container.
     * 
     * Binds the application instance to itself and the Container interface.
     *
     * @return void
     */
    protected function registerBaseBindings(): void
    {
        static::setInstance($this);
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Set the globally available instance of the application.
     *
     * @param \Lily\Foundation\Application|null $app The application instance.
     * @return void
     */
    public static function setInstance(?Application $app): void
    {
        static::$instance = $app;
    }

    /**
     * Get the globally available instance of the application.
     *
     * @return \Lily\Foundation\Application|null
     */
    public static function getInstance(): ?Application
    {
        return static::$instance;
    }
    
    /**
     * Get the base path of the application.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
