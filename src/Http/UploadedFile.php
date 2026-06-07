<?php

namespace Lily\Http;

class UploadedFile
{
    private string $originalName;
    private string $mimeType;
    private string $tmpName;
    private int $error;
    private int $size;

    public function __construct(string $originalName, string $mimeType, string $tmpName, int $error, int $size)
    {
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->tmpName = $tmpName;
        $this->error = $error;
        $this->size = $size;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getTmpName(): string
    {
        return $this->tmpName;
    }

    public function getExtension(): string
    {
        return pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpName);
    }

    public function getErrorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => 'There is no error, the file uploaded with success.',
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'Unknown upload error.',
        };
    }

    public function store(string $directory): string|false
    {
        return $this->storeAs($directory, $this->generateUniqueName());
    }

    public function storeAs(string $directory, string $name): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $targetPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        
        // Ensure directory exists
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (move_uploaded_file($this->tmpName, $targetPath)) {
            return $targetPath;
        }

        return false;
    }

    private function generateUniqueName(): string
    {
        $ext = $this->getExtension();
        return bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
    }
}
