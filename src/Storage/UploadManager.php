<?php

namespace Lily\Storage;

use Lily\Http\Request;

/**
 * Class UploadManager
 *
 * Manages processing and storing of uploaded files.
 *
 * @package Lily\Storage
 */
class UploadManager
{
    /** @var Filesystem The filesystem instance. */
    private Filesystem $filesystem;

    /** @var string The directory where files will be uploaded. */
    private string $uploadDir;

    /**
     * UploadManager constructor.
     *
     * @param Filesystem $filesystem The filesystem instance.
     * @param string $uploadDir The directory for uploads.
     */
    public function __construct(Filesystem $filesystem, string $uploadDir)
    {
        $this->filesystem = $filesystem;
        $this->uploadDir = rtrim($uploadDir, '/');
        
        if (!$this->filesystem->exists($this->uploadDir)) {
            $this->filesystem->makeDirectory($this->uploadDir, 0777, true);
        }
    }

    /**
     * Process an uploaded file from the request.
     *
     * @param Request $request The HTTP request containing the file.
     * @param string $inputName The name of the file input field.
     * @param array $allowedMimeTypes An array of allowed MIME types.
     * @return string|null The destination path on success, or null on failure.
     * @throws \Exception If the file type is invalid or the move fails.
     */
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
