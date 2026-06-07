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
        $this->files = $files;
        $this->cookies = $cookies;
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
}
