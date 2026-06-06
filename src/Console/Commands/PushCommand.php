<?php

namespace Lily\Console\Commands;

use Lily\Support\Env;

class PushCommand
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

        ftp_pasv($conn, true); // Use passive mode

        echo "Scanning local files...\n";
        
        $localFiles = $this->scanDirectory('.');
        $previousState = $this->loadState();
        $newState = [];
        
        $uploadedCount = 0;
        
        foreach ($localFiles as $file) {
            $hash = sha1_file($file);
            $newState[$file] = $hash;
            
            if (!isset($previousState[$file]) || $previousState[$file] !== $hash) {
                // File is new or changed
                $remotePath = rtrim($root, '/') . '/' . ltrim($file, './');
                
                echo "Uploading: $file...\n";
                
                $this->ensureRemoteDirExists($conn, dirname($remotePath));
                
                if (ftp_put($conn, $remotePath, $file, FTP_BINARY)) {
                    $uploadedCount++;
                } else {
                    echo "Failed to upload $file\n";
                    unset($newState[$file]);
                }
            }
        }

        foreach ($previousState as $file => $hash) {
            if (!isset($newState[$file]) && file_exists($file) === false) {
                $remotePath = rtrim($root, '/') . '/' . ltrim($file, './');
                echo "Deleting remote: $file...\n";
                @ftp_delete($conn, $remotePath);
            } elseif (!isset($newState[$file])) {
                 if (in_array($file, $this->scanDirectory('.'))) {
                     $newState[$file] = $hash;
                 }
            }
        }

        $this->saveState($newState);
        
        ftp_close($conn);
        echo "Push complete! $uploadedCount files uploaded.\n";
    }

    private function scanDirectory(string $dir): array
    {
        $files = [];
        $items = @scandir($dir);
        
        if ($items === false) return [];
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir === '.' ? $item : $dir . '/' . $item;
            
            if ($this->isIgnored($path)) {
                continue;
            }
            
            if (is_dir($path)) {
                $files = array_merge($files, $this->scanDirectory($path));
            } else {
                $files[] = $path;
            }
        }
        
        return $files;
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

    private function ensureRemoteDirExists($conn, string $dir): void
    {
        if ($dir === '.' || $dir === '/') {
            return;
        }

        $parts = explode('/', trim($dir, '/'));
        $currentDir = '';

        foreach ($parts as $part) {
            if (empty($part)) continue;
            
            $currentDir .= '/' . $part;
            if (!@ftp_chdir($conn, $currentDir)) {
                @ftp_mkdir($conn, $currentDir);
            }
        }
    }

    private function loadState(): array
    {
        if (file_exists($this->stateFile)) {
            $content = file_get_contents($this->stateFile);
            $state = json_decode($content, true);
            return is_array($state) ? $state : [];
        }
        return [];
    }

    private function saveState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }
}
