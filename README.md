# The Lily Framework

Lily is a robust, zero-dependency, PSR-4 compliant PHP MVC framework engineered from the ground up to embrace modern architectural paradigms while remaining incredibly lightweight. It provides the elegance of large frameworks without the bloat of hundreds of third-party Composer packages.

## Installation

You can create a new Lily project instantly using Composer. Even though Lily relies on **zero** external dependencies, we use Composer for quick scaffolding:

```bash
composer create-project nader/lily my-app
cd my-app
php lily serve
```

*(Note: The setup process automatically generates your `.htaccess`, `nginx.conf`, and `web.config` files!)*

## Requirements
- PHP >= 8.2

## Architecture & Features
- **Zero Dependencies**: Lily does not rely on any third-party packages in its core. It uses a blazing fast, custom native autoloader.
- **Inversion of Control**: Centralized Dependency Injection Container managing class instantiation and singleton bindings.
- **Strict HTTP Abstraction**: Isolation of all superglobals (`$_GET`, `$_POST`, etc.) into robust `Request` and `Response` objects.
- **Middleware Pipeline**: Onion-architecture HTTP Kernel pipeline for filtering requests.
- **Stems**: An elegant, native alternative to Facades, allowing static-like access to DI container instances.

## Directory Structure
- `app/` - Application logic (Controllers, Models, Middleware, Providers).
- `src/` - Core Framework Logic (Lily Kernel).
- `public/` - Front Controller & Assets.
- `tests/` - QA and unit tests.

## Routing
Define routes elegantly in `app/routes.php` using the `Route` Stem, which proxies static calls to the underlying Router instance:
```php
use Lily\Support\Stems\Route;
use App\Controllers\HomeController;
use Lily\Http\Request;
use Lily\Http\Response;

// Basic routing
Route::get('/', [HomeController::class, 'index']);

// Subdomain routing
Route::on('api.*')->post('/data', function (Request $request) {
    return new Response('API Endpoint');
});
```

## Dependency Injection
Bindings can be configured within Service Providers inside the `app/Providers` directory. The container supports auto-resolution via reflection.
```php
$app->singleton(MyService::class, fn($app) => new MyService());
$service = $app->get(MyService::class);
```

## CLI Console
Lily includes an integrated command-line tool `lily` for automation and scaffolding.

### Usage
```bash
php lily serve
php lily config:generate
php lily make:controller UserController
```
