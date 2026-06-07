<?php

namespace Lily\Storage;

use Lily\Support\Env;
use Lily\Http\Request;

/**
 * Class FileHosting
 *
 * Handles file uploads to local storage or Telegram based on environment configuration.
 *
 * @package Lily\Storage
 */
class FileHosting
{
    /** @var string The configured storage type. */
    private string $storageType;

    /** @var string The directory for local uploads. */
    private string $uploadDir;

    /** @var string|null The Telegram bot token. */
    private ?string $botToken;

    /** @var string|null The Telegram chat ID. */
    private ?string $chatId;

    /** @var Filesystem The filesystem instance. */
    private Filesystem $filesystem;

    /**
     * FileHosting constructor.
     *
     * @param Filesystem $filesystem The filesystem instance.
     * @param string $uploadDir The directory for local uploads (default 'public/uploads').
     */
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

    /**
     * Upload a file to the configured storage backend.
     *
     * @param string $tmpName The temporary path to the uploaded file.
     * @param string $filename The original filename.
     * @return string|false The storage path or file ID on success, false on failure.
     */
    public function upload(string $tmpName, string $filename): string|false
    {
        if ($this->storageType === 'telegram') {
            return $this->uploadToTelegram($tmpName, $filename);
        }

        return $this->uploadToLocal($tmpName, $filename);
    }

    /**
     * Upload a file to the local filesystem.
     *
     * @param string $tmpName The temporary path to the uploaded file.
     * @param string $filename The original filename.
     * @return string|false The destination path on success, false on failure.
     */
    private function uploadToLocal(string $tmpName, string $filename): string|false
    {
        $destination = $this->uploadDir . '/' . uniqid('file_') . '_' . basename($filename);
        if ($this->filesystem->moveUploadedFile($tmpName, $destination)) {
            return $destination;
        }
        return false;
    }

    /**
     * Upload a file to Telegram.
     *
     * @param string $tmpName The temporary path to the uploaded file.
     * @param string $filename The original filename.
     * @return string|false The Telegram file ID on success, false on failure.
     * @throws \Exception If Telegram configuration is missing.
     */
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
