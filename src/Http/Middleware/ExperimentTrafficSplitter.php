<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Testing\ExperimentManager;

class ExperimentTrafficSplitter
{
    public function __construct(private ExperimentManager $experiments) {}

    public function handle(Request $request, \Closure $next)
    {
        $experimentId = 'homepage_cta_test';
        $cookieName = 'lily_exp_' . $experimentId;
        
        $variant = $request->cookies[$cookieName] ?? null;

        if (!$variant) {
            $variant = $this->experiments->resolveVariant($experimentId, ['A', 'B']);
            $this->experiments->logAssignment($experimentId, $variant);
            
            // Store the assigned variant in the request environment so controllers can access it
            $request->setAttribute('X_EXPERIMENT_VARIANT', $variant);
            
            // The response will need a cookie attached to persist the assignment
        } else {
            $request->setAttribute('X_EXPERIMENT_VARIANT', $variant);
        }

        return $next($request);
    }
}
