<?php

namespace Lily\Console\Commands;

use Lily\Queue\QueueManager;

/**
 * Class QueueWorkCommand
 *
 * Command to start the queue worker and process jobs.
 *
 * @package Lily\Console\Commands
 */
class QueueWorkCommand
{
    /**
     * Execute the queue worker command.
     *
     * @param array $args The arguments passed to the command.
     * @return void
     */
    public function execute(array $args): void
    {
        $manager = new QueueManager();
        echo "Starting Lily Queue Worker...\n";
        echo "Waiting for jobs. Press Ctrl+C to stop.\n";

        while (true) {
            $job = $manager->pop();
            if ($job) {
                $class = get_class($job);
                echo "[" . date('Y-m-d H:i:s') . "] Processing: $class\n";
                try {
                    $job->handle();
                    echo "[" . date('Y-m-d H:i:s') . "] Processed:  $class\n";
                } catch (\Throwable $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] Failed:     $class (" . $e->getMessage() . ")\n";
                }
            } else {
                sleep(1);
            }
        }
    }
}
