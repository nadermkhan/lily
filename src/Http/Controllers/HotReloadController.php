<?php

namespace Lily\Http\Controllers;

class HotReloadController
{
    public function handle(): void
    {
        // Prevent buffering
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        $dirsToWatch = [
            __DIR__ . '/../../../../src',
            __DIR__ . '/../../../../app',
            __DIR__ . '/../../../../public',
            __DIR__ . '/../../../../database'
        ];

        $lastMod = $this->getLatestModTime($dirsToWatch);

        // Keep connection alive and watch for changes
        while (true) {
            $currentMod = $this->getLatestModTime($dirsToWatch);
            
            if ($currentMod > $lastMod) {
                echo "data: reload\n\n";
                flush();
                break; // Client will reconnect after reload
            }

            // Send ping to keep connection alive
            echo ": ping\n\n";
            flush();
            
            if (connection_aborted()) break;
            
            sleep(1);
        }
    }

    private function getLatestModTime(array $dirs): int
    {
        $latest = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir)
            );

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $mtime = $fileinfo->getMTime();
                    if ($mtime > $latest) {
                        $latest = $mtime;
                    }
                }
            }
        }
        return $latest;
    }
}
