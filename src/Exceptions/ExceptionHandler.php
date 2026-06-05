<?php

namespace Lily\Exceptions;

use Lily\Http\Request;
use Lily\Http\Response;
use Throwable;

class ExceptionHandler
{
    private bool $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    public function handle(Throwable $e, Request $request): Response
    {
        $statusCode = $this->isHttpException($e) ? $e->getCode() : 500;
        
        if ($statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        if ($this->debug) {
            $content = $this->renderDebugPage($e);
        } else {
            $content = $this->renderErrorPage($statusCode);
        }

        return new Response($content, $statusCode);
    }

    private function isHttpException(Throwable $e): bool
    {
        return $e->getCode() >= 400 && $e->getCode() <= 599;
    }

    private function renderDebugPage(Throwable $e): string
    {
        return "<h1>Fatal Error</h1><p><b>Message:</b> " . htmlspecialchars($e->getMessage()) . "</p>" .
               "<p><b>File:</b> " . $e->getFile() . ":" . $e->getLine() . "</p>" .
               "<pre>" . $e->getTraceAsString() . "</pre>";
    }

    private function renderErrorPage(int $statusCode): string
    {
        return "<h1>Error {$statusCode}</h1><p>Something went wrong.</p>";
    }
}
