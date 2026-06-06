# Lily Framework - LLM Developer Guide

This document is optimized to provide LLMs (Claude, Gemini, ChatGPT) with the critical context needed to write, debug, and understand code in the Lily Framework, without consuming excessive tokens.

## 1. Core Philosophy
- **Zero Dependencies**: Lily does NOT use Composer or the `vendor/` directory. It relies on a native `autoload.php`.
- **Eradicate Static State**: The framework relies entirely on Dependency Injection (DI) through its Container.
- **Stems (Not Facades)**: Lily uses "Stems" (e.g., `Route::get()`) as a native, elegant proxy to instances in the DI Container.

## 2. Directory Structure
- `src/` - Framework Core (`Lily\` namespace)
- `app/` - Application logic (`App\` namespace: Controllers, Middleware, Providers)
- `public/` - Web root (`index.php`)
- `tests/` - QA and unit tests (`Tests\` namespace)
- `lily` - CLI Executable (Run via `php lily <command>`)

## 3. Dependency Injection (DI) & Application
The `Lily\Foundation\Application` class extends `Lily\Container\Container`. It acts as the central registry.
```php
// Binding an instance
$app->singleton(MyService::class, fn() => new MyService());
// Resolving
$service = $app->get(MyService::class);
```

## 4. Stems (Facade Alternative)
Stems provide static access to underlying container instances via `__callStatic()`.
- Base Class: `Lily\Support\Stem`
- Available Stems: `Lily\Support\Stems\Route`
- Usage: Replace injected variables (like `$router->get()`) with `Route::get()`.

## 5. Routing (`app/routes.php`)
Routing is fluent and supports advanced subdomain matching.
```php
use Lily\Support\Stems\Route;
use App\Controllers\HomeController;
use Lily\Http\Request;
use Lily\Http\Response;

// Basic Route
Route::get('/', [HomeController::class, 'index']);

// Subdomain Route (Exact Match)
Route::on('api.example.com')->get('/users', fn() => new Response('API'));

// Subdomain Route (Wildcard)
Route::on('*.example.com')->get('/tenant', fn() => new Response('Tenant'));

// Subdomain Exclusion
Route::except('admin.*')->get('/public', fn() => new Response('Public'));

// SPA (Single Page Application) Catch-All Route
// Use the `{path*}` wildcard parameter at the end of the route to catch all sub-routes for frontend routers (Vue/React).
Route::get('/app/{path*}', function (Request $request) {
    // Return the SPA's index.html
    return new Response(file_get_contents(__DIR__ . '/../public/index.html'));
});

// Routing to a View or specific .php file
Route::get('/legacy', function (Request $request) {
    // For simple PHP views without controllers
    ob_start();
    require __DIR__ . '/../resources/views/legacy.php';
    return new Response(ob_get_clean());
});
```

## 6. HTTP Layer (Request & Response)
Controllers MUST return a `Lily\Http\Response` object.
```php
namespace App\Controllers;
use Lily\Http\Request;
use Lily\Http\Response;

class HomeController {
    public function index(Request $request): Response {
        // Access attributes injected by middleware
        $variant = $request->getAttribute('X_EXPERIMENT_VARIANT');
        
        // Parse incoming JSON payload
        $payload = $request->json(); // Gets decoded php://input array
        $userId = $request->json('user_id'); // Plucks specific key

        // Return a JSON Response natively
        return Response::json([
            'message' => "Hello. Variant: " . $variant,
            'received_id' => $userId
        ]);
    }
}
```

## 7. Middleware
Middleware wraps the request lifecycle. It must call the `$next` closure.
```php
namespace App\Http\Middleware;
use Lily\Http\Request;

class ExampleMiddleware {
    public function handle(Request $request, \Closure $next) {
        $request->setAttribute('X_TEST', true);
        return $next($request);
    }
}
```

## 8. Security & CSP
Lily provides dedicated classes to harden security:
- `Lily\Security\SecurityManager`: Handles CSRF token generation/validation, IP resolution, and base security headers.
- `Lily\Security\CspBuilder`: Fluent builder for Content Security Policy.
```php
use Lily\Security\CspBuilder;
use Lily\Http\Response;

$csp = new CspBuilder();
$csp->add('default-src', "'self'")
    ->add('script-src', "'self' 'unsafe-inline' https://trusted.cdn.com");

$response = new Response('Secure Page');
$response->setHeader('Content-Security-Policy', $csp->build());
```

## 9. Database & Factories
- **Database Engine**: `Lily\Database\Db` handles raw querying.
- **QA Factories**: `Lily\Database\Factory` enables rapid database seeding.
```php
$factory = new class($db) extends \Lily\Database\Factory {
    protected string $table = 'users';
    public function definition(): array {
        return ['name' => 'Mock User', 'email' => 'mock@test.com'];
    }
};
$factory->create(3); // Inserts 3 records
```

## 10. A/B Testing Engine
Lily natively supports A/B testing via `Lily\Testing\ExperimentManager` and `ExperimentTrafficSplitter`.
- **Assignment**: `resolveVariant('exp_name', ['A', 'B'], [50, 50])`
- **Analytics**: Auto-logs via `Lily\Services\AnalyticsEngine`
- **Middleware**: Middleware reads/sets signed cookies (`lily_exp_*`) and passes the variant to the Controller via `$request->setAttribute()`.

## 11. CLI (The `lily` executable)
Run `php lily <command>` from the project root.
- `php lily serve` - Starts dev server.
- `php lily config:generate` - Generates `.htaccess`, `nginx.conf`, `web.config` in `public/`.
- `php lily make:controller Name` - Scaffolds a Controller.
- `php lily queue:work` - Starts the background job queue worker.
- `php lily tinker` - Starts an interactive PHP REPL shell.
- `php lily migrate:diff` - Auto-diff models against the SQLite DB and generate a migration.
- `php lily bolt:compile` - Manually compile the OPcache flat-file for Bolt API tokens.
- `php lily push` / `php lily pull` - Sync files via FTP (development mode only).

## LLM Directive
When writing code for Lily:
1. NEVER `require 'vendor/autoload.php'`. The autoloader is native at `/autoload.php`.
2. NEVER use Laravel's `Illuminate` classes or Facades. Use Lily's native implementations and Stems.
3. Keep code fully PSR-4 compliant and heavily decoupled.
