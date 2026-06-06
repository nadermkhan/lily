<?php

namespace Lily\Queue;

class QueueManager
{
    private string $queueDir;

    public function __construct(string $queueDir = 'storage/queue')
    {
        $this->queueDir = rtrim($queueDir, '/');
        if (!is_dir($this->queueDir)) {
            @mkdir($this->queueDir, 0777, true);
        }
    }

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
