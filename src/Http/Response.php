<?php

namespace Lily\Http;

/**
 * Represents an HTTP response.
 */
class Response
{
    /**
     * Create a new Response instance.
     *
     * @param string $content The response content.
     * @param int $statusCode The HTTP status code.
     * @param array $headers The response headers.
     */
    public function __construct(
        private string $content = '',
        private int $statusCode = 200,
        private array $headers = []
    ) {}

    /**
     * Set the response content.
     *
     * @param string $content
     * @return self
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Get the response content.
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $statusCode
     * @return self
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Set a response header.
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Get a specific response header.
     *
     * @param string $name
     * @param string|null $default
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }

    /**
     * Send the HTTP response to the client.
     *
     * @return void
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->content;
    }

    /**
     * Create a new JSON response.
     *
     * @param array|object $data
     * @param int $statusCode
     * @return self
     */
    public static function json(array|object $data, int $statusCode = 200): self
    {
        return new self(
            json_encode($data),
            $statusCode,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Create a new redirect response.
     *
     * @param string $url
     * @param int $statusCode
     * @return self
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }

    /**
     * Create a new file download response.
     *
     * @param string $filePath
     * @param string|null $filename
     * @return self
     * @throws \RuntimeException If the file is not found.
     */
    public static function download(string $filePath, ?string $filename = null): self
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $filename = $filename ?? basename($filePath);
        $content = file_get_contents($filePath);

        return new self($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) filesize($filePath),
        ]);
    }
}
