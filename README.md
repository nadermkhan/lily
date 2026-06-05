# The Lily Framework

Lily is a robust, PSR-4 compliant, fully decoupled PHP MVC framework engineered from the ground up to eradicate static state and embrace modern architectural paradigms.

## Architecture & Features
- **Inversion of Control**: Centralized Dependency Injection Container managing class instantiation and singleton bindings.
- **Strict HTTP Abstraction**: Isolation of all superglobals (`$_GET`, `$_POST`, etc.) into robust `Request` and `Response` objects.
- **Middleware Pipeline**: Onion-architecture HTTP Kernel pipeline.
- **Front Controller**: Secure execution path originating exclusively from `public/index.php`.

## Requirements
- PHP >= 8.0

## Directory Structure
- `app/` - Application logic (Controllers, Models, Middleware).
- `src/` - Core Framework Logic (Lily Kernel).
- `public/` - Front Controller & Assets.
- `database/` - Migrations.

## Routing
Define routes in `app/routes.php`:
```php
$router->get('/', [HomeController::class, 'index']);
```

## Dependency Injection
Bindings can be configured within Service Providers inside the `app/Providers` directory. The container supports auto-resolution via reflection.
```php
$app->singleton(MyService::class, fn($app) => new MyService());
```

## CLI Console
Lily includes an integrated command-line tool `lily` for automation and scaffolding.

### Usage
```bash
php lily make:controller UserController
php lily make:model User
php lily make:migration create_users_table
```
