<?php

namespace Lily\Http;

class Response
{
    public function __construct(
        private string $content = '',
        private int $statusCode = 200,
        private array $headers = []
    ) {}

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->content;
    }

    public static function json(array|object $data, int $statusCode = 200): self
    {
        return new self(
            json_encode($data),
            $statusCode,
            ['Content-Type' => 'application/json']
        );
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }

    public static function view(string $viewPath, array $data = [], int $statusCode = 200): self
    {
        $path = dirname(__DIR__, 3) . '/resources/views/' . str_replace('.', '/', $viewPath) . '.php';
        
        if (!file_exists($path)) {
            throw new \RuntimeException("View file not found: {$path}");
        }

        extract($data);
        
        ob_start();
        require $path;
        $content = ob_get_clean();

        return new self($content, $statusCode, ['Content-Type' => 'text/html']);
    }

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
