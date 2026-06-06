<?php

namespace Lily\Console\Commands;

use Lily\Support\Env;

class PullCommand
{
    private array $ignoredPaths = [
        '.git/',
        'tests/',
        '.ncache/',
        '.env',
        '.gitignore',
        'phpunit.xml',
        'qa_tester.ps1',
        '.lily_sync_state.json'
    ];

    public function execute(array $args): void
    {
        $host = Env::get('FTP_HOST');
        $port = Env::get('FTP_PORT', 21);
        $user = Env::get('FTP_USER');
        $pass = Env::get('FTP_PASS');
        $root = Env::get('FTP_ROOT', '/');
        $secure = Env::get('FTP_SECURE', 'true') === 'true';

        if (empty($host) || empty($user) || empty($pass)) {
            echo "Error: FTP credentials (FTP_HOST, FTP_USER, FTP_PASS) are missing in .env\n";
            return;
        }

        echo "Connecting to $host...\n";
        
        $conn = null;
        if ($secure && function_exists('ftp_ssl_connect')) {
            $conn = @ftp_ssl_connect($host, $port);
            if ($conn) {
                echo "Established secure FTPS connection.\n";
            }
        }

        if (empty($conn)) {
            $conn = @ftp_connect($host, $port);
            if ($conn) {
                echo "Established standard FTP connection.\n";
            } else {
                echo "Error: Could not connect to FTP server $host:$port\n";
                return;
            }
        }

        if (!@ftp_login($conn, $user, $pass)) {
            echo "Error: Could not login to FTP server with user $user\n";
            ftp_close($conn);
            return;
        }

        ftp_pasv($conn, true);

        echo "Fetching remote files from {$root}...\n";
        
        $downloadedCount = $this->downloadDirectory($conn, $root, '.');
        
        ftp_close($conn);
        echo "Pull complete! $downloadedCount files downloaded.\n";
    }

    private function downloadDirectory($conn, string $remoteDir, string $localDir): int
    {
        $count = 0;
        
        // Ensure local directory exists
        if (!file_exists($localDir) && $localDir !== '.') {
            mkdir($localDir, 0777, true);
        }

        // Try to get list of files
        $contents = @ftp_mlsd($conn, $remoteDir);
        
        if ($contents !== false) {
            // Server supports MLSD (modern, structured list)
            foreach ($contents as $item) {
                if ($item['name'] === '.' || $item['name'] === '..') continue;
                
                $localPath = $localDir === '.' ? $item['name'] : $localDir . '/' . $item['name'];
                $remotePath = rtrim($remoteDir, '/') . '/' . $item['name'];
                
                if ($this->isIgnored($localPath)) continue;

                if ($item['type'] === 'dir') {
                    $count += $this->downloadDirectory($conn, $remotePath, $localPath);
                } elseif ($item['type'] === 'file') {
                    echo "Downloading: $localPath...\n";
                    if (ftp_get($conn, $localPath, $remotePath, FTP_BINARY)) {
                        $count++;
                    } else {
                        echo "Failed to download $localPath\n";
                    }
                }
            }
        } else {
            // Fallback to nlist (basic list)
            $items = @ftp_nlist($conn, $remoteDir);
            if ($items !== false) {
                foreach ($items as $item) {
                    $basename = basename($item);
                    if ($basename === '.' || $basename === '..') continue;
                    
                    // nlist can sometimes return full paths
                    $remotePath = (strpos($item, '/') !== false) ? $item : rtrim($remoteDir, '/') . '/' . $item;
                    $localPath = $localDir === '.' ? $basename : $localDir . '/' . $basename;
                    
                    if ($this->isIgnored($localPath)) continue;

                    // Check if directory by trying to chdir
                    if (@ftp_chdir($conn, $remotePath)) {
                        @ftp_chdir($conn, $remoteDir); // go back
                        $count += $this->downloadDirectory($conn, $remotePath, $localPath);
                    } else {
                        echo "Downloading: $localPath...\n";
                        if (ftp_get($conn, $localPath, $remotePath, FTP_BINARY)) {
                            $count++;
                        } else {
                            echo "Failed to download $localPath\n";
                        }
                    }
                }
            }
        }
        
        return $count;
    }

    private function isIgnored(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        
        foreach ($this->ignoredPaths as $ignored) {
            $ignored = str_replace('\\', '/', $ignored);
            
            if (str_ends_with($ignored, '/')) {
                if (str_starts_with($path . '/', ltrim($ignored, './'))) {
                    return true;
                }
            } else {
                if ($path === ltrim($ignored, './')) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
