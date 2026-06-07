<?php

namespace Lily\Http;

class ChunkedUploader
{
    private string $tempDir;

    public function __construct(string $tempDir = null)
    {
        $this->tempDir = $tempDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lily_uploads';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Handle an incoming chunk request.
     * 
     * @param Request $request
     * @param string $fileInputName The name of the file input in the form
     * @return UploadedFile|null Returns UploadedFile when fully uploaded, null if still receiving chunks
     * @throws \Exception
     */
    public function handle(Request $request, string $fileInputName): ?UploadedFile
    {
        $file = $request->file($fileInputName);
        if (!$file) {
            throw new \Exception("No file found in request for key: $fileInputName");
        }

        $contentRange = $request->server['HTTP_CONTENT_RANGE'] ?? null;
        if (!$contentRange) {
            // Not a chunked upload, return it immediately
            return $file;
        }

        if (!preg_match('/bytes (\d+)-(\d+)\/(\d+)/', $contentRange, $matches)) {
            throw new \Exception("Invalid Content-Range header: $contentRange");
        }

        $start = (int)$matches[1];
        $end = (int)$matches[2];
        $totalSize = (int)$matches[3];

        $uniqueId = md5($file->getOriginalName() . $totalSize);
        $tempFilePath = $this->tempDir . DIRECTORY_SEPARATOR . $uniqueId . '.part';

        $this->appendChunk($tempFilePath, $file, $start);

        if ($end + 1 >= $totalSize) {
            // All chunks received
            return new UploadedFile(
                $file->getOriginalName(),
                $file->getMimeType(),
                $tempFilePath,
                UPLOAD_ERR_OK,
                $totalSize
            );
        }

        return null; // Awaiting more chunks
    }

    private function appendChunk(string $tempFilePath, UploadedFile $chunk, int $startOffset): void
    {
        // Use standard php wrappers to copy chunk to part file
        $chunkFile = $chunk->getError() === UPLOAD_ERR_OK ? fopen($chunk->getTmpName(), 'rb') : null;
        if (!$chunkFile) {
            throw new \Exception("Failed to open chunk file for reading.");
        }

        $outFile = fopen($tempFilePath, 'c+b');
        if (!$outFile) {
            fclose($chunkFile);
            throw new \Exception("Failed to open temporary file for writing.");
        }

        // Lock the file to prevent concurrent write issues
        if (flock($outFile, LOCK_EX)) {
            fseek($outFile, $startOffset);
            stream_copy_to_stream($chunkFile, $outFile);
            flock($outFile, LOCK_UN);
        } else {
            fclose($chunkFile);
            fclose($outFile);
            throw new \Exception("Could not obtain lock on temporary file.");
        }

        fclose($chunkFile);
        fclose($outFile);
    }
}
