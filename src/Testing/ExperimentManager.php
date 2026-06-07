<?php

namespace Lily\Testing;

use Lily\Http\Request;
use Lily\Services\AnalyticsEngine;

/**
 * Class ExperimentManager
 *
 * Manages A/B testing variants and assignments.
 *
 * @package Lily\Testing
 */
class ExperimentManager
{
    /**
     * ExperimentManager constructor.
     *
     * @param AnalyticsEngine $analytics The analytics engine for logging assignments.
     */
    public function __construct(private AnalyticsEngine $analytics) {}

    /**
     * Resolve a variant for an experiment based on weights.
     *
     * @param string $experimentId The ID of the experiment.
     * @param array $variants The available variants (default ['A', 'B']).
     * @param array $weights The weights for each variant.
     * @return string The selected variant.
     */
    public function resolveVariant(string $experimentId, array $variants = ['A', 'B'], array $weights = []): string
    {
        if (empty($variants)) {
            return 'A';
        }

        if (empty($weights)) {
            $weights = array_fill(0, count($variants), 100 / count($variants));
        }

        $rand = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($variants as $index => $variant) {
            $cumulative += $weights[$index] ?? 0;
            if ($rand <= $cumulative) {
                return $variant;
            }
        }

        return $variants[0];
    }

    /**
     * Log an experiment variant assignment for a user.
     *
     * @param string $experimentId The ID of the experiment.
     * @param string $variant The assigned variant.
     * @param string $userId The ID of the user (default 'anonymous').
     * @return void
     */
    public function logAssignment(string $experimentId, string $variant, string $userId = 'anonymous'): void
    {
        $this->analytics->logEvent('experiment_assignment', [
            'experiment' => $experimentId,
            'variant' => $variant,
            'user' => $userId
        ]);
    }
}
