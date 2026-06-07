<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Testing\ExperimentManager;

/**
 * Middleware to split traffic for A/B experiments.
 */
class ExperimentTrafficSplitter
{
    /**
     * Create a new ExperimentTrafficSplitter instance.
     *
     * @param ExperimentManager $experiments The experiment manager.
     */
    public function __construct(private ExperimentManager $experiments) {}

    /**
     * Handle the incoming request.
     *
     * @param Request $request The incoming request.
     * @param \Closure $next The next middleware or handler in the pipeline.
     * @return mixed The response from the next handler.
     */
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
