<?php

namespace Lily\Routing;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Support\DomainResolver;

/**
 * Handles HTTP route registration and request dispatching.
 */
class Router
{
    /**
     * The root node of the routing trie.
     *
     * @var TrieNode
     */
    private TrieNode $root;

    /**
     * Create a new Router instance.
     */
    public function __construct()
    {
        $this->root = new TrieNode();
    }

    /**
     * Register a new GET route.
     *
     * @param string $uri The URI pattern.
     * @param mixed $action The route action.
     * @return void
     */
    public function get(string $uri, mixed $action): void
    {
        $this->addRoute('GET', $uri, $action);
    }

    /**
     * Register a new POST route.
     *
     * @param string $uri The URI pattern.
     * @param mixed $action The route action.
     * @return void
     */
    public function post(string $uri, mixed $action): void
    {
        $this->addRoute('POST', $uri, $action);
    }

    /**
     * Scope routes to a specific host or array of hosts.
     *
     * @param string|array $hosts
     * @return RouteScope
     */
    public function on(string|array $hosts): RouteScope
    {
        return new RouteScope($this, DomainResolver::normaliseHosts($hosts));
    }

    /**
     * Scope routes to exclude a specific host or array of hosts.
     *
     * @param string|array $hosts
     * @return RouteScope
     */
    public function except(string|array $hosts): RouteScope
    {
        return new RouteScope($this, [], DomainResolver::normaliseHosts($hosts));
    }

    /**
     * Add a route to the routing trie.
     *
     * @param string $method The HTTP method.
     * @param string $uri The URI pattern.
     * @param mixed $action The route action.
     * @param array $allowHosts The list of allowed hosts.
     * @param array $blockHosts The list of blocked hosts.
     * @return void
     */
    public function addRoute(string $method, string $uri, mixed $action, array $allowHosts = [], array $blockHosts = []): void
    {
        $segments = $this->segmentise($uri);
        $node = $this->root;

        foreach ($segments as $i => $seg) {
            $isLast = ($i === count($segments) - 1);
            $node = $this->insertSegment($node, $seg, $isLast);
        }

        $node->isLeaf = true;
        $node->routePattern = $uri;
        $node->handlers[$method] = $action;
        $node->methodAllowHosts[$method] = $allowHosts;
        $node->methodBlockHosts[$method] = $blockHosts;
    }

    /**
     * Split a URI path into its constituent segments.
     *
     * @param string $path
     * @return array
     */
    private function segmentise(string $path): array
    {
        return array_values(array_map(
            fn(string $s) => rawurldecode($s),
            array_filter(explode('/', $path), fn(string $s) => $s !== '')
        ));
    }

    /**
     * Insert a segment into the routing trie.
     *
     * @param TrieNode $node The current node.
     * @param string $seg The segment to insert.
     * @param bool $isLast Whether this is the last segment in the path.
     * @return TrieNode The resulting node.
     * @throws \InvalidArgumentException
     */
    private function insertSegment(TrieNode $node, string $seg, bool $isLast): TrieNode
    {
        $parsed = $this->parseSegment($seg);

        if ($parsed['type'] === 'literal') {
            if (!isset($node->children[$parsed['value']])) {
                $node->children[$parsed['value']] = new TrieNode();
            }
            return $node->children[$parsed['value']];
        }

        if ($parsed['type'] === 'wildcard') {
            if (!$isLast) {
                throw new \InvalidArgumentException("Wildcard segment {{$parsed['name']}*} must be last.");
            }
            if ($node->wildcardChild === null) {
                $child = new TrieNode();
                $child->wildcardName = $parsed['name'];
                $node->wildcardChild = $child;
            }
            return $node->wildcardChild;
        }

        // dynamic
        if ($node->dynamicChild === null) {
            $child = new TrieNode();
            $child->paramName = $parsed['name'];
            $child->paramConstraint = $parsed['constraint'];
            $node->dynamicChild = $child;
        } else {
            // Ensure constraints don't conflict (simplified)
            $node->dynamicChild->paramName = $parsed['name'];
            if ($parsed['constraint']) {
                $node->dynamicChild->paramConstraint = $parsed['constraint'];
            }
        }
        return $node->dynamicChild;
    }

    /**
     * Parse a routing segment into its type, name, and constraint.
     *
     * @param string $seg
     * @return array
     * @throws \InvalidArgumentException
     */
    private function parseSegment(string $seg): array
    {
        if (!str_starts_with($seg, '{') || !str_ends_with($seg, '}')) {
            return ['type' => 'literal', 'value' => $seg];
        }

        $body = substr($seg, 1, -1);
        if ($body === '') {
            throw new \InvalidArgumentException('Empty parameter braces.');
        }

        $type = 'dynamic';
        $last = $body[strlen($body) - 1];
        if ($last === '*') { 
            $type = 'wildcard'; 
            $body = substr($body, 0, -1); 
        }

        $constraint = null;
        $colon = strpos($body, ':');
        if ($colon !== false) {
            $name = substr($body, 0, $colon);
            $constraint = substr($body, $colon + 1);
        } else {
            $name = $body;
        }

        return ['type' => $type, 'name' => $name, 'constraint' => $constraint];
    }

