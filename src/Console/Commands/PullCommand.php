<?php

namespace Lily\Console\Commands;

use Lily\Support\Env;

class PullCommand
{
    private string $stateFile = '.lily_sync_state.json';
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
    private bool $force = false;

    public function execute(array $args): void
    {
        if (Env::get('APP_ENV', 'development') === 'production') {
            echo "Error: Pull is not allowed in production mode.\n";
            return;
        }

        $this->force = in_array('-f', $args) || in_array('--force', $args);

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
        
        // Update local state hashes after pull
        $this->updateLocalState();
        
        ftp_close($conn);
        echo "Pull complete! $downloadedCount files downloaded.\n";
    }

    private function downloadDirectory($conn, string $remoteDir, string $localDir): int
    {
        $count = 0;
        
        if (!file_exists($localDir) && $localDir !== '.') {
            mkdir($localDir, 0777, true);
        }

        $contents = @ftp_mlsd($conn, $remoteDir);
        
        if ($contents !== false) {
            foreach ($contents as $item) {
                if ($item['name'] === '.' || $item['name'] === '..') continue;
                
                $localPath = $localDir === '.' ? $item['name'] : $localDir . '/' . $item['name'];
                $remotePath = rtrim($remoteDir, '/') . '/' . $item['name'];
                
                if ($this->isIgnored($localPath)) continue;

                if ($item['type'] === 'dir') {
                    $count += $this->downloadDirectory($conn, $remotePath, $localPath);
                } elseif ($item['type'] === 'file') {
                    
                    $remoteSize = (int)$item['size'];
                    
                    if (!$this->force && file_exists($localPath)) {
                        $localSize = filesize($localPath);
                        if ($localSize === $remoteSize) {
                            // Skip if size matches and not forced
                            continue;
                        }
                    }

                    echo "Downloading: $localPath...\n";
                    if (ftp_get($conn, $localPath, $remotePath, FTP_BINARY)) {
                        $count++;
                    } else {
                        echo "Failed to download $localPath\n";
                    }
                }
            }
        } else {
            $items = @ftp_nlist($conn, $remoteDir);
            if ($items !== false) {
                foreach ($items as $item) {
                    $basename = basename($item);
                    if ($basename === '.' || $basename === '..') continue;
                    
                    $remotePath = (strpos($item, '/') !== false) ? $item : rtrim($remoteDir, '/') . '/' . $item;
                    $localPath = $localDir === '.' ? $basename : $localDir . '/' . $basename;
                    
                    if ($this->isIgnored($localPath)) continue;

                    if (@ftp_chdir($conn, $remotePath)) {
                        @ftp_chdir($conn, $remoteDir); // go back
                        $count += $this->downloadDirectory($conn, $remotePath, $localPath);
                    } else {
                        
                        if (!$this->force && file_exists($localPath)) {
                            $remoteSize = ftp_size($conn, $remotePath);
                            $localSize = filesize($localPath);
                            if ($remoteSize !== -1 && $localSize === $remoteSize) {
                                continue;
                            }
                        }

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
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        
        foreach ($this->ignoredPaths as $ignored) {
            $ignored = str_replace('\\', '/', $ignored);
            if (str_starts_with($ignored, './')) {
                $ignored = substr($ignored, 2);
            }
            
            if (str_ends_with($ignored, '/')) {
                if (str_starts_with($path . '/', $ignored)) {
                    return true;
                }
            } else {
                if ($path === $ignored) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function updateLocalState(): void
    {
        $localFiles = $this->scanDirectory('.');
        $state = [];
        foreach ($localFiles as $file) {
            $state[$file] = sha1_file($file);
        }
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function scanDirectory(string $dir): array
    {
        $files = [];
        $items = @scandir($dir);
        
        if ($items === false) return [];
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir === '.' ? $item : $dir . '/' . $item;
            if ($this->isIgnored($path)) continue;
            
            if (is_dir($path)) {
                $files = array_merge($files, $this->scanDirectory($path));
            } else {
                $files[] = $path;
            }
        }
        
        return $files;
    }
}
