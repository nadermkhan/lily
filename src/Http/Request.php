<?php

namespace Lily\Http;

/**
 * Represents an HTTP request.
 */
class Request
{
    /**
     * The GET parameters.
     *
     * @var array
     */
    public readonly array $get;

    /**
     * The POST parameters.
     *
     * @var array
     */
    public readonly array $post;

    /**
     * The SERVER parameters.
     *
     * @var array
     */
    public readonly array $server;

    /**
     * The FILES parameters.
     *
     * @var array
     */
    public readonly array $files;

    /**
     * The COOKIES parameters.
     *
     * @var array
     */
    public readonly array $cookies;
    
    /**
     * The extracted route parameters.
     *
     * @var array
     */
    private array $routeParams = [];

    /**
     * Custom attributes for the request.
     *
     * @var array
     */
    private array $attributes = [];

    /**
     * Create a new Request instance.
     *
     * @param array $get
     * @param array $post
     * @param array $server
     * @param array $files
     * @param array $cookies
     */
    public function __construct(
        array $get,
        array $post,
        array $server,
        array $files,
        array $cookies
    ) {
        $this->get = $get;
        $this->post = $post;
        $this->server = $server;
        $this->files = $this->parseFiles($files);
        $this->cookies = $cookies;
    }

    private function parseFiles(array $files): array
    {
        $parsed = [];
        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                // Handle array of files: name="files[]"
                $parsed[$key] = [];
                foreach (array_keys($file['name']) as $i) {
                    $parsed[$key][] = new UploadedFile(
                        $file['name'][$i],
                        $file['type'][$i],
                        $file['tmp_name'][$i],
                        $file['error'][$i],
                        $file['size'][$i]
                    );
                }
            } else {
                $parsed[$key] = new UploadedFile(
                    $file['name'],
                    $file['type'],
                    $file['tmp_name'],
                    $file['error'],
                    $file['size']
                );
            }
        }
        return $parsed;
    }

    /**
     * Capture the current incoming HTTP request.
     *
     * @return self
     */
    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    /**
     * Get the request method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Get the requested URI.
     *
     * @return string
     */
    public function getUri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $position = strpos($uri, '?');
        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }
        return $uri;
    }

    /**
     * Set the route parameters.
     *
     * @param array $params
     * @return void
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Get a specific route parameter.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getRouteParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Set a custom attribute on the request.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a custom attribute from the request.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get JSON payload data from the request body.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        // Cache the decoded json payload so we only read php://input once
        if (!isset($this->attributes['_json_payload'])) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?? [];
            $this->setAttribute('_json_payload', $data);
        }

        $payload = $this->getAttribute('_json_payload');

        if ($key === null) {
            return $payload;
        }

        return $payload[$key] ?? $default;
    }

    /**
     * Get an uploaded file by key.
     *
     * @param string $key
     * @return UploadedFile|UploadedFile[]|null
     */
    public function file(string $key): UploadedFile|array|null
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get all uploaded files.
     *
     * @return array
     */
    public function allFiles(): array
    {
        return $this->files;
    }
}
