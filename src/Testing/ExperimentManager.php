<?php

namespace Lily\Testing;

use Lily\Http\Request;
use Lily\Services\AnalyticsEngine;

class ExperimentManager
{
    public function __construct(private AnalyticsEngine $analytics) {}

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

    public function logAssignment(string $experimentId, string $variant, string $userId = 'anonymous'): void
    {
        $this->analytics->logEvent('experiment_assignment', [
            'experiment' => $experimentId,
            'variant' => $variant,
            'user' => $userId
        ]);
    }
}
