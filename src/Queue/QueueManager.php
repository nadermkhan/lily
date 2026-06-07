<?php

namespace Lily\Queue;

/**
 * Class QueueManager
 *
 * Manages the pushing and popping of jobs using file-based storage.
 *
 * @package Lily\Queue
 */
class QueueManager
{
    /**
     * @var string The directory where queue jobs are stored.
     */
    private string $queueDir;

    /**
     * QueueManager constructor.
     *
     * @param string $queueDir The path to the queue directory (default 'storage/queue').
     */
    public function __construct(string $queueDir = 'storage/queue')
    {
        $this->queueDir = rtrim($queueDir, '/');
        if (!is_dir($this->queueDir)) {
            @mkdir($this->queueDir, 0777, true);
        }
    }

    /**
     * Push a job onto the queue.
     *
     * @param JobInterface $job The job to be queued.
     * @return void
     */
    public function push(JobInterface $job): void
    {
        $id = uniqid('job_', true);
        $filename = $this->queueDir . '/' . $id . '.job';
        
        $data = [
            'id' => $id,
            'pushed_at' => time(),
            'payload' => serialize($job)
        ];
        
        file_put_contents($filename, json_encode($data));
    }

    /**
     * Pop the oldest job from the queue.
     *
     * @return JobInterface|null The queued job or null if the queue is empty.
     */
    public function pop(): ?JobInterface
    {
        $files = glob($this->queueDir . '/*.job');
        if (empty($files)) {
            return null;
        }

        // Sort by modification time to get oldest first
        usort($files, function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });

        $oldest = $files[0];
        
        // Atomically rename to lock it
        $locked = $oldest . '.locked';
        if (rename($oldest, $locked)) {
            $content = file_get_contents($locked);
            $data = json_decode($content, true);
            
            if ($data && isset($data['payload'])) {
                $job = unserialize($data['payload']);
                unlink($locked); // delete after loading to prevent duplicate runs
                return $job;
            }
            
            unlink($locked); // Delete corrupt job
        }

        return null;
    }
}
