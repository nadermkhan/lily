<?php

namespace Lily\Queue;

/**
 * Interface JobInterface
 *
 * Defines the contract for queue jobs.
 *
 * @package Lily\Queue
 */
interface JobInterface
{
    /**
     * Handle the execution of the job.
     *
     * @return void
     */
    public function handle(): void;
}
