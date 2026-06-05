<?php

namespace Lily\Debug;

class Profiler
{
    private float $startTime;
    private int $startMemory;
    private array $milestones = [];

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
    }

    public function mark(string $name): void
    {
        $this->milestones[] = [
            'name' => $name,
            'time' => microtime(true) - $this->startTime,
            'memory' => memory_get_usage() - $this->startMemory
        ];
    }

    public function getResults(): array
    {
        $this->mark('end');
        return [
            'total_time' => microtime(true) - $this->startTime,
            'total_memory' => memory_get_peak_usage() - $this->startMemory,
            'milestones' => $this->milestones,
        ];
    }
}
