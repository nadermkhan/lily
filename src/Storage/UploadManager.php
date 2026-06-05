<?php

namespace Lily\Storage;

use Lily\Http\Request;

class UploadManager
{
    private Filesystem $filesystem;
    private string $uploadDir;

    public function __construct(Filesystem $filesystem, string $uploadDir)
    {
        $this->filesystem = $filesystem;
        $this->uploadDir = rtrim($uploadDir, '/');
        
        if (!$this->filesystem->exists($this->uploadDir)) {
            $this->filesystem->makeDirectory($this->uploadDir, 0777, true);
        }
    }

    public function processUpload(Request $request, string $inputName, array $allowedMimeTypes): ?string
    {
        $file = $request->files[$inputName] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if (!in_array($file['type'], $allowedMimeTypes)) {
            throw new \Exception('Invalid file type.');
        }

        $filename = uniqid('upload_') . '_' . basename($file['name']);
        $destination = $this->uploadDir . '/' . $filename;

        if ($this->filesystem->moveUploadedFile($file['tmp_name'], $destination)) {
            return $destination;
        }

        throw new \Exception('Failed to move uploaded file.');
    }
}
