<?php

namespace Lily\Storage;

use Lily\Support\Env;
use Lily\Http\Request;

class FileHosting
{
    private string $storageType;
    private string $uploadDir;
    private ?string $botToken;
    private ?string $chatId;
    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem, string $uploadDir = 'public/uploads')
    {
        $this->storageType = Env::get('STORAGE', 'local');
        $this->uploadDir = rtrim($uploadDir, '/');
        $this->botToken = Env::get('TELEGRAM_BOT_TOKEN');
        $this->chatId = Env::get('TELEGRAM_CHAT_ID');
        $this->filesystem = $filesystem;
        
        if ($this->storageType === 'local' && !$this->filesystem->exists($this->uploadDir)) {
            $this->filesystem->makeDirectory($this->uploadDir, 0777, true);
        }
    }

    public function upload(string $tmpName, string $filename): string|false
    {
        if ($this->storageType === 'telegram') {
            return $this->uploadToTelegram($tmpName, $filename);
        }

        return $this->uploadToLocal($tmpName, $filename);
    }

    private function uploadToLocal(string $tmpName, string $filename): string|false
    {
        $destination = $this->uploadDir . '/' . uniqid('file_') . '_' . basename($filename);
        if ($this->filesystem->moveUploadedFile($tmpName, $destination)) {
            return $destination;
        }
        return false;
    }

    private function uploadToTelegram(string $tmpName, string $filename): string|false
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            throw new \Exception("Telegram bot token or chat ID is missing.");
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendDocument";
        
        $mimeType = mime_content_type($tmpName);
        if ($mimeType === false) {
            $mimeType = 'application/octet-stream';
        }

        $cfile = new \CURLFile($tmpName, $mimeType, basename($filename));
        $data = [
            'chat_id' => $this->chatId,
            'document' => $cfile
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response) {
            $result = json_decode($response, true);
            if (isset($result['ok']) && $result['ok']) {
                // Return the file_id from Telegram
                if (isset($result['result']['document']['file_id'])) {
                    return $result['result']['document']['file_id'];
                }
            }
        }
        
        return false;
    }
}