    /**
     * Dispatch an incoming request to the matched route action.
     *
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $segments = $this->segmentise($uri);
        
        $currentHost = DomainResolver::normaliseHost($request->server['HTTP_HOST'] ?? 'localhost');
        $tried = [];
        $matched = $this->traverseRecursive($this->root, $segments, 0, $method, $currentHost, [], $tried);

        if ($matched === null) {
            return new Response('404 Not Found', 404);
        }

        $request->setRouteParams($matched['params']);
        $action = $matched['node']->handlers[$method] ?? null;

        if (!$action) {
            return new Response('405 Method Not Allowed', 405);
        }

        return $this->callAction($action, $request);
    }

    /**
     * Recursively traverse the routing trie to find a match.
     *
     * @param TrieNode $node
     * @param array $segments
     * @param int $i
     * @param string $method
     * @param string $currentHost
     * @param array $params
     * @param array &$tried
     * @return array|null
     */
    private function traverseRecursive(
        TrieNode $node,
        array $segments,
        int $i,
        string $method,
        string $currentHost,
        array $params,
        array &$tried
    ): ?array {
        $count = count($segments);

        if ($i === $count) {
            if ($node->isLeaf && isset($node->handlers[$method])) {
                if ($this->checkDomain($node, $method, $currentHost)) {
                    return ['node' => $node, 'params' => $params];
                }
            }
            if ($node->wildcardChild !== null) {
                $p = $params;
                $p[$node->wildcardChild->wildcardName ?? '_'] = '';
                if ($node->wildcardChild->isLeaf && isset($node->wildcardChild->handlers[$method])) {
                    if ($this->checkDomain($node->wildcardChild, $method, $currentHost)) {
                        return ['node' => $node->wildcardChild, 'params' => $p];
                    }
                }
            }
            return null;
        }

        $seg = $segments[$i];

        if (isset($node->children[$seg])) {
            $hit = $this->traverseRecursive($node->children[$seg], $segments, $i + 1, $method, $currentHost, $params, $tried);
            if ($hit !== null) return $hit;
        }

        if ($node->dynamicChild !== null) {
            $constraint = $node->dynamicChild->paramConstraint;
            if ($constraint === null || preg_match('/^(?:' . str_replace('/', '\/', $constraint) . ')$/u', $seg) === 1) {
                $p = $params;
                $p[$node->dynamicChild->paramName ?? '_'] = $seg;
                $hit = $this->traverseRecursive($node->dynamicChild, $segments, $i + 1, $method, $currentHost, $p, $tried);
                if ($hit !== null) return $hit;
            }
        }

        if ($node->wildcardChild !== null) {
            $tail = implode('/', array_slice($segments, $i));
            $p = $params;
            $p[$node->wildcardChild->wildcardName ?? '_'] = $tail;
            if ($node->wildcardChild->isLeaf && isset($node->wildcardChild->handlers[$method])) {
                if ($this->checkDomain($node->wildcardChild, $method, $currentHost)) {
                    return ['node' => $node->wildcardChild, 'params' => $p];
                }
            }
        }

        return null;
    }

    /**
     * Check if a route matches the current request domain.
     *
     * @param TrieNode $node
     * @param string $method
     * @param string $currentHost
     * @return bool
     */
    private function checkDomain(TrieNode $node, string $method, string $currentHost): bool
    {
        $allowHosts = $node->methodAllowHosts[$method] ?? [];
        $blockHosts = $node->methodBlockHosts[$method] ?? [];

        if (!empty($blockHosts) && DomainResolver::hostMatches($blockHosts, $currentHost)) {
            return false;
        }

        if (!empty($allowHosts) && !DomainResolver::hostMatches($allowHosts, $currentHost)) {
            return false;
        }

        return true;
    }

    /**
     * Call the action for a matched route.
     *
     * @param mixed $action
     * @param Request $request
     * @return Response
     * @throws \InvalidArgumentException
     */
    private function callAction(mixed $action, Request $request): Response
    {
        if (is_callable($action)) {
            $result = call_user_func($action, $request);
        } elseif (is_array($action)) {
            [$class, $method] = $action;
            $controller = new $class();
            $result = $controller->$method($request);
        } elseif (is_string($action)) {
            $parts = explode('@', $action);
            $class = $parts[0];
            $method = $parts[1] ?? 'index';
            $controller = new $class();
            $result = $controller->$method($request);
        } else {
            throw new \InvalidArgumentException('Invalid route action.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        return new Response((string) $result);
    }
}
