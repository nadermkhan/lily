<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Support\Env;
use Lily\Http\Controllers\HotReloadController;

class HotReloadMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Env::get('APP_ENV', 'development') !== 'development') {
            return $next($request);
        }

        // Intercept SSE endpoint route
        if ($request->server['REQUEST_URI'] === '/_hot-reload') {
            $controller = new HotReloadController();
            $controller->handle();
            exit; // SSE streams are infinite, exit when done
        }

        /** @var Response $response */
        $response = $next($request);

        // Inject SSE script into HTML responses
        $contentType = $response->headers['Content-Type'] ?? 'text/html';
        if (strpos($contentType, 'text/html') !== false) {
            $content = $response->getContent();
            
            $script = <<<HTML
<!-- Lily Hot Reload -->
<script>
    if (!!window.EventSource) {
        var source = new EventSource('/_hot-reload');
        source.onmessage = function(e) {
            if (e.data === 'reload') {
                console.log('Lily Hot-Reload: Changes detected, reloading...');
                window.location.reload();
            }
        };
    }
</script>
</body>
HTML;
            $content = str_replace('</body>', $script, $content);
            $response->setContent($content);
        }

        return $response;
    }
}
